<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\ClientCompany;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\IncidentReport;
use App\Models\JobRequest;
use App\Models\ManpowerRequisition;
use App\Models\Resignation;
use App\Models\Termination;
use App\Models\ViolationReport;
use App\Support\ApplicantStatuses;
use App\Support\DocumentCompliance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the manual counting HR previously did across folders and spreadsheets.
 *
 * Everything here answers a question the agency currently answers by hand: how
 * many applicants are deployment-ready, which requests are running late, and
 * whether attrition is rising.
 */
class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewDashboard');

        $data = $request->validate([
            'months' => ['nullable', 'integer', 'between:1,36'],
        ]);
        $months = (int) ($data['months'] ?? 6);
        $since = now()->subMonths($months)->startOfMonth();

        // The memoised aggregates are per request. The router keeps one
        // instance of this controller, so without this a second request in
        // the same process (a test, a long-running worker) got the first
        // request's figures.
        $this->applicantAggregate = $this->requestAggregate = null;
        $this->resignationAggregate = $this->terminationAggregate = null;
        $this->expiredDocuments = $this->overdueRenewals = $this->openViolations = null;
        $this->reviewQueues = null;

        $summary = Cache::remember(
            'empower.dashboard.summary.'.($request->integer('months', 6)),
            now()->addSeconds(30),
            fn () => [
            'headline' => $this->headline(),
            'attention' => $this->attention(),
            'applicants_by_status' => $this->applicantsByStage(),
            'applicants_by_folder' => $this->folderBreakdown(),
            'deployments_by_company' => $this->deploymentsByCompany(),
            'violations_by_type' => $this->groupCount(EmployeeViolation::query(), 'violation_type'),
            'monthly_trend' => $this->monthlyTrend($since),
            'open_requests' => $this->openRequests(),
            'links' => $this->links(),
            'generated_at' => now()->toIso8601String(),
            ]
        );

        return ApiResponse::success($this->forViewer($summary, $request->user()));
    }

    /*
     * The headline figures.
     *
     * Counts over one table are asked for together, with conditional aggregates,
     * rather than as a separate COUNT per figure. On a local database the
     * difference would be irrelevant; against Supabase every statement is a
     * round trip to Singapore costing roughly 115ms, so ten counts over four
     * tables cost well over a second in latency alone. Grouped this way the same
     * numbers come back in four.
     *
     * COUNT(CASE WHEN … THEN 1 END) rather than SUM: it counts non-null values,
     * so a condition matching nothing yields 0 instead of NULL.
     */
    private function headline(): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $applicants = $this->applicantAggregate();
        $requests = $this->requestAggregate();
        $resignations = $this->resignationAggregate();
        $terminations = $this->terminationAggregate();

        return [
            'total_applicants' => (int) $applicants->total,
            'active_applicants' => (int) $applicants->active,
            'ready_for_deployment' => (int) $applicants->ready,
            'active_employees' => Employee::active()->count(),
            'client_companies' => ClientCompany::active()->count(),
            'open_requests' => (int) $requests->open_count,
            'positions_to_fill' => (int) $requests->to_fill,
            'deployments_this_month' => Deployment::whereBetween('deployment_date', [$monthStart, $monthEnd])->count(),
            'resignations_this_month' => (int) $resignations->this_month,
            'terminations_this_month' => (int) $terminations->this_month,
        ];
    }


    /*
     * One row per table, holding every figure the dashboard needs from it.
     *
     * Memoised because the headline block and the attention queue both want
     * numbers from the same four tables. Counted separately that is eight
     * statements; asked for like this it is four, and at 117ms a round trip to
     * Supabase that difference is roughly half a second on every dashboard load.
     *
     * COUNT(CASE WHEN … THEN 1 END) rather than SUM: it counts non-null values,
     * so a condition matching nothing yields 0 rather than NULL.
     */
    private ?object $applicantAggregate = null;

    private function applicantAggregate(): object
    {
        $inactive = config('empower.inactive_applicant_statuses');

        // Somebody working under an active deployment is not waiting for one,
        // whatever their status says (some statuses were reset by hand after
        // the person was deployed). The rule the applicant list uses.
        $placed = Employee::query()
            ->currentlyDeployed()
            ->whereColumn('employees.applicant_id', 'applicants.id')
            ->toBase();

        return $this->applicantAggregate ??= Applicant::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw(
                'COUNT(CASE WHEN current_status NOT IN ('.$this->placeholders($inactive).') THEN 1 END) AS active',
                $inactive
            )
            // The status lists are the ones the applicant list is opened on
            // (see links()), so each figure and its list are the same people.
            ->selectRaw(
                "COUNT(CASE WHEN folder_category = 'folder_1'
                    AND current_status IN (".$this->placeholders(ApplicantStatuses::READY_FOR_PLACEMENT).')
                    AND NOT EXISTS ('.$placed->toSql().')
                 THEN 1 END) AS ready',
                [...ApplicantStatuses::READY_FOR_PLACEMENT, ...$placed->getBindings()]
            )
            ->selectRaw(
                'COUNT(CASE WHEN current_status IN ('.$this->placeholders(ApplicantStatuses::AWAITING_DOCUMENTS).')
                 THEN 1 END) AS needing_documents',
                ApplicantStatuses::AWAITING_DOCUMENTS
            )
            // Registered online and not yet seen in person: the condition
            // of Applicant::scopeAwaitingIdentityCheck, which the list uses.
            ->selectRaw('COUNT(CASE WHEN self_registered_at IS NOT NULL AND identity_verified_at IS NULL THEN 1 END) AS awaiting_identity')
            ->first();
    }

    private ?object $requestAggregate = null;

    private function requestAggregate(): object
    {
        return $this->requestAggregate ??= JobRequest::open()
            ->selectRaw('COUNT(*) AS open_count')
            ->selectRaw('COALESCE(SUM(workers_needed - workers_fulfilled), 0) AS to_fill')
            ->selectRaw(
                'COUNT(CASE WHEN deployment_deadline IS NOT NULL AND deployment_deadline < ? THEN 1 END) AS overdue',
                [now()->toDateString()]
            )
            ->first();
    }

    private ?object $resignationAggregate = null;

    private function resignationAggregate(): object
    {
        return $this->resignationAggregate ??= Resignation::query()
            ->selectRaw(
                "COUNT(CASE WHEN status = 'completed' AND exit_date BETWEEN ? AND ? THEN 1 END) AS this_month",
                [now()->startOfMonth(), now()->endOfMonth()]
            )
            // HR's queue and the employee's are counted apart. A resignation
            // waiting on the employee's answer is open but nothing HR can
            // clear, so it is neither hidden nor counted as HR's work.
            ->selectRaw(
                'COUNT(CASE WHEN status IN ('.$this->placeholders(Resignation::AWAITING_HR).") AND clearance_status <> 'cleared'
                 THEN 1 END) AS pending_clearance",
                Resignation::AWAITING_HR
            )
            ->selectRaw("COUNT(CASE WHEN status = 'info_requested' THEN 1 END) AS awaiting_employee")
            ->first();
    }

    private ?object $terminationAggregate = null;

    private function terminationAggregate(): object
    {
        return $this->terminationAggregate ??= Termination::query()
            ->selectRaw(
                "COUNT(CASE WHEN status = 'finalized' AND termination_date BETWEEN ? AND ? THEN 1 END) AS this_month",
                [now()->startOfMonth(), now()->endOfMonth()]
            )
            ->selectRaw("COUNT(CASE WHEN status = 'for_review' THEN 1 END) AS for_review")
            ->first();
    }

    /**
     * The list filters behind each figure.
     *
     * The ready figure used to open every Folder 1 applicant, deployed
     * employees and closed records included, and the documents figure counted
     * three statuses but opened one. Published here so the screen does not
     * keep its own copy of the lists.
     */
    private function links(): array
    {
        return [
            'ready_for_deployment' => [
                'folder_category' => 'folder_1',
                'current_status' => implode(',', ApplicantStatuses::READY_FOR_PLACEMENT),
                'exclude_deployed' => 1,
            ],
            'incomplete_requirements' => [
                'current_status' => implode(',', ApplicantStatuses::AWAITING_DOCUMENTS),
            ],
            'pending_clearance' => [
                'type' => 'resignation',
                'status' => 'awaiting_clearance',
            ],
            'resignations_awaiting_employee' => [
                'type' => 'resignation',
                'status' => 'info_requested',
            ],
            'terminations_for_review' => [
                'type' => 'termination',
                'status' => 'for_review',
            ],
            'overdue_requests' => ['overdue' => 1],
            // These three count documents or violations; each list shows the
            // people holding them, which attention() also counts.
            'expired_documents' => ['expired_documents' => 1],
            'employee_renewals_overdue' => ['renewal_overdue' => 1],
            'open_violations' => ['open_violations' => 1],
            // The review inboxes, each opened on its waiting view.
            'documents_awaiting_review' => ['filter' => 'needs_review'],
            'requisitions_awaiting_review' => ['status' => 'pending'],
            'violation_reports_awaiting_review' => ['status' => 'pending'],
            'incident_reports_open' => ['status' => 'open'],
            'identity_checks' => ['awaiting_identity_check' => 1],
        ];
    }

    /** Comma-separated placeholders for an IN clause inside a raw expression. */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * Lapsed documents of applicants still waiting for a posting, and how many
     * applicants hold them - one statement for both. The rule is the one the
     * applicant list's `expired_documents` filter runs (ApplicantRequirement::
     * scopeLapsed, Applicant::scopeWithExpiredDocuments), so the people
     * figure is exactly the list's length.
     */
    private ?object $expiredDocuments = null;

    private function expiredDocuments(): object
    {
        return $this->expiredDocuments ??= ApplicantRequirement::query()
            ->lapsed()
            ->whereHas('applicant', fn ($applicant) => $applicant->whereNotIn('current_status', [
                ...DocumentCompliance::EMPLOYED_STATUSES,
                ...DocumentCompliance::CLOSED_STATUSES,
            ]))
            ->selectRaw('COUNT(*) AS documents, COUNT(DISTINCT applicant_id) AS applicants')
            ->toBase()
            ->first();
    }

    /**
     * Lapsed renew-while-employed documents of employed workers, and the
     * employees holding them, as the employee list's `renewal_overdue`
     * filter finds them (Employee::scopeRenewalOverdue). An applicant has at
     * most one employee record, so the join cannot count a document twice.
     */
    private ?object $overdueRenewals = null;

    private function overdueRenewals(): object
    {
        return $this->overdueRenewals ??= ApplicantRequirement::query()
            ->renewalOverdue()
            ->whereHas('applicant', fn ($applicant) => $applicant->whereIn('current_status', DocumentCompliance::EMPLOYED_STATUSES))
            ->leftJoin('employees', fn ($join) => $join
                ->on('employees.applicant_id', '=', 'applicant_requirements.applicant_id')
                ->whereNull('employees.deleted_at'))
            ->selectRaw('COUNT(*) AS documents, COUNT(DISTINCT employees.id) AS employees')
            ->toBase()
            ->first();
    }

    private ?object $openViolations = null;

    private function openViolations(): object
    {
        return $this->openViolations ??= EmployeeViolation::query()
            ->open()
            // Whose violations, counted the way the employee list's
            // `open_violations` filter finds them: a removed employee's
            // record is not in that list, so it is not counted as a person.
            ->leftJoin('employees', fn ($join) => $join
                ->on('employees.id', '=', 'employee_violations.employee_id')
                ->whereNull('employees.deleted_at'))
            ->selectRaw('COUNT(*) AS violations, COUNT(DISTINCT employees.id) AS employees')
            ->toBase()
            ->first();
    }

    /**
     * HR's review inboxes, in one statement of scalar subqueries rather than
     * one per table: four more round trips to Supabase would have added about
     * half a second to every uncached dashboard load (measured 2 November
     * 2026: 21 statements before these figures, 22 with them).
     *
     * - Files sent in and waiting to be verified or rejected, the applicants
     *   they belong to (the length of the document library's "Awaiting
     *   review" list), and how many nobody has opened yet.
     * - What coordinators have sent and HR has not yet decided: new (not yet
     *   taken up) and in all, so the row can say how many are under review.
     *   The statuses are each model's own PENDING_REVIEW / OPEN lists, which
     *   the lists' "pending" and "open" views use.
     */
    private ?object $reviewQueues = null;

    private function reviewQueues(): object
    {
        $documents = fn () => ApplicantRequirement::query()->awaitingReview()->whereHas('applicant');
        $count = fn ($query, string $expression = 'COUNT(*)') => $query->selectRaw($expression);

        return $this->reviewQueues ??= DB::query()
            ->selectSub($count($documents()), 'documents')
            ->selectSub($count($documents(), 'COUNT(DISTINCT applicant_id)'), 'document_applicants')
            ->selectSub($count($documents()->where('status', 'submitted')->whereNull('first_viewed_at')), 'documents_unopened')
            ->selectSub($count(ManpowerRequisition::where('status', 'submitted')), 'requisitions_new')
            ->selectSub($count(ManpowerRequisition::whereIn('status', ManpowerRequisition::PENDING_REVIEW)), 'requisitions_waiting')
            ->selectSub($count(ViolationReport::where('status', 'submitted')), 'violation_reports_new')
            ->selectSub($count(ViolationReport::whereIn('status', ViolationReport::PENDING_REVIEW)), 'violation_reports_waiting')
            ->selectSub($count(IncidentReport::where('status', 'submitted')), 'incident_reports_new')
            ->selectSub($count(IncidentReport::whereIn('status', IncidentReport::OPEN)), 'incident_reports_waiting')
            ->first();
    }

    /**
     * The queue of things that need a person to act, which is what HR actually
     * opens the dashboard to find out.
     */
    private function attention(): array
    {
        return [
            // Drawn from the same per-table aggregates as the headline figures
            // rather than counted again. Each extra statement is another 117ms
            // round trip to Supabase, and these four tables were being visited
            // twice apiece.
            'incomplete_requirements' => (int) $this->applicantAggregate()->needing_documents,

            'overdue_requests' => (int) $this->requestAggregate()->overdue,

            // Documents that have lapsed while the applicant waited for a
            // posting. These silently make someone undeployable. Counted
            // whether or not the daily check has marked them yet - it used to
            // count only the unmarked ones, so the figure fell to zero every
            // night while the documents stayed lapsed. A deployed worker's
            // clearance is not counted (CDE: it only matters upon employment).
            'expired_documents' => (int) $this->expiredDocuments()->documents,
            // Who holds them: the length of the list the figure opens.
            'applicants_with_expired_documents' => (int) $this->expiredDocuments()->applicants,

            // Deployed workers whose annual medical or health card has lapsed:
            // renewed while employed, so it needs following up. Informational
            // only - nothing about their employment changes.
            'employee_renewals_overdue' => (int) $this->overdueRenewals()->documents,
            'employees_with_overdue_renewals' => (int) $this->overdueRenewals()->employees,

            'open_violations' => (int) $this->openViolations()->violations,
            'employees_with_open_violations' => (int) $this->openViolations()->employees,

            'pending_clearance' => (int) $this->resignationAggregate()->pending_clearance,

            'resignations_awaiting_employee' => (int) $this->resignationAggregate()->awaiting_employee,

            'terminations_for_review' => (int) $this->terminationAggregate()->for_review,

            // HR's review inboxes. The sidebar badges for these are unread
            // notifications and clear once the page is opened; these count
            // the records still waiting for a decision.
            'documents_awaiting_review' => (int) $this->reviewQueues()->documents,
            'applicants_with_documents_awaiting_review' => (int) $this->reviewQueues()->document_applicants,
            'documents_unopened' => (int) $this->reviewQueues()->documents_unopened,

            'requisitions_awaiting_review' => (int) $this->reviewQueues()->requisitions_waiting,
            'requisitions_new' => (int) $this->reviewQueues()->requisitions_new,

            'violation_reports_awaiting_review' => (int) $this->reviewQueues()->violation_reports_waiting,
            'violation_reports_new' => (int) $this->reviewQueues()->violation_reports_new,

            'incident_reports_open' => (int) $this->reviewQueues()->incident_reports_waiting,
            'incident_reports_new' => (int) $this->reviewQueues()->incident_reports_new,

            'identity_checks' => (int) $this->applicantAggregate()->awaiting_identity,
        ];
    }

    /**
     * What a person needs to be shown each figure: the permissions that open
     * its list and, for the review queues, act on it. The summary is cached
     * for everybody, so this is applied per request afterwards. HR and the
     * administrator hold all of these; a custom role sees only its own work.
     */
    private const ATTENTION_PERMISSIONS = [
        'incomplete_requirements' => ['applicants.view'],
        'expired_documents' => ['applicants.view'],
        'applicants_with_expired_documents' => ['applicants.view'],
        'employee_renewals_overdue' => ['employees.view'],
        'employees_with_overdue_renewals' => ['employees.view'],
        'overdue_requests' => ['job_requests.view'],
        'open_violations' => ['employees.view'],
        'employees_with_open_violations' => ['employees.view'],
        'pending_clearance' => ['separation.view'],
        'resignations_awaiting_employee' => ['separation.view'],
        'terminations_for_review' => ['separation.view'],
        'documents_awaiting_review' => ['applicants.view', 'requirements.view', 'requirements.verify'],
        'applicants_with_documents_awaiting_review' => ['applicants.view', 'requirements.view', 'requirements.verify'],
        'documents_unopened' => ['applicants.view', 'requirements.view', 'requirements.verify'],
        'requisitions_awaiting_review' => ['requisitions.view', 'requisitions.review'],
        'requisitions_new' => ['requisitions.view', 'requisitions.review'],
        'violation_reports_awaiting_review' => ['violation_reports.view', 'violation_reports.review'],
        'violation_reports_new' => ['violation_reports.view', 'violation_reports.review'],
        'incident_reports_open' => ['incident_reports.view', 'incident_reports.review'],
        'incident_reports_new' => ['incident_reports.view', 'incident_reports.review'],
        'identity_checks' => ['applicants.view', 'applicants.change_status'],
    ];

    /** The figures, and their links, this person may see. */
    private function forViewer(array $summary, $user): array
    {
        // Each permission asked about once, not once per figure naming it:
        // this runs on every request, cached summary or not.
        $granted = collect(self::ATTENTION_PERMISSIONS)->flatten()->unique()
            ->mapWithKeys(fn (string $permission) => [$permission => $user->can($permission)]);
        $allowed = fn (string $key) => collect(self::ATTENTION_PERMISSIONS[$key] ?? [])
            ->every(fn (string $permission) => $granted[$permission]);

        $summary['attention'] = array_filter($summary['attention'], $allowed, ARRAY_FILTER_USE_KEY);
        $summary['links'] = array_filter(
            $summary['links'],
            fn (string $key) => ! array_key_exists($key, self::ATTENTION_PERMISSIONS) || $allowed($key),
            ARRAY_FILTER_USE_KEY
        );

        return $summary;
    }

    /**
     * Whether the dashboard's own numbers agree with each other.
     *
     * Not exposed; it exists so the consolidated aggregates above can be checked
     * against the plain per-figure counts they replaced. Conditional aggregates
     * are easy to get subtly wrong — an operator precedence slip in a CASE gives
     * a plausible number rather than an error — and a wrong headline on a
     * dashboard is the kind of bug nobody notices for months.
     */
    public function selfCheck(): array
    {
        $inactive = config('empower.inactive_applicant_statuses');
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $headline = $this->headline();

        return [
            'total_applicants' => [$headline['total_applicants'], Applicant::count()],
            'active_applicants' => [
                $headline['active_applicants'],
                Applicant::whereNotIn('current_status', $inactive)->count(),
            ],
            // Counted with the applicant list's own filter, as the link opens it.
            'ready_for_deployment' => [
                $headline['ready_for_deployment'],
                Applicant::where('folder_category', 'folder_1')
                    ->inStatuses(ApplicantStatuses::READY_FOR_PLACEMENT)
                    ->notCurrentlyDeployed()
                    ->count(),
            ],
            'open_requests' => [$headline['open_requests'], JobRequest::open()->count()],
            'positions_to_fill' => [
                $headline['positions_to_fill'],
                (int) JobRequest::open()
                    ->selectRaw('COALESCE(SUM(workers_needed - workers_fulfilled), 0) AS total')
                    ->value('total'),
            ],
            'incomplete_requirements' => [
                $this->attention()['incomplete_requirements'],
                Applicant::query()->inStatuses(ApplicantStatuses::AWAITING_DOCUMENTS)->count(),
            ],
            'overdue_requests' => [$this->attention()['overdue_requests'], JobRequest::overdue()->count()],
            // The people figures against the list filters that show them.
            'applicants_with_expired_documents' => [
                $this->attention()['applicants_with_expired_documents'],
                Applicant::withExpiredDocuments()->count(),
            ],
            'employees_with_overdue_renewals' => [
                $this->attention()['employees_with_overdue_renewals'],
                Employee::renewalOverdue()->count(),
            ],
            'employees_with_open_violations' => [
                $this->attention()['employees_with_open_violations'],
                Employee::withOpenViolations()->count(),
            ],
            'applicants_with_documents_awaiting_review' => [
                $this->attention()['applicants_with_documents_awaiting_review'],
                Applicant::whereHas('requirements', fn ($q) => $q->awaitingReview())->count(),
            ],
            'requisitions_awaiting_review' => [
                $this->attention()['requisitions_awaiting_review'],
                ManpowerRequisition::whereIn('status', ManpowerRequisition::PENDING_REVIEW)->count(),
            ],
            'violation_reports_awaiting_review' => [
                $this->attention()['violation_reports_awaiting_review'],
                ViolationReport::whereIn('status', ViolationReport::PENDING_REVIEW)->count(),
            ],
            'incident_reports_open' => [
                $this->attention()['incident_reports_open'],
                IncidentReport::whereIn('status', IncidentReport::OPEN)->count(),
            ],
            'identity_checks' => [$this->attention()['identity_checks'], Applicant::awaitingIdentityCheck()->count()],
            'pending_clearance' => [
                $this->attention()['pending_clearance'],
                Resignation::whereIn('status', Resignation::AWAITING_HR)
                    ->where('clearance_status', '!=', 'cleared')->count(),
            ],
            'resignations_awaiting_employee' => [
                $this->attention()['resignations_awaiting_employee'],
                Resignation::where('status', 'info_requested')->count(),
            ],
            'terminations_for_review' => [
                $this->attention()['terminations_for_review'],
                Termination::where('status', 'for_review')->count(),
            ],
            'resignations_this_month' => [
                $headline['resignations_this_month'],
                Resignation::where('status', 'completed')
                    ->whereBetween('exit_date', [$monthStart, $monthEnd])->count(),
            ],
            'terminations_this_month' => [
                $headline['terminations_this_month'],
                Termination::where('status', 'finalized')
                    ->whereBetween('termination_date', [$monthStart, $monthEnd])->count(),
            ],
        ];
    }

    private function folderBreakdown(): array
    {
        $counts = Applicant::whereNotIn('current_status', config('empower.inactive_applicant_statuses'))
            ->select('folder_category', DB::raw('COUNT(*) AS total'))
            ->groupBy('folder_category')
            ->pluck('total', 'folder_category');

        return collect(['folder_1', 'folder_2', 'folder_3'])
            ->map(fn ($folder) => [
                'folder' => $folder,
                'label' => config("empower.folders.{$folder}.label"),
                'description' => config("empower.folders.{$folder}.description"),
                'count' => (int) ($counts[$folder] ?? 0),
            ])->all();
    }

    private function deploymentsByCompany(): array
    {
        return Deployment::query()
            ->join('client_companies', 'deployments.client_company_id', '=', 'client_companies.id')
            ->where('deployments.deployment_status', 'active')
            ->select('client_companies.company_name AS label', DB::raw('COUNT(*) AS count'))
            ->groupBy('client_companies.company_name')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->count])
            ->all();
    }

    /**
     * Hiring against attrition, month by month. Reading them together is what
     * shows whether the agency is actually growing a client's headcount or just
     * replacing leavers.
     */
    private function monthlyTrend(Carbon $since): array
    {
        /*
         * Grouping a date into a "YYYY-MM" bucket is spelled differently on
         * every engine this project runs on, and there is no portable form.
         *
         * All three branches are needed. PostgreSQL is the final-defense
         * platform, MySQL is what the local demo runs on, and SQLite is what
         * the test suite uses. An earlier version had only the first two and
         * sent MySQL down the SQLite path, which fails outright: MySQL has no
         * strftime, so every dashboard load would have died on the trend chart.
         */
        $period = match (DB::connection()->getDriverName()) {
            'pgsql' => fn (string $c) => "TO_CHAR({$c}, 'YYYY-MM')",
            'mysql', 'mariadb' => fn (string $c) => "DATE_FORMAT({$c}, '%Y-%m')",
            default => fn (string $c) => "strftime('%Y-%m', {$c})",
        };

        $bucket = fn (string $table, string $column, ?array $where = null) => DB::table($table)
            ->selectRaw($period($column).' AS period, COUNT(*) AS total')
            ->whereDate($column, '>=', $since)
            ->when($where, fn ($q) => $q->where($where[0], $where[1]))
            ->groupBy('period')
            ->pluck('total', 'period');

        $applications = $bucket('applicants', 'application_date');
        $deployments = $bucket('deployments', 'deployment_date');
        $resignations = $bucket('resignations', 'exit_date', ['status', 'completed']);
        $terminations = $bucket('terminations', 'termination_date', ['status', 'finalized']);

        $series = [];
        for ($cursor = $since->copy(); $cursor->lte(now()); $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $series[] = [
                'period' => $key,
                'label' => $cursor->format('M Y'),
                'applications' => (int) ($applications[$key] ?? 0),
                'deployments' => (int) ($deployments[$key] ?? 0),
                'resignations' => (int) ($resignations[$key] ?? 0),
                'terminations' => (int) ($terminations[$key] ?? 0),
            ];
        }

        return $series;
    }

    private function openRequests(): array
    {
        return JobRequest::open()
            ->with(['company', 'department'])
            ->orderBy('deployment_deadline')
            ->limit(10)
            ->get()
            ->map(fn (JobRequest $r) => [
                'id' => $r->id,
                'request_code' => $r->request_code,
                'company' => $r->company?->company_name,
                'department' => $r->department?->department_name,
                'position_title' => $r->position_title,
                'workers_needed' => $r->workers_needed,
                'workers_fulfilled' => $r->workers_fulfilled,
                'remaining' => $r->remaining_headcount,
                'deadline' => $r->deployment_deadline?->toDateString(),
                'days_remaining' => $r->deployment_deadline
                    ? (int) now()->startOfDay()->diffInDays($r->deployment_deadline, false)
                    : null,
            ])->all();
    }

    /**
     * Applicants per lifecycle stage, in the order the lifecycle runs.
     *
     * Deliberately not groupCount(), which sorts by count descending. That is
     * right for nominal things like violation types, but wrong here: these
     * stages are a sequence an applicant moves through, and the dashboard draws
     * them on a light-to-dark ramp so the funnel is visible as a shape. Sorted
     * by size instead, the ramp would darken as the counts fell — encoding the
     * bar length a second time in colour and implying an order that is not the
     * real one.
     *
     * Stages with nobody in them are dropped rather than drawn at zero, so the
     * chart stays a picture of where people actually are.
     */
    private function applicantsByStage(): array
    {
        $counts = Applicant::query()
            ->select('current_status', DB::raw('COUNT(*) AS total'))
            ->groupBy('current_status')
            ->pluck('total', 'current_status');

        // The transition map is declared in lifecycle order, so its keys are the
        // authoritative sequence — there is no second list to keep in step.
        return collect(array_keys(config('empower.applicant_transitions')))
            ->filter(fn (string $status) => ($counts[$status] ?? 0) > 0)
            ->map(fn (string $status) => [
                'key' => $status,
                'label' => ucwords(str_replace('_', ' ', $status)),
                'count' => (int) $counts[$status],
            ])
            ->values()
            ->all();
    }

    private function groupCount($query, string $column): array
    {
        return $query->select($column, DB::raw('COUNT(*) AS total'))
            ->groupBy($column)
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->{$column},
                'label' => ucwords(str_replace('_', ' ', (string) $row->{$column})),
                'count' => (int) $row->total,
            ])->all();
    }
}
