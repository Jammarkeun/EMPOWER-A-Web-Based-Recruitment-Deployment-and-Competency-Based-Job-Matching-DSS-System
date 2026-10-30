<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AttendanceException;
use App\Models\ClientDepartment;
use App\Models\Employee;
use App\Services\Attendance\AttendancePresenter;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Approved leave, holidays and client non-working days.
 *
 * What the agency already knows about a day before the DTR is read. A
 * scheduled day covered by one is not a missing record. Exceptions are
 * withdrawn with a reason rather than deleted.
 */
class AttendanceExceptionController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendancePresenter $present,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAnyAttendance', Employee::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'include_withdrawn' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $exceptions = AttendanceException::query()
            ->with(['employee.applicant', 'company', 'department', 'recorder'])
            ->unless($filters['include_withdrawn'] ?? false, fn ($q) => $q->inForce())
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('date_to', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('date_from', '<=', $v))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('client_company_id', $v)
                ->orWhereHas('employee', fn ($e) => $e->where('current_client_company_id', $v))))
            ->orderByDesc('date_from')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(
            $exceptions->through(fn (AttendanceException $e) => $this->present->exception($e)),
            'Attendance exceptions'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $data = $request->validate([
            'scope' => ['required', Rule::in(['employee', 'client'])],
            'employee_id' => ['required_if:scope,employee', 'nullable', 'integer', 'exists:employees,id'],
            'client_company_id' => ['required_if:scope,client', 'nullable', 'integer', 'exists:client_companies,id'],
            'client_department_id' => ['nullable', 'integer', 'exists:client_departments,id'],
            'exception_type' => ['required', Rule::in(array_keys(AttendanceException::TYPES))],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'remarks' => ['nullable', 'string', 'max:500', 'required_if:exception_type,other'],
        ], [
            'remarks.required_if' => 'Describe the exception.',
        ]);

        if ($data['scope'] === 'client') {
            $data['employee_id'] = null;

            if (($data['client_department_id'] ?? null)
                && ClientDepartment::whereKey($data['client_department_id'])->value('client_company_id') !== (int) $data['client_company_id']) {
                throw ValidationException::withMessages(['client_department_id' => 'That department belongs to another client company.']);
            }
        }

        // A year is ample for leave or a closure; a longer range is far more
        // likely a mistyped date.
        if (CarbonImmutable::parse($data['date_from'])->diffInDays(CarbonImmutable::parse($data['date_to'])) > 366) {
            throw ValidationException::withMessages(['date_to' => 'An exception can cover at most a year.']);
        }

        $exception = $this->attendance->recordException($data, $request->user());

        return ApiResponse::created(
            $this->present->exception($exception->load(['employee.applicant', 'company', 'department', 'recorder'])),
            $exception->label().' recorded'
        );
    }

    public function void(Request $request, AttendanceException $exception): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $exception = $this->attendance->voidException($exception, $data['reason'], $request->user());

        return ApiResponse::success(
            $this->present->exception($exception->load(['employee.applicant', 'company', 'department', 'recorder'])),
            'Exception withdrawn'
        );
    }
}
