<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AttendanceCoveragePeriod;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordRevision;
use App\Models\AttendanceReview;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Services\Attendance\AttendanceContext;
use App\Services\Attendance\AttendancePresenter;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Work schedules, daily time records, and the comparison between them.
 *
 * The one attendance workflow in EMPOWER. The DTR reaches it two ways - typed
 * in by HR from the client's sheet (here), or imported from a file the client
 * sent (AttendanceImportController) - and both land in the same time records.
 * Flagged days are reviewed in AttendanceReviewController. The biometric
 * devices belong to the client and are not connected to this system.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendancePresenter $present,
    ) {
    }

    /**
     * One employee's schedules, and their DTR against them for a period.
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

        $schedules = $employee->schedules()->with(['creator', 'voider', 'company', 'department'])->get();
        $context = AttendanceContext::load([$employee->id], $from, $to);
        $comparison = $this->attendance->compare($employee, $from, $to, $context);
        $reviews = $this->reviewsFor(collect([$employee->id]), $from, $to);
        $days = array_map(
            fn ($day) => $this->present->day($day, $reviews->get($employee->id.'|'.$day['date'])),
            $comparison['days']
        );

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
            'current_schedule' => $this->present->schedule($this->attendance->currentSchedule($employee, $schedules)),
            'schedules' => $schedules->map(fn (EmployeeSchedule $s) => $this->present->schedule($s))->values(),
            'schedule_changes' => $this->present->scheduleChanges($schedules),
            'exceptions' => EloquentCollection::make($context->exceptionsFor($employee->id)
                ->filter(fn ($e) => $e->employee_id !== null || collect($comparison['days'])
                    ->contains(fn ($d) => ($d['exception']['id'] ?? null) === $e->id)))
                ->load(['employee.applicant', 'company', 'department', 'recorder'])
                ->map(fn ($e) => $this->present->exception($e))->values(),
            'dtr_received' => EloquentCollection::make($context->coverageFor($employee->id))
                ->load('import:id,import_code')
                ->map(fn (AttendanceCoveragePeriod $c) => [
                    'from' => $c->period_from->toDateString(),
                    'to' => $c->period_to->toDateString(),
                    'import_code' => $c->import?->import_code,
                ])->values(),
            'days' => $days,
            'summary' => $comparison['summary'],
            // Counted as the review queue counts them, so the link from this
            // tab to the queue promises exactly what the queue will show.
            'review_counts' => $this->present->reviewCounts($days),
            'policy' => $comparison['policy'],
        ]);
    }

    /**
     * One day in full: the comparison, every change to its DTR, and its review.
     */
    public function day(Request $request, Employee $employee, string $date): JsonResponse
    {
        $this->authorize('viewAttendance', $employee);

        $day = $this->parseDate($date);
        $review = AttendanceReview::query()
            ->with(['events.actor', 'violation'])
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $day->toDateString())
            ->first();

        $record = AttendanceRecord::query()
            ->with(['recorder', 'import:id,import_code,original_filename'])
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $day->toDateString())
            ->first();

        $revisions = AttendanceRecordRevision::query()
            ->with(['changer', 'import:id,import_code'])
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $day->toDateString())
            ->orderByDesc('id')
            ->get();

        $presented = $this->present->day($this->attendance->compareDay($employee, $day), $review);

        return ApiResponse::success([
            'employee' => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'employment_status' => $employee->employment_status,
            ],
            'day' => $presented,
            'record' => $record ? [
                'source' => $record->source,
                'import_code' => $record->import?->import_code,
                'import_file' => $record->import?->original_filename,
                'source_row' => $record->source_row,
                'recorded_by' => $record->recorder?->full_name,
                'recorded_at' => $record->created_at?->toIso8601String(),
            ] : null,
            'revisions' => $revisions->map(fn ($r) => $this->present->revision($r))->values(),
            'review_events' => $review?->events->map(fn ($e) => $this->present->reviewEvent($e))->values() ?? [],
            'violation' => $review?->violation ? [
                'id' => $review->violation->id,
                'violation_type' => $review->violation->violation_type,
                'violation_date' => $review->violation->violation_date?->toDateString(),
                'status' => $review->violation->status,
            ] : null,
            // Nothing to move for a day that is not in the review queue.
            'transitions' => $presented['review_status'] ? AttendanceReview::TRANSITIONS[$presented['review_status']] : [],
            'note_required' => AttendanceReview::NOTE_REQUIRED,
        ]);
    }

    // ------------------------------------------------------------- schedules

    /**
     * Starts a new schedule from a date, closing the one before it.
     */
    public function storeSchedule(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('manageAttendance', $employee);

        $data = $request->validate(['effective_from' => ['required', 'date']] + $this->scheduleRules(), $this->scheduleMessages());

        $schedule = $this->attendance->setSchedule($employee, $data, $request->user());

        return ApiResponse::created($this->present->schedule($schedule), 'Schedule saved');
    }

    /**
     * Corrects a schedule entered wrongly. The old values and the reason go to
     * the audit trail; the dates stay as they were.
     */
    public function updateSchedule(Request $request, Employee $employee, EmployeeSchedule $schedule): JsonResponse
    {
        $this->authorize('manageAttendance', $employee);
        abort_unless($schedule->employee_id === $employee->id, 404);

        $data = $request->validate($this->scheduleRules() + [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], $this->scheduleMessages());

        $schedule = $this->attendance->correctSchedule($schedule, $data, $request->user());

        return ApiResponse::success($this->present->schedule($schedule), 'Schedule corrected');
    }

    /**
     * Withdraws the latest schedule as entered in error.
     */
    public function voidSchedule(Request $request, Employee $employee, EmployeeSchedule $schedule): JsonResponse
    {
        $this->authorize('manageAttendance', $employee);
        abort_unless($schedule->employee_id === $employee->id, 404);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $schedule = $this->attendance->voidSchedule($schedule, $data['reason'], $request->user());

        return ApiResponse::success($this->present->schedule($schedule), 'Schedule voided');
    }

    private function scheduleRules(): array
    {
        return [
            'work_days' => ['required', 'array', 'min:1', 'max:7'],
            'work_days.*' => ['integer', 'between:1,7'],
            'shift_start' => ['required', 'date_format:H:i'],
            'shift_end' => ['required', 'date_format:H:i', 'different:shift_start'],
            'break_start' => ['nullable', 'date_format:H:i', 'required_with:break_minutes'],
            'break_minutes' => ['nullable', 'integer', 'between:1,600'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function scheduleMessages(): array
    {
        return [
            'shift_end.different' => 'The shift must end at a different time from when it starts.',
            'break_start.required_with' => 'Give the time the break starts, or leave the break blank.',
        ];
    }

    // --------------------------------------------------------- time records

    /**
     * Saves DTR entries for one employee, several days at once.
     */
    public function storeRecords(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('manageAttendance', $employee);

        $data = $request->validate([
            // Optional only when HR is marking a period complete without
            // changing any day in it.
            'entries' => ['required_without:complete_from', 'array', 'max:'.AttendanceService::MAX_PERIOD_DAYS],
            'entries.*.work_date' => ['required', 'date', 'distinct'],
            'entries.*.time_in' => ['nullable', 'date_format:H:i'],
            'entries.*.time_out' => ['nullable', 'date_format:H:i'],
            'entries.*.remarks' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
            'complete_from' => ['nullable', 'date', 'required_with:complete_to'],
            'complete_to' => ['nullable', 'date', 'after_or_equal:complete_from', 'required_with:complete_from'],
        ]);

        $complete = isset($data['complete_from'])
            ? ['from' => $data['complete_from'], 'to' => $data['complete_to']]
            : null;

        if ($complete) {
            $this->attendance->period($complete['from'], $complete['to']);
        }

        $result = $this->attendance->saveRecords($employee, $data['entries'] ?? [], $request->user(), $data['reason'] ?? null, $complete);

        return ApiResponse::success($result, match (true) {
            $result['saved'] === 0 && $result['cleared'] === 0 => $complete ? 'DTR marked complete' : 'Nothing to save',
            $result['cleared'] === 0 => sprintf('%d day(s) saved', $result['saved']),
            default => sprintf('%d day(s) saved, %d cleared', $result['saved'], $result['cleared']),
        });
    }

    // ------------------------------------------------------------- workforce

    /**
     * Attendance across the workforce for a period, one row per employee.
     *
     * Everyone placed at some point in the period is included, so somebody
     * who left or moved mid-month still shows the days they were meant to work.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAnyAttendance', Employee::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'client_department_id' => ['nullable', 'integer', 'exists:client_departments,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'only_discrepancies' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        [$from, $to] = $this->attendance->period($filters['from'] ?? null, $filters['to'] ?? null);

        $employees = self::employeesInPeriod($from, $to, $filters)
            ->with(['applicant', 'currentCompany', 'currentDepartment'])
            ->orderBy('employee_number')
            ->get();

        $context = AttendanceContext::load($employees->pluck('id'), $from, $to);

        $rows = $employees->map(function (Employee $employee) use ($from, $to, $context) {
            $comparison = $this->attendance->compare($employee, $from, $to, $context);

            return [
                'employee_id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'employment_status' => $employee->employment_status,
                'company' => $employee->currentCompany?->company_name,
                'department' => $employee->currentDepartment?->department_name,
                'has_schedule' => $context->schedulesFor($employee->id)->isNotEmpty(),
                'summary' => $comparison['summary'],
            ];
        });

        if ($filters['only_discrepancies'] ?? false) {
            $rows = $rows->filter(fn ($r) => $r['summary']['discrepancy_days'] > 0);
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
                'late_grace_minutes' => $this->attendance->policy()['late_grace_minutes'],
            ]
        );
    }

    /**
     * Employees placed at some point in the period, narrowed by the filters
     * every attendance list shares. A client or department filter matches the
     * placements in the period, not only today's.
     */
    public static function employeesInPeriod(CarbonImmutable $from, CarbonImmutable $to, array $filters): Builder
    {
        $placedInPeriod = fn ($d) => $d
            ->whereDate('deployment_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from->toDateString()));

        return Employee::query()
            ->whereDate('hire_date', '<=', $to->toDateString())
            ->whereHas('deployments', $placedInPeriod)
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'deployments',
                fn ($d) => $placedInPeriod($d)->where('client_company_id', $v)
            ))
            ->when($filters['client_department_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'deployments',
                fn ($d) => $placedInPeriod($d)->where(fn ($dep) => $dep->where('client_department_id', $v)
                    ->orWhereHas('history', fn ($h) => $h->where('from_department_id', $v)->orWhere('to_department_id', $v)))
            ))
            ->when($filters['employee_id'] ?? null, fn ($q, $v) => $q->whereKey($v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub
                ->whereContains('employee_number', $v)
                ->orWhereContains('biometric_number', $v)
                ->orWhereHas('applicant', fn ($a) => $a->search($v))));
    }

    private function reviewsFor(Collection $employeeIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return AttendanceReview::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->get()
            ->keyBy(fn (AttendanceReview $r) => $r->employee_id.'|'.$r->work_date->toDateString());
    }

    private function parseDate(string $date): CarbonImmutable
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false, 404);

        return CarbonImmutable::parse($date)->startOfDay();
    }
}
