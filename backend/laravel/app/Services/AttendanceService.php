<?php

namespace App\Services;

use App\Models\AttendanceCoveragePeriod;
use App\Models\AttendanceException;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordRevision;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\Attendance\AttendanceComparison;
use App\Services\Attendance\AttendanceContext;
use App\Services\Attendance\PlacementTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Work schedules, the daily time record, and the comparison between them.
 *
 * The one place attendance is written. HR sets each worker's schedule; the DTR
 * arrives either typed in from the client's sheet or imported from a file the
 * client sent (DtrImportService, which writes through recordRevision() here
 * too); known leave and holidays are recorded as exceptions. The comparison is
 * worked out on demand from all of it (AttendanceComparison), so correcting a
 * time, a schedule or an exception corrects the comparison as well.
 *
 * Nothing written here is lost. A schedule change closes the old schedule; a
 * schedule entered in error is voided, not deleted; every change to a day of
 * the DTR - including clearing it - is kept as a revision with who made it and
 * why. This is attendance, not payroll: nothing here holds a rate or prices a
 * minute.
 */
class AttendanceService
{
    /** The longest period compared at once, so one request stays cheap. */
    public const MAX_PERIOD_DAYS = 62;

    public function __construct(private readonly AuditService $audit)
    {
    }

    // ------------------------------------------------------------- schedules

