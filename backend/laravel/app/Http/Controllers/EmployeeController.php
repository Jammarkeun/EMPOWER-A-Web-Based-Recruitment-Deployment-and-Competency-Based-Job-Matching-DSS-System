<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmployeeResource;
use App\Http\Resources\ViolationResource;
use App\Http\Responses\ApiResponse;
use App\Models\AttendanceReview;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\Resignation;
use App\Models\Termination;
use App\Services\AttendanceReviewService;
use App\Services\AuditService;
use App\Services\DocumentStorageService;
use App\Services\DocumentUploadPolicy;
use App\Services\SeparationPresenter;
use App\Services\ViolationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DocumentStorageService $storage,
        private readonly ViolationService $violations,
        private readonly AttendanceReviewService $attendanceReviews,
        private readonly SeparationPresenter $separations,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'employment_status' => ['nullable', Rule::in(array_keys(config('empower.employee_transitions', [])))],
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'client_department_id' => ['nullable', 'integer', 'exists:client_departments,id'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $employees = Employee::query()
            ->with(['applicant', 'currentCompany', 'currentDepartment'])
            ->withCount('violations')
            ->when($filters['search'] ?? null, function ($q, $v) {
                $q->where(function ($sub) use ($v) {
                    $sub->whereContains('employee_number', $v)
                        ->orWhereHas('applicant', fn ($a) => $a->search($v));
                });
            })
            ->when($filters['employment_status'] ?? null, fn ($q, $v) => $q->where('employment_status', $v))
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('current_client_company_id', $v))
            ->when($filters['client_department_id'] ?? null, fn ($q, $v) => $q->where('current_department_id', $v))
            ->orderByDesc('hire_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated(EmployeeResource::collection($employees), 'Employees retrieved');
    }

    /**
     * The full 201 file: identity, placement history, discipline, and the
     * recruitment record that produced the hire.
     */
    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        $employee->load([
            'applicant.educations',
            'applicant.experiences',
            'applicant.requirements.requirementType',
            'currentCompany',
            'currentDepartment',
            'deployments.company',
            'deployments.department',
            ...array_map(fn (string $relation) => "deployments.{$relation}", Deployment::HISTORY_RELATIONS),
            'violations.issuer',
            'violations.reporter',
            'violations.report',
            'statusHistory.changedBy',
            'resignation.processor',
            'resignation.filer',
            'resignation.withdrawer',
            'resignation.events.actor',
            'termination.creator',
            'termination.approver',
            'termination.withdrawer',
            'termination.events.actor',
        ]);

        return ApiResponse::success([
            'employee' => new EmployeeResource($employee),
            'status_history' => $employee->statusHistory,
            'resignation' => $this->presentResignation($employee->resignation),
            'termination' => $this->presentTermination($employee->termination),
        ]);
    }

    /**
     * The latest resignation, as the screen needs it.
     *
     * Storage paths stay on the server; the screen is told only whether a
     * letter exists, and fetches a short-lived link when someone opens it.
     */
    private function presentResignation(?Resignation $resignation): ?array
    {
        if (! $resignation) {
            return null;
        }

        return [
            'id' => $resignation->id,
            'reason' => $resignation->reason,
            'filing_date' => $resignation->filing_date?->toDateString(),
            'rendering_days' => $resignation->rendering_days,
            'exit_date' => $resignation->exit_date?->toDateString(),
            'clearance_status' => $resignation->clearance_status,
            'status' => $resignation->status,
            'remarks' => $resignation->remarks,
            'processed_by' => $resignation->processor?->full_name,
            'has_letter' => filled($resignation->resignation_letter_path),
            'is_open' => $resignation->isOpen(),
            'can_be_completed' => $resignation->canBeCompleted(),

            // The employee's side: sent from the portal or filed at the
            // office, the last day they asked for, and their own note.
            'source' => $resignation->source,
            'filed_by' => $resignation->filer?->full_name,
            'requested_exit_date' => $resignation->requested_exit_date?->toDateString(),
            'employee_remarks' => $resignation->employee_remarks,
            // How much notice the request gives, against CDE's stated period.
            'notice_given_days' => $resignation->requested_exit_date && $resignation->filing_date
                ? (int) $resignation->filing_date->diffInDays($resignation->requested_exit_date)
                : null,
            'notice_days' => (int) config('empower.separation.notice_days'),
            // What the employee is told, apart from the internal remarks.
            'employee_message' => $resignation->employee_message,
            'withdrawn_by' => $resignation->withdrawer?->full_name,
            'withdrawn_at' => $resignation->withdrawn_at?->toIso8601String(),
            'withdrawal_reason' => $resignation->withdrawal_reason,
            'allowed_statuses' => Resignation::STAFF_TRANSITIONS[$resignation->status] ?? [],
            'history' => $this->separations->staffHistory($resignation->events),
        ];
    }

    private function presentTermination(?Termination $termination): ?array
    {
        if (! $termination) {
            return null;
        }

        return [
            'id' => $termination->id,
            'reason' => $termination->reason,
            'termination_date' => $termination->termination_date?->toDateString(),
            'remarks' => $termination->remarks,
            'status' => $termination->status,
            'filed_by' => $termination->creator?->full_name,
            'filed_at' => $termination->created_at?->toIso8601String(),
            'finalized_by' => $termination->approver?->full_name,
            'finalized_at' => $termination->finalized_at?->toIso8601String(),
            'withdrawn_by' => $termination->withdrawer?->full_name,
            'withdrawn_at' => $termination->withdrawn_at?->toIso8601String(),
            'withdrawal_reason' => $termination->withdrawal_reason,
            'has_documents' => filled($termination->documents_path),
            'is_open' => $termination->isOpen(),
            'employee_message' => $termination->employee_message,
            'clearance_status' => $termination->clearance_status,
            // Created, read, opened, acknowledged: four separate facts.
            'notice' => $this->separations->staffNoticeStatus($termination),
            'history' => $this->separations->staffHistory($termination->events),
        ];
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $data = $request->validate([
            'biometric_number' => ['nullable', 'string', 'max:60'],
            'current_position_title' => ['nullable', 'string', 'max:150'],
            'current_supervisor_name' => ['nullable', 'string', 'max:190'],
            'profile_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $before = $employee->only(array_keys($data));
        $employee->update(array_merge($data, ['updated_by' => $request->user()->id]));

        $this->audit->recordUpdate('employees', Employee::class, $employee->id, $before, $data);

        return ApiResponse::success(new EmployeeResource($employee->fresh()), 'Employee updated');
    }

    // ---------------------------------------------------------------- violations

    /**
     * The employee's disciplinary record, and where it stands against policy.
     *
     * Returns the whole history — expired offences included and marked as such
     * — alongside the count that actually matters. Sending only the active ones
     * would answer the policy question while quietly losing the agency its own
     * history.
     */
    public function violations(Employee $employee): JsonResponse
    {
        $this->authorize('viewViolations', $employee);

        $violations = $employee->violations()->with(['issuer', 'reporter', 'report'])->get();
        $active = $violations->filter->isActive();
        $threshold = (int) config('empower.violations.termination_threshold', 4);

        return ApiResponse::success([
            'violations' => ViolationResource::collection($violations),
            'policy' => [
                'active_count' => $active->count(),
                'expired_count' => $violations->count() - $active->count(),
                'window_months' => EmployeeViolation::windowMonths(),
                'threshold' => $threshold,

                /*
                 * The flag, and nothing more.
                 *
                 * Reaching the threshold raises the question for an
                 * administrator; it does not answer it. Nothing downstream of
                 * this ends anybody's employment, which is the point — an
                 * automatic dismissal is precisely the irreversible decision a
                 * decision-support system must leave to a person.
                 */
                'threshold_reached' => $employee->reachedViolationThreshold(),
                'remaining_before_review' => max(0, $threshold - $active->count()),
            ],
        ]);
    }

    public function storeViolation(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('recordViolation', $employee);

        $data = $request->validate([
            'violation_date' => ['required', 'date', 'before_or_equal:today'],
            'violation_type' => ['required', Rule::in(EmployeeViolation::TYPES)],
            'description' => ['required', 'string', 'max:2000'],
            'penalty' => ['nullable', 'string', 'max:190'],
            'status' => ['nullable', Rule::in(EmployeeViolation::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'evidence' => DocumentUploadPolicy::rules(required: false),
            // Set when the violation comes from a verified attendance
            // discrepancy, so the review keeps the link.
            'attendance_review_id' => ['nullable', 'integer', 'exists:attendance_reviews,id'],
        ]);

        $review = isset($data['attendance_review_id']) ? AttendanceReview::find($data['attendance_review_id']) : null;
        unset($data['attendance_review_id']);

        if ($review) {
            // Only what HR has verified can become discipline. The comparison
            // alone never reaches this far.
            $this->authorize('reviewAttendance', Employee::class);
            $this->attendanceReviews->assertCanLinkViolation($review, $employee, $data['violation_date']);
        }

        // A violation is disciplinary evidence tied to current employment.
        // Recording one against someone who has already left would sit outside
        // any process they can answer.
        if (! $employee->isActive()) {
            return ApiResponse::error(
                'Violations can only be recorded against an active employee.',
                400
            );
        }

        $evidencePath = null;
        if ($request->hasFile('evidence')) {
            $evidencePath = $this->storage->store($request->file('evidence'), 'violations', $employee->id)['file_path'];
        }

        /*
         * Recorded through the same service that validates coordinators'
         * reports, so the audit entry and the threshold check are identical
         * however a violation reaches the file. Reaching the threshold
         * notifies and flags; it never acts. An administrator decides.
         */
        [$violation, $reached] = DB::transaction(function () use ($employee, $data, $evidencePath, $review, $request) {
            $result = $this->violations->record(
                $employee,
                array_merge($data, ['evidence_path' => $evidencePath]),
                $request->user()
            );

            if ($review) {
                $this->attendanceReviews->linkViolation($review, $result[0], $request->user());
            }

            return $result;
        });

        return ApiResponse::created([
            'violation' => new ViolationResource($violation->load('issuer')),
            'active_count' => $employee->activeViolations()->reorder()->count(),
            'threshold' => (int) config('empower.violations.termination_threshold', 4),
            'threshold_reached' => $reached,
        ], $reached
            ? 'Violation recorded. This employee has reached the review threshold.'
            : 'Violation recorded');
    }

    public function updateViolation(Request $request, Employee $employee, EmployeeViolation $violation): JsonResponse
    {
        $this->authorize('updateViolation', $employee);

        abort_unless($violation->employee_id === $employee->id, 404);

        $data = $request->validate([
            'violation_type' => ['sometimes', Rule::in(EmployeeViolation::TYPES)],
            'description' => ['sometimes', 'string', 'max:2000'],
            'penalty' => ['nullable', 'string', 'max:190'],
            'status' => ['sometimes', Rule::in(EmployeeViolation::STATUSES)],
            'resolution_date' => ['nullable', 'date', 'after_or_equal:'.$violation->violation_date?->toDateString()],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);

        $before = $violation->only(array_keys($data));
        $violation->update($data);

        $this->audit->recordUpdate('violations', EmployeeViolation::class, $violation->id, $before, $data);

        return ApiResponse::success(new ViolationResource($violation->fresh()->load('issuer')), 'Violation updated');
    }

    public function violationEvidence(Employee $employee, EmployeeViolation $violation): JsonResponse
    {
        $this->authorize('viewViolations', $employee);

        abort_unless($violation->employee_id === $employee->id, 404);

        if (blank($violation->evidence_path)) {
            return ApiResponse::error('No evidence file was attached to this violation.', 404);
        }

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrl($violation->evidence_path),
            'expires_in_minutes' => config('empower.uploads.signed_url_ttl_minutes'),
        ]);
    }
}
