<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The daily time record, set beside the schedule it should have followed.
 *
 * The review panel asked for the DTR to be compared with the schedule, and
 * that is all this does: for each day it says what was scheduled, what was
 * recorded, and where the two differ - late, left early, stayed past the end
 * of the shift, a missing punch, a scheduled day with no record at all.
 *
 * It deliberately stops there. It computes no pay, applies no deduction, and
 * records no violation. Those are the agency's decisions, made by a person
 * reading this, and a comparison that quietly turned into discipline or a
 * payslip would be doing something nobody asked it to.
 *
 * The rules are the plain ones, labelled as ours where CDE has not said:
 *
 * - Late is a time-in after the scheduled start, counted in minutes from the
 *   start, once it passes the configured grace (0 by default: CDE has not
 *   stated one).
 * - Undertime is a time-out before the scheduled end.
 * - Overtime here means only minutes recorded past the scheduled end. It is
 *   reported, not approved, and never priced.
 * - Absent is a scheduled day, now past, with neither a time-in nor a
 *   time-out. Today is not called absent until it is over.
 * - A day with no schedule in force is reported as such rather than guessed at.
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

        // Schedules only move forward. Inserting one before a later schedule
        // would rewrite which rules applied to days already compared.
        $later = EmployeeSchedule::where('employee_id', $employee->id)
            ->whereDate('effective_from', '>=', $from->toDateString())
            ->exists();

        if ($later) {
            throw new RuntimeException(
                'A schedule already starts on or after that date. Choose a later start date for the new schedule.'
            );
        }

        $workDays = collect($data['work_days'])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();

        return DB::transaction(function () use ($employee, $data, $actor, $from, $workDays) {
            // The schedule in force ends the day before the new one begins.
            EmployeeSchedule::where('employee_id', $employee->id)
                ->where(fn ($q) => $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $from->toDateString()))
                ->update(['effective_to' => $from->subDay()->toDateString()]);

            $schedule = EmployeeSchedule::create([
                'employee_id' => $employee->id,
                'effective_from' => $from->toDateString(),
                'effective_to' => null,
                'work_days' => $workDays,
                'shift_start' => $data['shift_start'],
                'shift_end' => $data['shift_end'],
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->audit->record('create', 'attendance', EmployeeSchedule::class, $schedule->id, null, [
                'employee_number' => $employee->employee_number,
                'schedule' => $schedule->describe(),
                'effective_from' => $from->toDateString(),
            ]);

            return $schedule;
        });
    }

    // --------------------------------------------------------- time records

    /**
     * Saves a run of DTR entries, as they are copied off a time sheet.
     *
     * An entry with nothing in it removes that day's record, which is what
     * clearing a row on the sheet means. Times are given as HH:MM on the work
     * date; a time-out that is not after the time-in belongs to the next day,
     * which is how a night shift reads.
     *
     * @return array{saved: int, cleared: int}
     */
    public function saveRecords(Employee $employee, array $entries, User $actor): array
    {
        [$first, $last] = $this->employmentWindow($employee);
        $today = CarbonImmutable::today();
        $schedules = $employee->schedules()->get();

        foreach ($entries as $entry) {
            $date = CarbonImmutable::parse($entry['work_date'])->startOfDay();

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

        $saved = 0;
        $cleared = 0;

        DB::transaction(function () use ($employee, $entries, $actor, $schedules, &$saved, &$cleared) {
            foreach ($entries as $entry) {
                $date = CarbonImmutable::parse($entry['work_date'])->startOfDay();
                $existing = AttendanceRecord::where('employee_id', $employee->id)
                    ->whereDate('work_date', $date->toDateString())
                    ->first();

                $timeIn = $entry['time_in'] ?? null;
                $timeOut = $entry['time_out'] ?? null;
                $remarks = isset($entry['remarks']) ? trim((string) $entry['remarks']) : null;

                if (blank($timeIn) && blank($timeOut) && blank($remarks)) {
                    if ($existing) {
                        $existing->delete();
                        $cleared++;
                    }

                    continue;
                }

                [$in, $out] = $this->timestamps($date, $timeIn, $timeOut, $this->scheduleOn($schedules, $date));

                $values = [
                    'time_in' => $in,
                    'time_out' => $out,
                    'remarks' => $remarks ?: null,
                ];

                if ($existing) {
                    $existing->update($values + ['updated_by' => $actor->id]);
                } else {
                    AttendanceRecord::create($values + [
                        'employee_id' => $employee->id,
                        'work_date' => $date->toDateString(),
                        'recorded_by' => $actor->id,
                    ]);
                }

                $saved++;
            }
        });

        $this->audit->record('update', 'attendance', Employee::class, $employee->id, null, [
            'employee_number' => $employee->employee_number,
            'days_saved' => $saved,
            'days_cleared' => $cleared,
            'dates' => collect($entries)->pluck('work_date')->sort()->values()->all(),
        ]);

        return ['saved' => $saved, 'cleared' => $cleared];
    }

    /**
     * Turns HH:MM on a work date into full timestamps.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function timestamps(CarbonImmutable $date, ?string $timeIn, ?string $timeOut, ?EmployeeSchedule $schedule): array
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

    // ------------------------------------------------------------ comparison

    /**
     * Compares one employee's DTR with their schedule, day by day.
     *
     * Schedules and records may be passed in already loaded, so a summary of
     * many employees costs two queries rather than two per person.
     */
    public function compare(
        Employee $employee,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Collection $schedules = null,
        ?Collection $records = null,
    ): array {
        $schedules ??= $employee->schedules()->get();
        $records ??= AttendanceRecord::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->get();

        $byDate = $records->keyBy(fn (AttendanceRecord $r) => $r->work_date->toDateString());
        [$first, $last] = $this->employmentWindow($employee);
        $grace = max(0, (int) config('empower.attendance.late_grace_minutes', 0));
        $today = CarbonImmutable::today();

        $days = [];

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $days[] = $this->compareDay(
                $date,
                $this->scheduleOn($schedules, $date),
                $byDate->get($date->toDateString()),
                $first,
                $last,
                $today,
                $grace
            );
        }

        return [
            'days' => $days,
            'summary' => $this->summarise($days),
            'policy' => [
                'late_grace_minutes' => $grace,
            ],
        ];
    }

    private function compareDay(
        CarbonImmutable $date,
        ?EmployeeSchedule $schedule,
        ?AttendanceRecord $record,
        ?CarbonImmutable $first,
        ?CarbonImmutable $last,
        CarbonImmutable $today,
        int $grace,
    ): array {
        $in = $record?->time_in ? CarbonImmutable::parse($record->time_in) : null;
        $out = $record?->time_out ? CarbonImmutable::parse($record->time_out) : null;
        $scheduled = $schedule !== null && $schedule->isWorkDay($date);

        $day = [
            'date' => $date->toDateString(),
            'weekday' => $date->format('D'),
            'is_scheduled' => $scheduled,
            'schedule' => $scheduled ? [
                'start' => $schedule->time('shift_start'),
                'end' => $schedule->time('shift_end'),
                'crosses_midnight' => $schedule->crossesMidnight(),
            ] : null,
            'has_record' => $record !== null,
            'time_in' => $in?->format('H:i'),
            'time_out' => $out?->format('H:i'),
            'time_out_next_day' => $in && $out ? $out->toDateString() !== $in->toDateString()
                : ($out ? $out->toDateString() !== $date->toDateString() : false),
            'remarks' => $record?->remarks,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
            'flags' => [],
            'status' => null,
        ];

        if (($first && $date->lessThan($first)) || ($last && $date->greaterThan($last))) {
            $day['status'] = 'outside_employment';

            return $day;
        }

        if ($date->greaterThan($today)) {
            $day['status'] = 'upcoming';

            return $day;
        }

        if (! $scheduled) {
            $day['status'] = match (true) {
                $schedule === null && ($in || $out) => 'no_schedule_work',
                $schedule === null => 'no_schedule',
                $in || $out => 'rest_day_work',
                default => 'rest_day',
            };

            return $day;
        }

        if (! $in && ! $out) {
            // Today is not over, so a day without a punch yet is not an
            // absence yet.
            $day['status'] = $date->equalTo($today) ? 'awaiting_record' : 'absent';

            if ($day['status'] === 'absent') {
                $day['flags'][] = 'absent';
            }

            return $day;
        }

        $start = $date->setTimeFromTimeString($schedule->time('shift_start'));
        $end = $date->setTimeFromTimeString($schedule->time('shift_end'));

        if ($schedule->crossesMidnight()) {
            $end = $end->addDay();
        }

        if ($in) {
            $lateBy = (int) floor($start->diffInMinutes($in, false));

            if ($lateBy > $grace) {
                $day['late_minutes'] = $lateBy;
                $day['flags'][] = 'late';
            }
        } else {
            $day['flags'][] = 'missing_time_in';
        }

        if ($out) {
            $earlyBy = (int) floor($out->diffInMinutes($end, false));
            $pastBy = (int) floor($end->diffInMinutes($out, false));

            if ($earlyBy > 0) {
                $day['undertime_minutes'] = $earlyBy;
                $day['flags'][] = 'undertime';
            } elseif ($pastBy > 0) {
                $day['overtime_minutes'] = $pastBy;
                $day['flags'][] = 'overtime';
            }
        } elseif (! $date->equalTo($today)) {
            $day['flags'][] = 'missing_time_out';
        }

        // Staying past the end of the shift is reported, but it is not a
        // shortfall against the schedule: a day whose only flag is overtime is
        // still a day worked as scheduled, and it does not put the employee on
        // the list of people needing attention.
        $day['status'] = array_diff($day['flags'], ['overtime']) === [] ? 'present' : 'discrepancy';

        return $day;
    }

    private function summarise(array $days): array
    {
        $counted = array_filter($days, fn ($d) => ! in_array($d['status'], ['outside_employment', 'upcoming'], true));
        $scheduled = array_filter($counted, fn ($d) => $d['is_scheduled']);
        $flagged = fn (string $flag) => count(array_filter($scheduled, fn ($d) => in_array($flag, $d['flags'], true)));

        return [
            'scheduled_days' => count($scheduled),
            'days_present' => count(array_filter($scheduled, fn ($d) => in_array($d['status'], ['present', 'discrepancy'], true))),
            'days_absent' => $flagged('absent'),
            'days_late' => $flagged('late'),
            'late_minutes' => array_sum(array_column($scheduled, 'late_minutes')),
            'days_undertime' => $flagged('undertime'),
            'undertime_minutes' => array_sum(array_column($scheduled, 'undertime_minutes')),
            'days_overtime' => $flagged('overtime'),
            'overtime_minutes' => array_sum(array_column($scheduled, 'overtime_minutes')),
            'incomplete_records' => $flagged('missing_time_in') + $flagged('missing_time_out'),
            'rest_day_work' => count(array_filter($counted, fn ($d) => $d['status'] === 'rest_day_work')),
            'days_without_schedule' => count(array_filter($counted, fn ($d) => in_array($d['status'], ['no_schedule', 'no_schedule_work'], true))),
            'days_with_discrepancy' => count(array_filter($counted, fn ($d) => $d['status'] === 'discrepancy' || $d['status'] === 'absent')),
        ];
    }

    // --------------------------------------------------------------- helpers

    public function scheduleOn(Collection $schedules, CarbonImmutable $date): ?EmployeeSchedule
    {
        return $schedules->first(fn (EmployeeSchedule $s) => $s->appliesOn($date));
    }

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