    public function setSchedule(Employee $employee, array $data, User $actor): EmployeeSchedule
    {
        $from = CarbonImmutable::parse($data['effective_from'])->startOfDay();

        if ($employee->hire_date && $from->lessThan(CarbonImmutable::parse($employee->hire_date)->startOfDay())) {
            throw new RuntimeException(sprintf(
                'A schedule cannot start before the hire date (%s).',
                $employee->hire_date->toDateString()
            ));
        }

        // A schedule belongs to a placement: it is the client's schedule for
        // this worker, and it does not follow them to another client.
        $placement = PlacementTimeline::forEmployees([$employee->id])->get($employee->id)->on($from);

        if (! $placement) {
            throw new RuntimeException(sprintf(
                'This employee had no placement on %s. A schedule starts on a day they were deployed.',
                $from->toDateString()
            ));
        }

        // Schedules only move forward. Inserting one before a later schedule
        // would rewrite which rules applied to days already compared.
        $later = EmployeeSchedule::where('employee_id', $employee->id)
            ->inForce()
            ->whereDate('effective_from', '>=', $from->toDateString())
            ->exists();

        if ($later) {
            throw new RuntimeException(
                'A schedule already starts on or after that date. Choose a later start date, or correct or void that schedule.'
            );
        }

        $fields = $this->scheduleFields($data);

        return DB::transaction(function () use ($employee, $data, $actor, $from, $placement, $fields) {
            // The schedule in force ends the day before the new one begins.
            EmployeeSchedule::where('employee_id', $employee->id)
                ->inForce()
                ->where(fn ($q) => $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $from->toDateString()))
                ->update(['effective_to' => $from->subDay()->toDateString()]);

            $schedule = EmployeeSchedule::create($fields + [
                'employee_id' => $employee->id,
                'deployment_id' => $placement['deployment_id'],
                'client_company_id' => $placement['client_company_id'],
                'client_department_id' => $placement['client_department_id'],
                'effective_from' => $from->toDateString(),
                'effective_to' => null,
                'remarks' => $data['remarks'] ?? null,
                'status' => 'active',
                'created_by' => $actor->id,
            ]);

            $this->audit->record('create', 'attendance', EmployeeSchedule::class, $schedule->id, null, [
                'employee_number' => $employee->employee_number,
                'schedule' => $schedule->describe(),
                'effective_from' => $from->toDateString(),
            ], clientCompanyId: $placement['client_company_id']);

            return $schedule;
        });
    }

    /**
     * Fixes a schedule that was entered wrongly, keeping its dates.
     *
     * The old values go to the audit trail with the reason, which is how the
     * schedule's change history is read back.
     */
    public function correctSchedule(EmployeeSchedule $schedule, array $data, User $actor): EmployeeSchedule
    {
        if ($schedule->isVoided()) {
            throw new RuntimeException('A voided schedule cannot be corrected.');
        }

        $fields = $this->scheduleFields($data) + ['remarks' => $data['remarks'] ?? $schedule->remarks];
        $before = $this->scheduleSnapshot($schedule);

        $schedule->fill($fields);
        $after = $this->scheduleSnapshot($schedule);
        $changed = array_keys(array_diff_assoc(array_map('json_encode', $after), array_map('json_encode', $before)));

        if ($changed === []) {
            throw new RuntimeException('Nothing would change. Edit the schedule before saving the correction.', 422);
        }

        $schedule->updated_by = $actor->id;
        $schedule->save();

        $this->audit->record(
            'update',
            'attendance',
            EmployeeSchedule::class,
            $schedule->id,
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($after, array_flip($changed)) + ['reason' => $data['reason']],
            clientCompanyId: $schedule->client_company_id,
        );

        return $schedule;
    }

    /**
     * Withdraws the latest schedule as entered in error.
     *
     * The schedule it replaced runs on again in its place, which is what it
     * would have done had the mistaken one never been entered.
     */
    public function voidSchedule(EmployeeSchedule $schedule, string $reason, User $actor): EmployeeSchedule
    {
        if ($schedule->isVoided()) {
            throw new RuntimeException('This schedule has already been voided.');
        }

        $later = EmployeeSchedule::where('employee_id', $schedule->employee_id)
            ->inForce()
            ->whereKeyNot($schedule->id)
            ->whereDate('effective_from', '>', $schedule->effective_from->toDateString())
            ->exists();

        if ($later) {
            throw new RuntimeException('Only the latest schedule can be voided. Void the later schedule first, or correct this one.');
        }

        return DB::transaction(function () use ($schedule, $reason, $actor) {
            $previous = EmployeeSchedule::where('employee_id', $schedule->employee_id)
                ->inForce()
                ->whereKeyNot($schedule->id)
                ->whereDate('effective_to', $schedule->effective_from->subDay()->toDateString())
                ->first();

            $previous?->update(['effective_to' => $schedule->effective_to?->toDateString(), 'updated_by' => $actor->id]);

            $schedule->update([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ]);

            $this->audit->record('void', 'attendance', EmployeeSchedule::class, $schedule->id, ['status' => 'active'], [
                'status' => 'voided',
                'reason' => $reason,
                'reopened_schedule_id' => $previous?->id,
            ], clientCompanyId: $schedule->client_company_id);

            return $schedule;
        });
    }

    private function scheduleFields(array $data): array
    {
        $workDays = collect($data['work_days'])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();
        $breakMinutes = isset($data['break_minutes']) && $data['break_minutes'] !== '' ? (int) $data['break_minutes'] : null;

        $start = CarbonImmutable::parse('2000-01-01 '.$data['shift_start']);
        $end = CarbonImmutable::parse('2000-01-01 '.$data['shift_end']);
        $shiftMinutes = (int) $start->diffInMinutes($end->lessThanOrEqualTo($start) ? $end->addDay() : $end);

        if ($breakMinutes !== null && $breakMinutes >= $shiftMinutes) {
            throw new RuntimeException('The break is as long as the shift itself. Check the break and the shift times.', 422);
        }

        return [
            'work_days' => $workDays,
            'shift_start' => $data['shift_start'],
            'shift_end' => $data['shift_end'],
            'break_start' => filled($data['break_start'] ?? null) ? $data['break_start'] : null,
            'break_minutes' => $breakMinutes,
        ];
    }

    private function scheduleSnapshot(EmployeeSchedule $schedule): array
    {
        return [
            'work_days' => array_map('intval', $schedule->work_days ?? []),
            'shift_start' => $schedule->time('shift_start'),
            'shift_end' => $schedule->time('shift_end'),
            'break_start' => $schedule->time('break_start'),
            'break_minutes' => $schedule->break_minutes,
            'remarks' => $schedule->remarks,
        ];
    }

    // --------------------------------------------------------- time records

    /**
     * Saves a run of DTR entries, as they are copied off a time sheet.
     *
     * An entry with nothing in it clears that day, which is what blanking a
     * row on the sheet means; the cleared times stay in the day's revisions.
     * Changing or clearing a day already on file needs a reason, because the
     * earlier times may be what a review or a violation was based on. Times
     * are HH:MM on the work date; a time-out not after the time-in belongs to
     * the next day, which is how a night shift reads.
     *
     * When $complete is given, HR is also saying the client's DTR for that
     * period has been read in full, so a scheduled day still blank in it had
     * no time record at all.
     *
     * @param  array{from: string, to: string}|null  $complete
     * @return array{saved: int, cleared: int, unchanged: int}
     */
    public function saveRecords(Employee $employee, array $entries, User $actor, ?string $reason = null, ?array $complete = null): array
    {
        [$first, $last] = $this->employmentWindow($employee);
        $today = CarbonImmutable::today();
        $schedules = $employee->schedules()->inForce()->get();
        $placements = PlacementTimeline::forEmployees([$employee->id])->get($employee->id);
        $comparison = $this->comparison();

        foreach ($entries as $entry) {
            $this->assertRecordable($entry['work_date'], $first, $last, $today);
        }

        // A range rather than whereIn: a date column holds "Y-m-d 00:00:00" on
        // SQLite, so matching exact strings finds nothing there.
        $dates = collect($entries)->map(fn ($e) => CarbonImmutable::parse($e['work_date'])->toDateString());
        $existing = $dates->isEmpty() ? collect() : AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $dates->min())
            ->whereDate('work_date', '<=', $dates->max())
            ->get()
            ->keyBy(fn (AttendanceRecord $r) => $r->work_date->toDateString());

        // Work out every change before writing any, so a missing reason
        // refuses the whole batch rather than half of it.
        $changes = [];

        foreach ($entries as $entry) {
            $date = CarbonImmutable::parse($entry['work_date'])->startOfDay();
            $record = $existing->get($date->toDateString());
            $remarks = isset($entry['remarks']) ? trim((string) $entry['remarks']) : '';

            if (blank($entry['time_in'] ?? null) && blank($entry['time_out'] ?? null) && $remarks === '') {
                if ($record) {
                    $changes[] = ['clear', $date, $record, null];
                }

                continue;
            }

            $schedule = $comparison->scheduleFor($schedules, $date, $placements->on($date));
            [$in, $out] = $this->timestamps($date, $entry['time_in'] ?? null, $entry['time_out'] ?? null, $schedule);
            $values = ['time_in' => $in, 'time_out' => $out, 'remarks' => $remarks ?: null];

            if ($record && $this->sameRecord($record, $values)) {
                $changes[] = ['unchanged', $date, $record, null];
            } else {
                $changes[] = [$record ? 'update' : 'create', $date, $record, $values];
            }
        }

        $touchesExisting = collect($changes)->filter(fn ($c) => in_array($c[0], ['update', 'clear'], true))->count();

        if ($touchesExisting > 0 && blank($reason)) {
            throw new RuntimeException(sprintf(
                'Give a reason for changing %d day(s) already on file. The earlier times are kept, but the reason has to be recorded with them.',
                $touchesExisting
            ), 422);
        }

        $counts = ['saved' => 0, 'cleared' => 0, 'unchanged' => 0];

        DB::transaction(function () use ($employee, $changes, $actor, $reason, $complete, $first, $last, $today, &$counts) {
            foreach ($changes as [$action, $date, $record, $values]) {
                if ($action === 'unchanged') {
                    $counts['unchanged']++;

                    continue;
                }

                if ($action === 'clear') {
                    $this->recordRevision($employee->id, $date, 'cleared', $record, null, $actor, $reason);
                    $record->delete();
                    $counts['cleared']++;

                    continue;
                }

                $values += ['source' => 'manual', 'attendance_import_id' => null, 'source_row' => null];

                if ($record) {
                    $before = clone $record;
                    $record->update($values + ['updated_by' => $actor->id]);
                    $this->recordRevision($employee->id, $date, 'updated', $before, $record, $actor, $reason);
                } else {
                    $record = AttendanceRecord::create($values + [
                        'employee_id' => $employee->id,
                        'work_date' => $date->toDateString(),
                        'recorded_by' => $actor->id,
                    ]);
                    $this->recordRevision($employee->id, $date, 'created', null, $record, $actor, null);
                }

                $counts['saved']++;
            }

            if ($complete) {
                $this->declareComplete($employee, $complete['from'], $complete['to'], $actor, $first, $last, $today);
            }
        });

        $this->audit->record('update', 'attendance', Employee::class, $employee->id, null, [
            'employee_number' => $employee->employee_number,
            'days_saved' => $counts['saved'],
            'days_cleared' => $counts['cleared'],
            'dates' => collect($changes)->reject(fn ($c) => $c[0] === 'unchanged')
                ->map(fn ($c) => $c[1]->toDateString())->sort()->values()->all(),
            'reason' => $reason,
            'declared_complete' => $complete,
        ], clientCompanyId: $employee->current_client_company_id);

        return $counts;
    }

    private function assertRecordable(string $workDate, ?CarbonImmutable $first, ?CarbonImmutable $last, CarbonImmutable $today): void
    {
        $date = CarbonImmutable::parse($workDate)->startOfDay();

        if ($date->greaterThan($today)) {
            throw new RuntimeException(sprintf('%s is in the future. Only days already worked can be recorded.', $date->toDateString()));
        }

        if (($first && $date->lessThan($first)) || ($last && $date->greaterThan($last))) {
            throw new RuntimeException(sprintf(
                '%s is outside this person\'s employment, which runs from %s%s.',
                $date->toDateString(),
                $first?->toDateString() ?? 'their hire date',
                $last ? ' to '.$last->toDateString() : ''
            ));
        }
    }

    /**
     * Records that the DTR for a period has been read in full.
     */
    private function declareComplete(Employee $employee, string $from, string $to, User $actor, ?CarbonImmutable $first, ?CarbonImmutable $last, CarbonImmutable $today): void
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        // Only days that were both worked and already over can be complete.
        $start = $first && $start->lessThan($first) ? $first : $start;
        $end = $end->greaterThan($today) ? $today : $end;
        $end = $last && $end->greaterThan($last) ? $last : $end;

        if ($end->lessThan($start)) {
            return;
        }

        AttendanceCoveragePeriod::create([
            'employee_id' => $employee->id,
            'period_from' => $start->toDateString(),
            'period_to' => $end->toDateString(),
            'note' => 'Hand-encoded DTR declared complete',
            'declared_by' => $actor->id,
        ]);
    }

    /**
     * Keeps a day's change: what it was, what it became, who, and why.
     */
    public function recordRevision(
        int $employeeId,
        CarbonImmutable $date,
        string $action,
        ?AttendanceRecord $before,
        ?AttendanceRecord $after,
        User $actor,
        ?string $reason,
        ?int $importId = null,
    ): AttendanceRecordRevision {
        return AttendanceRecordRevision::create([
            'employee_id' => $employeeId,
            'work_date' => $date->toDateString(),
            'attendance_record_id' => $after?->id ?? $before?->id,
            'action' => $action,
            'old_time_in' => $before?->time_in,
            'old_time_out' => $before?->time_out,
            'old_remarks' => $before?->remarks,
            'old_source' => $before?->source,
            'new_time_in' => $after?->time_in,
            'new_time_out' => $after?->time_out,
            'new_remarks' => $after?->remarks,
            'new_source' => $after?->source,
            'reason' => $reason,
            'attendance_import_id' => $importId,
            'changed_by' => $actor->id,
        ]);
    }

    public function sameRecord(AttendanceRecord $record, array $values): bool
    {
        $minute = fn ($v) => $v ? CarbonImmutable::parse($v)->format('Y-m-d H:i') : null;

        return $minute($record->time_in) === $minute($values['time_in'] ?? null)
            && $minute($record->time_out) === $minute($values['time_out'] ?? null)
            && (! array_key_exists('remarks', $values) || ($record->remarks ?: null) === ($values['remarks'] ?: null));
    }

    /**
     * Turns HH:MM on a work date into full timestamps.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function timestamps(CarbonImmutable $date, ?string $timeIn, ?string $timeOut, ?EmployeeSchedule $schedule): array
    {
        $in = filled($timeIn) ? $date->setTimeFromTimeString($timeIn) : null;
        $out = filled($timeOut) ? $date->setTimeFromTimeString($timeOut) : null;

        if ($in && $out && $out->lessThanOrEqualTo($in)) {
            $out = $out->addDay();
        }

        // A lone time-out on a night shift, earlier than the shift starts,
        // is the morning after rather than the morning before.
        if (! $in && $out && $schedule?->crossesMidnight()
            && $out->format('H:i') < $schedule->time('shift_start')) {
            $out = $out->addDay();
        }

        return [$in, $out];
    }

    // ------------------------------------------------------------ exceptions

    public function recordException(array $data, User $actor): AttendanceException
    {
        $exception = AttendanceException::create([
            'employee_id' => $data['employee_id'] ?? null,
            'client_company_id' => ($data['employee_id'] ?? null) ? null : $data['client_company_id'],
            'client_department_id' => ($data['employee_id'] ?? null) ? null : ($data['client_department_id'] ?? null),
            'exception_type' => $data['exception_type'],
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'remarks' => $data['remarks'] ?? null,
            'recorded_by' => $actor->id,
        ]);

        $this->audit->record('create', 'attendance', AttendanceException::class, $exception->id, null, [
            'type' => $exception->exception_type,
            'employee_id' => $exception->employee_id,
            'client_company_id' => $exception->client_company_id,
            'client_department_id' => $exception->client_department_id,
            'date_from' => $exception->date_from->toDateString(),
            'date_to' => $exception->date_to->toDateString(),
        ], clientCompanyId: $exception->client_company_id);

        return $exception;
    }

    public function voidException(AttendanceException $exception, string $reason, User $actor): AttendanceException
    {
        if ($exception->voided_at !== null) {
            throw new RuntimeException('This exception has already been withdrawn.');
        }

        $exception->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);

        $this->audit->record('void', 'attendance', AttendanceException::class, $exception->id, null, [
            'reason' => $reason,
        ], clientCompanyId: $exception->client_company_id);

        return $exception;
    }

    // ------------------------------------------------------------ comparison

    public function comparison(): AttendanceComparison
    {
        return new AttendanceComparison(max(0, (int) config('empower.attendance.late_grace_minutes', 0)));
    }

    /**
     * Compares one employee's DTR with their schedule, day by day.
     *
     * A context loaded for many employees can be passed in, so a workforce
     * summary costs a handful of queries rather than a handful per person.
     *
     * @return array{days: list<array>, summary: array, policy: array}
     */
    public function compare(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, ?AttendanceContext $context = null): array
    {
        $context ??= AttendanceContext::load([$employee->id], $from, $to);
        [$first, $last] = $this->employmentWindow($employee);

        return $this->comparison()->compare($employee, $from, $to, $context, $first, $last) + [
            'policy' => $this->policy(),
        ];
    }

    /**
     * One day's comparison, read fresh.
     */
    public function compareDay(Employee $employee, CarbonImmutable $date): array
    {
        return $this->compare($employee, $date, $date)['days'][0];
    }

    public function policy(): array
    {
        return [
            'late_grace_minutes' => max(0, (int) config('empower.attendance.late_grace_minutes', 0)),
        ];
    }

    public function currentSchedule(Employee $employee, ?Collection $schedules = null): ?EmployeeSchedule
    {
        $today = CarbonImmutable::today();
        $schedules ??= $employee->schedules()->inForce()->get();
        $placement = PlacementTimeline::forEmployees([$employee->id])->get($employee->id)->on($today);

        return $this->comparison()->scheduleFor($schedules->reject->isVoided(), $today, $placement);
    }

    // --------------------------------------------------------------- helpers

    /**
     * First and last day of employment; the last is null while still employed.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function employmentWindow(Employee $employee): array
    {
        $first = $employee->hire_date ? CarbonImmutable::parse($employee->hire_date)->startOfDay() : null;
        $ended = $employee->employmentEndDate();

        return [$first, $ended ? CarbonImmutable::parse($ended)->startOfDay() : null];
    }

    /**
     * The period to compare, defaulting to the current month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(?string $from, ?string $to): array
    {
        $start = $from ? CarbonImmutable::parse($from)->startOfDay() : CarbonImmutable::today()->startOfMonth();
        $end = $to ? CarbonImmutable::parse($to)->startOfDay() : $start->endOfMonth()->startOfDay();

        if ($end->lessThan($start)) {
            throw new RuntimeException('The end of the period is before its start.', 422);
        }

        if ($start->diffInDays($end) + 1 > self::MAX_PERIOD_DAYS) {
            throw new RuntimeException(sprintf('Compare at most %d days at a time.', self::MAX_PERIOD_DAYS), 422);
        }

        return [$start, $end];
    }
}
