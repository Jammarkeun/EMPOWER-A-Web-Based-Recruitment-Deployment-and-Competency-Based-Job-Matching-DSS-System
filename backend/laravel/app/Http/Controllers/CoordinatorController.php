<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CoordinatorScoped;
use App\Http\Responses\ApiResponse;
use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\CoordinatorAssignment;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\IncidentReport;
use App\Models\JobRequest;
use App\Models\ManpowerRequisition;
use App\Models\ViolationReport;
use App\Services\AttendanceService;
use App\Services\AuditService;
use App\Services\ProfilePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The coordinator's workspace: one client company, read-only.
 *
 * A coordinator is the agency's person at one client. They need to see who is
 * placed there, where, and under whom; the client's open requests; and the
 * disciplinary picture for those workers - and nothing about any other client,
 * the applicant pool, or the agency's administration.
 *
 * Every query here is scoped to the company in the coordinator's active
 * assignment, resolved by EnsureCoordinator on the server. Nothing in this
 * controller reads a client_company_id from the request, so a coordinator who
 * edits the URL or the query string still sees only their own client.
 *
 * Personal details the coordinator does not need to coordinate - birth date,
 * home address, government numbers, documents - are not returned. Nor is
 * anything to do with pay, which the system does not hold.
 */
class CoordinatorController extends Controller
{
    use CoordinatorScoped;

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $company = $this->assignment($request)->company;
        $threshold = (int) config('empower.violations.termination_threshold', 4);

        $activeEmployees = Employee::query()->where('current_client_company_id', $companyId)->active();

