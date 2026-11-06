<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CoordinatorScoped;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\ScheduleSubmission;
use App\Services\Attendance\ScheduleSubmissionPresenter;
use App\Services\ScheduleSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Work schedules a coordinator writes for the workers at their client.
 *
 * The coordinator records the hours the client gave each worker and sends
 * them to HR; nothing here is in force until HR approves it. Every query is
 * scoped to the client in the coordinator's assignment (CoordinatorScoped);
 * no client id is read from the request.
 */
class CoordinatorScheduleController extends Controller
{
    use CoordinatorScoped;

    public function __construct(
        private readonly ScheduleSubmissionService $submissions,
        private readonly ScheduleSubmissionPresenter $present,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            // "open" is everything not yet decided, the default view.
            'status' => ['nullable', Rule::in([...ScheduleSubmission::STATUSES, 'open', 'all'])],
            'employee_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $status = $filters['status'] ?? 'open';
        $base = fn () => $this->visible($request);

        $submissions = $base()
            ->with(['employee.applicant', 'company', 'department', 'creator', 'reviewer'])
            ->when($status === 'open', fn ($q) => $q->open())
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub
                ->whereContains('submission_code', $v)
                ->orWhereHas('employee', fn ($e) => $e->whereContains('employee_number', $v)
                    ->orWhereHas('applicant', fn ($a) => $a->where(fn ($n) => $n
                        ->whereContains('first_name', $v)->orWhereContains('last_name', $v))))))
            // Returned ones first: they are waiting on the coordinator.
            ->orderByRaw("CASE WHEN status = 'returned' THEN 0 WHEN status = 'draft' THEN 1 ELSE 2 END")
            ->latest('updated_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        $counts = $base()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return ApiResponse::paginated(
            $submissions->through(fn (ScheduleSubmission $s) => $this->present->row($s)),
            'Schedules retrieved',
            ['status_counts' => $counts->map(fn ($n) => (int) $n)]
        );
    }

    public function show(Request $request, ScheduleSubmission $submission): JsonResponse
    {
        $this->authorizeSubmission($request, $submission);

        return ApiResponse::success($this->present->detail($submission));
    }

    /**
     * Starts a schedule for one worker at the coordinator's client, and sends
     * it to HR at once when `submit` is set.
     */
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $this->abortUnlessInScope($request, $employee->current_client_company_id, 'employee');

        $data = $request->validate($this->rules(), $this->messages());
        $submission = $this->submissions->create($this->assignment($request), $request->user(), $employee, $data);

        if ($request->boolean('submit')) {
            $submission = $this->submissions->submit($submission, $this->assignment($request), $request->user());
        }

        return ApiResponse::created(
            $this->present->detail($submission->fresh()),
            $submission->status === 'submitted' ? 'Schedule sent to HR for approval' : 'Draft saved'
        );
    }

    public function update(Request $request, ScheduleSubmission $submission): JsonResponse
    {
        $this->authorizeSubmission($request, $submission);

        $data = $request->validate($this->rules(), $this->messages());
        $submission = $this->submissions->update($submission, $this->assignment($request), $request->user(), $data);

        if ($request->boolean('submit')) {
            $submission = $this->submissions->submit($submission, $this->assignment($request), $request->user());
        }

        return ApiResponse::success(
            $this->present->detail($submission->fresh()),
            $submission->status === 'submitted' ? 'Schedule sent to HR for approval' : 'Schedule saved'
        );
    }

    public function submit(Request $request, ScheduleSubmission $submission): JsonResponse
    {
        $this->authorizeSubmission($request, $submission);

        $submission = $this->submissions->submit($submission, $this->assignment($request), $request->user());

        return ApiResponse::success($this->present->detail($submission->fresh()), 'Schedule sent to HR for approval');
    }

    public function withdraw(Request $request, ScheduleSubmission $submission): JsonResponse
    {
        $this->authorizeSubmission($request, $submission);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $submission = $this->submissions->withdraw($submission, $this->assignment($request), $request->user(), $data['reason'] ?? null);

        return ApiResponse::success($this->present->detail($submission->fresh()), 'Schedule withdrawn');
    }

    private function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'work_days' => ['required', 'array', 'min:1', 'max:7'],
            'work_days.*' => ['integer', 'between:1,7'],
            'shift_start' => ['required', 'date_format:H:i'],
            'shift_end' => ['required', 'date_format:H:i', 'different:shift_start'],
            'break_start' => ['nullable', 'date_format:H:i', 'required_with:break_minutes'],
            'break_minutes' => ['nullable', 'integer', 'between:1,600'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'source_note' => ['nullable', 'string', 'max:500'],
            'submit' => ['nullable', 'boolean'],
        ];
    }

    private function messages(): array
    {
        return [
            'work_days.required' => 'Choose at least one work day.',
            'effective_to.after_or_equal' => 'The end date cannot be before the start date.',
            'shift_end.different' => 'The shift must end at a different time from when it starts.',
            'break_start.required_with' => 'Give the time the break starts, or leave the break blank.',
        ];
    }

    /** The client's schedules, without other coordinators' unsent drafts. */
    private function visible(Request $request)
    {
        return ScheduleSubmission::query()
            ->where('client_company_id', $this->companyId($request))
            ->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhere('created_by', $request->user()->id));
    }

    private function authorizeSubmission(Request $request, ScheduleSubmission $submission): void
    {
        $this->abortUnlessInScope($request, $submission->client_company_id, 'schedule');

        abort_if(
            $submission->status === 'draft' && (int) $submission->created_by !== (int) $request->user()->id,
            403,
            'This draft belongs to another coordinator.'
        );
    }
}
