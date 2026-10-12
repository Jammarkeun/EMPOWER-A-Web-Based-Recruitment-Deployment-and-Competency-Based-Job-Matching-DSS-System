<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Services\AttendanceService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Work schedules, daily time records, and the comparison between them.
 *
 * HR encodes both from the client's own records: the schedule the worker was
 * given, and the DTR the client's timekeeping produced. The biometric devices
 * belong to the client and are not connected to this system, and the format of
 * their export has not been settled with CDE, so there is no import here yet -
 * entry is by hand, a period at a time, the way a DTR sheet is read.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * One employee's schedule history, and their DTR against it for a period.
     */
    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('viewAttendance', $employee);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        [$from, $to] = $this->attendance->period($filters['from'] ?? null, $filters['to'] ?? null);
        [$first, $last] = $this->attendance->employmentWindow($employee);

        $schedules = $employee->schedules()->with('creator')->get();
        $comparison = $this->attendance->compare($employee, $from, $to, $schedules);

        return ApiResponse::success([
            'employee' => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'biometric_number' => $employee->biometric_number,
                'employment_status' => $employee->employment_status,
                'employed_from' => $first?->toDateString(),
                'employed_to' => $last?->toDateString(),
            ],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'current_schedule' => $this->presentSchedule($this->attendance->scheduleOn($schedules, now()->toImmutable()->startOfDay())),
            'schedules' => $schedules->map(fn (EmployeeSchedule $s) => $this->presentSchedule($s))->values(),
            ...$comparison,
        ]);
    }

    /**
     * Starts a new schedule from a date, closing the one before it.
     */
    public function storeSchedule(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('manageAttendance', $employee);

        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'work_days' => ['required', 'array', 'min:1', 'max:7'],
            'work_days.*' => ['integer', 'between:1,7'],
            'shift_start' => ['required', 'date_format:H:i'],
            'shift_end' => ['required', 'date_format:H:i', 'different:shift_start'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ], [
            'shift_end.different' => 'The shift must end at a different time from when it starts.',
        ]);

        $schedule = $this->attendance->setSchedule($employee, $data, $request->user());

        return ApiResponse::created($this->presentSchedule($schedule), 'Schedule saved');
    }

    /**
     * Saves DTR entries for one employee, several days at once.
     */
    public function storeRecords(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('manageAttendance', $employee);

        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:'.AttendanceService::MAX_PERIOD_DAYS],
            'entries.*.work_date' => ['required', 'date', 'distinct'],
            'entries.*.time_in' => ['nullable', 'date_format:H:i'],
            'entries.*.time_out' => ['nullable', 'date_format:H:i'],
            'entries.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->attendance->saveRecords($employee, $data['entries'], $request->user());

        return ApiResponse::success($result, match (true) {
            $result['saved'] === 0 && $result['cleared'] === 0 => 'Nothing to save',
            $result['cleared'] === 0 => sprintf('%d day(s) saved', $result['saved']),
            default => sprintf('%d day(s) saved, %d cleared', $result['saved'], $result['cleared']),
        });
    }

    /**
     * Attendance across the workforce for a period, one row per employee.
     *
     * Everyone employed at any point in the period is included, so somebody
     * who left mid-month still shows the days they were meant to work.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAnyAttendance', Employee::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'only_discrepancies' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        [$from, $to] = $this->attendance->period($filters['from'] ?? null, $filters['to'] ?? null);

        $employees = Employee::query()
            ->with(['applicant', 'currentCompany', 'currentDepartment'])
            ->whereDate('hire_date', '<=', $to->toDateString())
            // Still employed, or left no earlier than the start of the period.
            ->where(fn ($q) => $q->where('employment_status', 'active')
                ->orWhereHas('deployments', fn ($d) => $d->whereDate('end_date', '>=', $from->toDateString())))
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('current_client_company_id', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $operator = $q->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $q->where(fn ($sub) => $sub->where('employee_number', $operator, "%{$v}%")
                    ->orWhere('biometric_number', $operator, "%{$v}%")
                    ->orWhereHas('applicant', fn ($a) => $a->search($v)));
            })
            ->orderBy('employee_number')
            ->get();

        $ids = $employees->pluck('id');

        // Two queries for the whole set rather than two per employee.
        $schedules = EmployeeSchedule::whereIn('employee_id', $ids)->orderByDesc('effective_from')->get()->groupBy('employee_id');
        $records = AttendanceRecord::whereIn('employee_id', $ids)
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->get()
            ->groupBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use ($from, $to, $schedules, $records) {
            $comparison = $this->attendance->compare(
                $employee,
                $from,
                $to,
                $schedules->get($employee->id, collect()),
                $records->get($employee->id, collect()),
            );

            return [
                'employee_id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'employment_status' => $employee->employment_status,
                'company' => $employee->currentCompany?->company_name,
                'department' => $employee->currentDepartment?->department_name,
                'has_schedule' => $schedules->has($employee->id),
                'records_in_period' => $records->get($employee->id, collect())->count(),
                'summary' => $comparison['summary'],
            ];
        });

        if ($filters['only_discrepancies'] ?? false) {
            $rows = $rows->filter(fn ($r) => $r['summary']['days_with_discrepancy'] > 0 || ! $r['has_schedule']);
        }

        // Paged after comparing, because "only those with discrepancies" can
        // only be known once each person has been compared.
        $perPage = $filters['per_page'] ?? 25;
        $page = max(1, (int) $request->query('page', 1));
        $rows = $rows->values();

        return ApiResponse::success(
            $rows->forPage($page, $perPage)->values(),
            'Attendance summary',
            200,
            [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'late_grace_minutes' => max(0, (int) config('empower.attendance.late_grace_minutes', 0)),
            ]
        );
    }

    private function presentSchedule(?EmployeeSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        return [
            'id' => $schedule->id,
            'effective_from' => $schedule->effective_from?->toDateString(),
            'effective_to' => $schedule->effective_to?->toDateString(),
            'work_days' => array_map('intval', $schedule->work_days ?? []),
            'shift_start' => $schedule->time('shift_start'),
            'shift_end' => $schedule->time('shift_end'),
            'crosses_midnight' => $schedule->crossesMidnight(),
            'description' => $schedule->describe(),
            'remarks' => $schedule->remarks,
            'set_by' => $schedule->relationLoaded('creator') ? $schedule->creator?->full_name : null,
        ];
    }
}