        $flagged = (clone $activeEmployees)
            ->with('applicant')
            ->withCount(['violations as active_violations_count' => fn ($q) => $q->active()])
            ->get()
            ->filter(fn (Employee $e) => $threshold > 0 && $e->active_violations_count >= $threshold)
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'full_name' => $e->full_name,
                'employee_number' => $e->employee_number,
                'active_violations' => $e->active_violations_count,
            ])->values();

        $reports = ViolationReport::forCompany($companyId);
        $openRequests = JobRequest::query()->where('client_company_id', $companyId)->open();

        return ApiResponse::success([
            'company' => [
                'id' => $company->id,
                'company_name' => $company->company_name,
                'status' => $company->status,
                'office_address' => $company->office_address,
            ],
            'assigned_since' => $this->assignment($request)->assigned_at?->toIso8601String(),
            'summary' => [
                'active_employees' => (clone $activeEmployees)->count(),
                'current_deployments' => Deployment::query()
                    ->where('client_company_id', $companyId)
                    ->where('deployment_status', 'active')
                    ->count(),
                'reports_draft' => (clone $reports)->where('status', 'draft')
                    ->where('reported_by', $request->user()->id)->count(),
                'reports_pending' => (clone $reports)->whereIn('status', ViolationReport::PENDING_REVIEW)->count(),
                'reports_returned' => (clone $reports)->where('status', 'returned')
                    ->where('reported_by', $request->user()->id)->count(),
                'incidents_open' => IncidentReport::forCompany($companyId)->whereIn('status', IncidentReport::OPEN)->count(),
                'requisitions_pending' => ManpowerRequisition::forCompany($companyId)
                    ->whereIn('status', ManpowerRequisition::PENDING_REVIEW)->count(),
                'requisitions_returned' => ManpowerRequisition::forCompany($companyId)->where('status', 'returned')
                    ->where('requested_by', $request->user()->id)->count(),
                'open_requests' => (clone $openRequests)->count(),
                'positions_to_fill' => (int) (clone $openRequests)
                    ->selectRaw('COALESCE(SUM(workers_needed - workers_fulfilled), 0) AS total')
                    ->value('total'),
                'threshold' => $threshold,
            ],
            'recent_violations' => $this->violationsQuery($companyId)
                ->with('employee.applicant')
                ->latest('violation_date')
                ->take(5)
                ->get()
                ->map(fn (EmployeeViolation $v) => $this->presentViolation($v) + [
                    'employee' => ['id' => $v->employee_id, 'full_name' => $v->employee?->full_name],
                ]),
            'returned_reports' => ViolationReport::forCompany($companyId)
                ->with('employee.applicant')
                ->where('status', 'returned')
                ->where('reported_by', $request->user()->id)
                ->latest('reviewed_at')
                ->take(5)
                ->get()
                ->map(fn (ViolationReport $r) => [
                    'id' => $r->id,
                    'report_code' => $r->report_code,
                    'employee_name' => $r->employee?->full_name,
                    'review_note' => $r->review_note,
                ]),
            // Workers at this client who have reached the review threshold:
            // the concern most likely to need the coordinator's attention on
            // site. HR and the administrator decide what follows.
            'flagged_employees' => $flagged,
        ]);
    }

    /**
     * The assigned client, as HR and the administrator maintain it.
     */
    public function company(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $company = ClientCompany::query()
            ->with([
                'departments' => fn ($q) => $q->withCount('activeEmployees')->orderBy('department_name'),
                'positions' => fn ($q) => $q->active()->orderBy('position_title'),
            ])
            ->findOrFail($companyId);

        $coordinators = CoordinatorAssignment::query()
            ->with('coordinator')
            ->where('client_company_id', $companyId)
            ->active()
            ->get()
            ->map(fn (CoordinatorAssignment $a) => [
                'name' => $a->coordinator?->full_name,
                'email' => $a->coordinator?->email,
                'since' => $a->assigned_at?->toDateString(),
            ]);

        return ApiResponse::success([
            'id' => $company->id,
            'company_code' => $company->company_code,
            'company_name' => $company->company_name,
            'business_type' => $company->business_type,
            'office_address' => $company->office_address,
            'contact_person' => $company->contact_person,
            'contact_number' => $company->contact_number,
            'email' => $company->email,
            'status' => $company->status,
            'departments' => $company->departments->map(fn (ClientDepartment $d) => [
                'id' => $d->id,
                'department_name' => $d->department_name,
                'department_code' => $d->department_code,
                'status' => $d->status,
                'active_employees' => (int) $d->active_employees_count,
            ]),
            'positions' => $company->positions->map(fn ($p) => [
                'id' => $p->id,
                'position_title' => $p->position_title,
                'description' => $p->description,
            ]),
            'coordinators' => $coordinators,
        ]);
    }

    public function employees(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'former', 'all'])],
            'client_department_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        // A department filter must name one of this client's departments.
        if (! empty($filters['client_department_id'])) {
            $department = ClientDepartment::find($filters['client_department_id']);
            $this->abortUnlessInScope($request, $department?->client_company_id, 'department');
        }

        $threshold = (int) config('empower.violations.termination_threshold', 4);

        $employees = Employee::query()
            ->with(['applicant', 'currentDepartment'])
            ->withCount(['violations as active_violations_count' => fn ($q) => $q->active()])
            ->where('current_client_company_id', $companyId)
            ->when(($filters['status'] ?? 'active') === 'active', fn ($q) => $q->active())
            ->when(($filters['status'] ?? null) === 'former', fn ($q) => $q->where('employment_status', '!=', 'active'))
            ->when($filters['client_department_id'] ?? null, fn ($q, $v) => $q->where('current_department_id', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                // Word by word, as on HR's lists: "Juan Cruz" is a first and
                // a last name, and compared whole with each column it found
                // nobody. Names and the employee number only - the contact
                // details HR can search by are not the coordinator's to probe.
                $words = array_slice(preg_split('/\s+/', trim($v), -1, PREG_SPLIT_NO_EMPTY), 0, 6);

                foreach ($words as $word) {
                    $q->where(fn ($sub) => $sub->whereContains('employee_number', $word)
                        ->orWhereHas('applicant', fn ($a) => $a->where(fn ($n) => $n
                            ->whereContains('first_name', $word)
                            ->orWhereContains('middle_name', $word)
                            ->orWhereContains('last_name', $word))));
                }
            })
            ->orderByRaw("CASE WHEN employment_status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('employee_number')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(
            $employees->through(fn (Employee $e) => $this->presentEmployee($e) + [
                'active_violations' => (int) $e->active_violations_count,
                'flagged' => $threshold > 0 && $e->active_violations_count >= $threshold,
            ]),
            'Employees retrieved'
        );
    }

    public function employee(Request $request, Employee $employee, AttendanceService $attendance): JsonResponse
    {
        $this->abortUnlessInScope($request, $employee->current_client_company_id, 'employee');
        $companyId = $this->companyId($request);

        $employee->load(['applicant', 'currentDepartment']);

        $deployments = Deployment::query()
            ->with('department')
            ->where('employee_id', $employee->id)
            ->where('client_company_id', $companyId)
            ->orderByDesc('deployment_date')
            ->get();

        $violations = $this->violationsQuery($companyId)
            ->where('employee_id', $employee->id)
            ->with('reporter')
            ->latest('violation_date')
            ->get();

        $reports = ViolationReport::forCompany($companyId)
            ->with('reporter')
            ->where('employee_id', $employee->id)
            ->latest()
            ->get();

        // This month's attendance, read-only and summary only. HR brings in
        // the DTR and reviews what it flags; the coordinator sees how it
        // compares with the schedule, never HR's review notes. A day with no
        // DTR on file is counted as awaiting the DTR, not as an absence.
        [$from, $to] = $attendance->period(null, null);
        $comparison = $attendance->compare($employee, $from, $to);
        $schedule = $attendance->currentSchedule($employee);

        $this->audit->record('view', 'coordination', Employee::class, $employee->id, null, [
            'employee_number' => $employee->employee_number,
        ], clientCompanyId: $companyId);

        return ApiResponse::success([
            'employee' => $this->presentEmployee($employee),
            'deployments' => $deployments->map(fn (Deployment $d) => $this->presentDeployment($d)),
            'violations' => $violations->map(fn (EmployeeViolation $v) => $this->presentViolation($v) + [
                'reported_by' => $v->reporter?->full_name,
            ]),
            'violation_policy' => [
                'active_count' => $violations->filter->isActive()->count(),
                'threshold' => (int) config('empower.violations.termination_threshold', 4),
                'window_months' => EmployeeViolation::windowMonths(),
            ],
            'reports' => $reports->map(fn (ViolationReport $r) => [
                'id' => $r->id,
                'report_code' => $r->report_code,
                'status' => $r->status,
                'status_label' => ViolationReport::LABELS[$r->status] ?? $r->status,
                'violation_type' => $r->violation_type,
                'incident_date' => $r->incident_date?->toDateString(),
                'reported_by' => $r->reporter?->full_name,
            ]),
            'attendance' => [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'schedule' => $schedule?->describe(),
                'summary' => $comparison['summary'],
            ],
        ]);
    }

    public function deployments(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'ended', 'all'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $deployments = Deployment::query()
            ->with(['employee.applicant', 'department'])
            ->where('client_company_id', $this->companyId($request))
            ->when(($filters['status'] ?? 'active') === 'active', fn ($q) => $q->where('deployment_status', 'active'))
            ->when(($filters['status'] ?? null) === 'ended', fn ($q) => $q->where('deployment_status', '!=', 'active'))
            ->orderByDesc('deployment_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(
            $deployments->through(fn (Deployment $d) => $this->presentDeployment($d) + [
                'employee' => [
                    'id' => $d->employee_id,
                    'full_name' => $d->employee?->full_name,
                    'employee_number' => $d->employee?->employee_number,
                    'biometric_number' => $d->employee?->biometric_number,
                ],
            ]),
            'Deployments retrieved'
        );
    }

    /**
     * The client's manpower requests and how far each has been filled.
     *
     * The ranking and the applicants behind it are the agency's recruitment
     * work and are not shown here.
     */
    public function manpowerRequests(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'only_open' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $requests = JobRequest::query()
            ->with('department')
            ->where('client_company_id', $this->companyId($request))
            ->when($filters['only_open'] ?? false, fn ($q) => $q->open())
            ->orderByDesc('date_requested')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(
            $requests->through(fn (JobRequest $r) => [
                'id' => $r->id,
                'request_code' => $r->request_code,
                'position_title' => $r->position_title,
                'department' => $r->department?->department_name,
                'workers_needed' => $r->workers_needed,
                'workers_fulfilled' => $r->workers_fulfilled,
                'remaining' => $r->remaining_headcount,
                'request_status' => $r->request_status,
                'date_requested' => $r->date_requested?->toDateString(),
                'deployment_deadline' => $r->deployment_deadline?->toDateString(),
            ]),
            'Manpower requests retrieved'
        );
    }

    // ------------------------------------------------------------- helpers

    /**
     * Official violations recorded against workers at this client.
     *
     * Records made since coordinators were introduced carry the client they
     * were made at. Older ones carry none, and are attributed to the client
     * the employee is placed with now.
     */
    private function violationsQuery(int $companyId)
    {
        return EmployeeViolation::query()->where(fn ($q) => $q
            ->where('client_company_id', $companyId)
            ->orWhere(fn ($legacy) => $legacy
                ->whereNull('client_company_id')
                ->whereHas('employee', fn ($e) => $e->where('current_client_company_id', $companyId))));
    }

    private function presentEmployee(Employee $e): array
    {
        return [
            'id' => $e->id,
            'full_name' => $e->full_name,
            // Only ever reached for a worker at the coordinator's own client:
            // every caller checks the assignment scope before presenting.
            'photo_url' => app(ProfilePhotoService::class)->url($e->applicant?->profile_photo_path),
            'employee_number' => $e->employee_number,
            'biometric_number' => $e->biometric_number,
            'position' => $e->current_position_title,
            'department' => $e->currentDepartment?->department_name,
            'supervisor' => $e->current_supervisor_name,
            'hire_date' => $e->hire_date?->toDateString(),
            'employment_status' => $e->employment_status,
        ];
    }

    private function presentDeployment(Deployment $d): array
    {
        return [
            'id' => $d->id,
            'deployment_code' => $d->deployment_code,
            'position' => $d->position_title,
            'department' => $d->department?->department_name,
            'supervisor' => $d->supervisor_name,
            'deployment_date' => $d->deployment_date?->toDateString(),
            'end_date' => $d->end_date?->toDateString(),
            'deployment_status' => $d->deployment_status,
        ];
    }

    private function presentViolation(EmployeeViolation $v): array
    {
        return [
            'id' => $v->id,
            'violation_date' => $v->violation_date?->toDateString(),
            'violation_type' => $v->violation_type,
            'type_label' => ucwords(str_replace('_', ' ', (string) $v->violation_type)),
            'description' => $v->description,
            'penalty' => $v->penalty,
            'status' => $v->status,
            'is_active' => $v->isActive(),
        ];
    }
}
