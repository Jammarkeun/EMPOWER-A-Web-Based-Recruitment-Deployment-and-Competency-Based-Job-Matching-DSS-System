<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ClientCompany;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\JobRequest;
use App\Models\Resignation;
use App\Models\Termination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $months = (int) $request->integer('months', 6);
        $since = now()->subMonths($months)->startOfMonth();

        return ApiResponse::success([
            'headline' => $this->headline(),
            'attention' => $this->attention(),
            'applicants_by_status' => $this->applicantsByStage(),
            'applicants_by_folder' => $this->folderBreakdown(),
            'deployments_by_company' => $this->deploymentsByCompany(),
            'violations_by_type' => $this->groupCount(EmployeeViolation::query(), 'violation_type'),
            'monthly_trend' => $this->monthlyTrend($since),
            'open_requests' => $this->openRequests(),
            'generated_at' => now()->toIso8601String(),
        ]);
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

        return $this->applicantAggregate ??= Applicant::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw(
                'COUNT(CASE WHEN current_status NOT IN ('.$this->placeholders($inactive).') THEN 1 END) AS active',
                $inactive
            )
            ->selectRaw(
                "COUNT(CASE WHEN folder_category = 'folder_1'
                    AND current_status IN ('ready_for_deployment', 'approved', 'client_evaluation')
                 THEN 1 END) AS ready"
            )
            ->selectRaw(
                "COUNT(CASE WHEN current_status IN
                    ('initial_screening', 'incomplete_requirements', 'pending_final_requirements')
                 THEN 1 END) AS needing_documents"
            )
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
            ->selectRaw(
                "COUNT(CASE WHEN status IN ('filed', 'accepted') AND clearance_status <> 'cleared'
                 THEN 1 END) AS pending_clearance"
            )
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

    /** Comma-separated placeholders for an IN clause inside a raw expression. */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
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
            // posting. These silently make someone undeployable.
            'expired_documents' => DB::table('applicant_requirements')
                ->where('status', 'verified')
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<', now())
                ->count(),

            'open_violations' => EmployeeViolation::open()->count(),

            'pending_clearance' => (int) $this->resignationAggregate()->pending_clearance,

            'terminations_for_review' => (int) $this->terminationAggregate()->for_review,
        ];
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
            'ready_for_deployment' => [
                $headline['ready_for_deployment'],
                Applicant::where('folder_category', 'folder_1')
                    ->whereIn('current_status', ['ready_for_deployment', 'approved', 'client_evaluation'])
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
                Applicant::whereIn('current_status', [
                    'initial_screening', 'incomplete_requirements', 'pending_final_requirements',
                ])->count(),
            ],
            'overdue_requests' => [
                $this->attention()['overdue_requests'],
                JobRequest::open()->whereNotNull('deployment_deadline')
                    ->whereDate('deployment_deadline', '<', now())->count(),
            ],
            'pending_clearance' => [
                $this->attention()['pending_clearance'],
                Resignation::whereIn('status', ['filed', 'accepted'])
                    ->where('clearance_status', '!=', 'cleared')->count(),
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
        $bucket = fn (string $table, string $column, ?array $where = null) => DB::table($table)
            ->selectRaw("TO_CHAR({$column}, 'YYYY-MM') AS period, COUNT(*) AS total")
            ->whereDate($column, '>=', $since)
            ->when($where, fn ($q) => $q->where($where[0], $where[1]))
            ->groupBy('period')
            ->pluck('total', 'period');

        // SQLite has no TO_CHAR; the test suite runs there, so fall back to
        // strftime when the driver is not PostgreSQL.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $bucket = fn (string $table, string $column, ?array $where = null) => DB::table($table)
                ->selectRaw("strftime('%Y-%m', {$column}) AS period, COUNT(*) AS total")
                ->whereDate($column, '>=', $since)
                ->when($where, fn ($q) => $q->where($where[0], $where[1]))
                ->groupBy('period')
                ->pluck('total', 'period');
        }

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
