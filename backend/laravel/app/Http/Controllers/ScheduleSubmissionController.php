<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\ScheduleSubmission;
use App\Models\User;
use App\Services\Attendance\ScheduleSubmissionPresenter;
use App\Services\ScheduleSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HR's queue of work schedules coordinators have sent in.
 *
 * Part of the Attendance (DTR) module: a schedule approved here is the one the
 * DTR is compared with from its first day. Reading the queue needs
 * attendance.view; approving or returning needs attendance.manage, the same
 * permission that lets HR set a schedule directly. Coordinators never reach
 * these routes - their own are under /coordinator.
 */
class ScheduleSubmissionController extends Controller
{
    public function __construct(
        private readonly ScheduleSubmissionService $submissions,
        private readonly ScheduleSubmissionPresenter $present,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAnyAttendance', Employee::class);

        $filters = $request->validate([
            // "submitted" - waiting for HR - is the default view. A draft is
            // the coordinator's unfinished work and is never listed here.
            'status' => ['nullable', Rule::in(['submitted', 'returned', 'approved', 'withdrawn', 'all'])],
            'client_company_id' => ['nullable', 'integer'],
            'coordinator_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $status = $filters['status'] ?? 'submitted';
        $base = fn () => ScheduleSubmission::query()
            ->where('status', '!=', 'draft')
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['coordinator_id'] ?? null, fn ($q, $v) => $q->where('created_by', $v))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['effective_from'] ?? null, fn ($q, $v) => $q->whereDate('effective_from', '>=', $v))
            ->when($filters['effective_to'] ?? null, fn ($q, $v) => $q->whereDate('effective_from', '<=', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub
                ->whereContains('submission_code', $v)
                ->orWhereHas('employee', fn ($e) => $e->whereContains('employee_number', $v)
                    ->orWhereContains('biometric_number', $v)
                    ->orWhereHas('applicant', fn ($a) => $a->search($v)))));

        $submissions = $base()
            ->with(['employee.applicant', 'company', 'department', 'creator', 'reviewer'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            // Oldest waiting first, so nothing sits at the bottom of the queue.
            ->when($status === 'submitted', fn ($q) => $q->orderBy('submitted_at')->orderBy('id'),
                fn ($q) => $q->latest('updated_at')->orderByDesc('id'))
            ->paginate($filters['per_page'] ?? 25);

        $counts = $base()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return ApiResponse::paginated(
            $submissions->through(fn (ScheduleSubmission $s) => $this->present->row($s)),
            'Schedule submissions retrieved',
            [
                'status_counts' => $counts->map(fn ($n) => (int) $n),
                // Whoever has sent schedules in, for the coordinator filter.
                'coordinators' => User::query()
                    ->whereIn('id', ScheduleSubmission::query()->where('status', '!=', 'draft')->select('created_by'))
                    ->orderBy('first_name')
                    ->get()
                    ->map(fn (User $u) => ['id' => $u->id, 'full_name' => $u->full_name])
                    ->values(),
            ]
        );
    }

    public function show(ScheduleSubmission $submission): JsonResponse
    {
        $this->authorize('viewAnyAttendance', Employee::class);
        abort_if($submission->status === 'draft', 404);

        return ApiResponse::success($this->present->detail($submission) + [
            'can_review' => request()->user()->can('attendance.manage')
                && (int) $submission->created_by !== (int) request()->user()->id,
        ]);
    }

    public function review(Request $request, ScheduleSubmission $submission): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);
        abort_if($submission->status === 'draft', 404);

        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'return'])],
            'note' => ['nullable', 'string', 'max:1000'],
            'confirm_replaces_past' => ['nullable', 'boolean'],
        ]);

        $submission = $this->submissions->review(
            $submission,
            $request->user(),
            $data['action'],
            $data['note'] ?? null,
            (bool) ($data['confirm_replaces_past'] ?? false),
        );

        return ApiResponse::success(
            $this->present->detail($submission) + ['can_review' => false],
            $data['action'] === 'approve' ? 'Schedule approved' : 'Schedule returned to the coordinator'
        );
    }
}
