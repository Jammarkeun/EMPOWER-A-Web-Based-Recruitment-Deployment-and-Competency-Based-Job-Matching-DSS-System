<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The daily time record set beside the schedule it should have followed.
 *
 * For each day it says what was scheduled, what the DTR shows, and where the
 * two differ. It points at differences for a person to review and stops
 * there: it computes no pay, records no violation, and decides nothing.
 *
 * Two rules matter more than the rest, because the version this replaced got
 * them wrong:
 *
 * - Nothing on file is not an absence. The DTR stays in the client's
 *   biometrics and reaches the agency late or not at all, so a scheduled day
 *   with no record is "awaiting DTR" - not enough data to compare - unless the
 *   DTR for that day is known to have been received (an import, or HR saying a
 *   period is complete). Only then is it "no DTR record", and even that is a
 *   discrepancy to look into, not a finding.
 * - A record that cannot be matched to a shift is not measured against one. A
 *   time-in after the shift has already ended is "does not match the scheduled
 *   shift", not ten hours late.
 *
 * The thresholds are the plain ones, labelled as ours where CDE has not said:
 * late is any time-in after the scheduled start once past the configured
 * grace (0 by default - CDE has stated none); early departure is any time-out
 * before the scheduled end. Time after the scheduled end is shown as
 * information only - it is not approved overtime and is never priced. Breaks
 * are shown, never deducted.
 */
final class AttendanceComparison
{
    public const DISCREPANCY_LABELS = [
        'late' => 'Late',
        'early_departure' => 'Early departure',
        'missing_time_in' => 'Missing time-in',
        'missing_time_out' => 'Missing time-out',
        'no_record' => 'No DTR record',
        'outside_schedule' => 'Outside assigned schedule',
        'unscheduled_record' => 'No schedule to compare with',
        'shift_mismatch' => 'Does not match the scheduled shift',
    ];

    /** Days that are not part of the comparison at all. */
    private const NOT_COUNTED = ['outside_employment', 'upcoming'];

    public function __construct(private readonly int $graceMinutes = 0)
    {
    }

    /**
     * @return array{days: list<array>, summary: array}
     */
    public function compare(
        Employee $employee,
        CarbonImmutable $from,
        CarbonImmutable $to,
        AttendanceContext $context,
        ?CarbonImmutable $employedFrom,
        ?CarbonImmutable $employedTo,
    ): array {
        $today = CarbonImmutable::today();
        $days = [];

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $days[] = $this->day($date, $employee, $context, $employedFrom, $employedTo, $today);
        }

        return ['days' => $days, 'summary' => $this->summarise($days)];
    }

    public function day(
        CarbonImmutable $date,
        Employee $employee,
        AttendanceContext $context,
        ?CarbonImmutable $employedFrom,
        ?CarbonImmutable $employedTo,
        CarbonImmutable $today,
    ): array {
        $placement = $context->placementsFor($employee->id)->on($date);
        $schedule = $this->scheduleFor($context->schedulesFor($employee->id), $date, $placement);
        /** @var AttendanceRecord|null $record */
        $record = $context->recordsFor($employee->id)->get($date->toDateString());
        $exception = $this->exceptionFor($context->exceptionsFor($employee->id), $date, $placement);
        $covered = $context->coverageFor($employee->id)->contains(fn ($c) => $c->covers($date));

        $in = $record?->time_in ? CarbonImmutable::parse($record->time_in) : null;
        $out = $record?->time_out ? CarbonImmutable::parse($record->time_out) : null;
        $scheduled = $schedule !== null && $schedule->isWorkDay($date);

        $day = [
            'date' => $date->toDateString(),
            'weekday' => $date->format('D'),
            'client_company_id' => $placement['client_company_id'] ?? null,
            'client_department_id' => $placement['client_department_id'] ?? null,
            'is_scheduled' => $scheduled,
            'schedule' => $scheduled ? [
                'id' => $schedule->id,
                'start' => $schedule->time('shift_start'),
                'end' => $schedule->time('shift_end'),
                'crosses_midnight' => $schedule->crossesMidnight(),
                'break_start' => $schedule->time('break_start'),
                'break_minutes' => $schedule->break_minutes,
            ] : null,
            'has_record' => $record !== null,
            'time_in' => $in?->format('H:i'),
            'time_out' => $out?->format('H:i'),
            'time_out_next_day' => $out !== null && $out->toDateString() !== $date->toDateString(),
            'remarks' => $record?->remarks,
            'source' => $record?->source,
            'import_code' => $record?->import?->import_code,
            'exception' => $exception ? [
                'id' => $exception->id,
                'type' => $exception->exception_type,
                'label' => $exception->label(),
                'remarks' => $exception->remarks,
            ] : null,
            'dtr_received' => $covered,
            'late_minutes' => 0,
            'early_departure_minutes' => 0,
            'minutes_after_end' => 0,
            'discrepancies' => [],
            'status' => null,
        ];

        if (($employedFrom && $date->lessThan($employedFrom)) || ($employedTo && $date->greaterThan($employedTo))) {
            return ['status' => 'outside_employment'] + $day;
        }

        if ($date->greaterThan($today)) {
            return ['status' => 'upcoming'] + $day;
        }

        $hasTimes = $in !== null || $out !== null;

        if ($schedule === null) {
            // Times with nothing to compare them against: flagged, because a
            // record nobody can read against a shift is itself a gap.
            return $hasTimes
                ? $this->flag($day, ['unscheduled_record'])
                : ['status' => 'no_schedule'] + $day;
        }

        if ($exception !== null || ! $scheduled) {
            // A known leave, holiday or rest day. Times on file for one are
            // attendance outside the assigned schedule, worth a look.
            if ($hasTimes) {
                return $this->flag($day, ['outside_schedule']);
            }

            return ['status' => $exception !== null ? 'excused' : 'rest_day'] + $day;
        }

        if (! $hasTimes) {
            return match (true) {
                // Today is not over, so a day without a punch yet is not a gap.
                $date->equalTo($today) => ['status' => 'in_progress'] + $day,
                $covered => $this->flag($day, ['no_record']),
                default => ['status' => 'awaiting_dtr'] + $day,
            };
        }

        $start = $date->setTimeFromTimeString($schedule->time('shift_start'));
        $end = $date->setTimeFromTimeString($schedule->time('shift_end'));

        if ($schedule->crossesMidnight()) {
            $end = $end->addDay();
        }

        // Times that never meet the scheduled shift belong to some other
        // shift, or were keyed against the wrong day. Measuring them as late
        // or early would put a number on a mistake.
        $overlaps = ($in === null || $in->lessThan($end)) && ($out === null || $out->greaterThan($start));

        if (! $overlaps) {
            return $this->flag($day, ['shift_mismatch']);
        }

        $flags = [];

        if ($in) {
            $lateBy = (int) floor($start->diffInMinutes($in, false));

            if ($lateBy > $this->graceMinutes) {
                $day['late_minutes'] = $lateBy;
                $flags[] = 'late';
            }
        } else {
            $flags[] = 'missing_time_in';
        }

        if ($out) {
            $earlyBy = (int) floor($out->diffInMinutes($end, false));
            $pastBy = (int) floor($end->diffInMinutes($out, false));

            if ($earlyBy > 0) {
                $day['early_departure_minutes'] = $earlyBy;
                $flags[] = 'early_departure';
            } elseif ($pastBy > 0) {
                $day['minutes_after_end'] = $pastBy;
            }
        } elseif (! $date->equalTo($today)) {
            $flags[] = 'missing_time_out';
        }

        return $flags === [] ? ['status' => 'on_schedule'] + $day : $this->flag($day, $flags);
    }

    /**
     * The schedule a day is compared with: in force on the date, and set for
     * the placement the worker was on that day. A schedule from a previous
     * client does not carry over to the next one.
     */
    public function scheduleFor(Collection $schedules, CarbonImmutable $date, ?array $placement): ?EmployeeSchedule
    {
        return $schedules->first(fn (EmployeeSchedule $s) => $s->appliesOn($date)
            && ($s->deployment_id === null || $placement === null || $s->deployment_id === $placement['deployment_id']));
    }

    private function exceptionFor(Collection $exceptions, CarbonImmutable $date, ?array $placement): ?AttendanceException
    {
        // An exception for the employee themselves outranks one for their
        // whole client.
        return $exceptions
            ->sortBy(fn (AttendanceException $e) => $e->employee_id === null ? 1 : 0)
            ->first(fn (AttendanceException $e) => $e->appliesTo(
                $date,
                $placement['client_company_id'] ?? null,
                $placement['client_department_id'] ?? null,
            ));
    }

    private function flag(array $day, array $flags): array
    {
        return ['status' => 'discrepancy', 'discrepancies' => $flags] + $day;
    }

    public function summarise(array $days): array
    {
        $counted = array_values(array_filter($days, fn ($d) => ! in_array($d['status'], self::NOT_COUNTED, true)));
        $with = fn (string $flag) => count(array_filter($counted, fn ($d) => in_array($flag, $d['discrepancies'], true)));
        $status = fn (string $s) => count(array_filter($counted, fn ($d) => $d['status'] === $s));

        $scheduled = count(array_filter($counted, fn ($d) => $d['is_scheduled']));
        $excused = $status('excused');
        $awaiting = $status('awaiting_dtr');
        $noSchedule = $status('no_schedule');
        $compared = count(array_filter($counted, fn ($d) => $d['is_scheduled']
            && in_array($d['status'], ['on_schedule', 'discrepancy'], true)));

        return [
            'scheduled_days' => $scheduled,
            'compared_days' => $compared,
            'on_schedule_days' => $status('on_schedule'),
            'discrepancy_days' => $status('discrepancy'),
            'late_days' => $with('late'),
            'late_minutes' => array_sum(array_column($counted, 'late_minutes')),
            'early_departure_days' => $with('early_departure'),
            'early_departure_minutes' => array_sum(array_column($counted, 'early_departure_minutes')),
            'missing_punch_days' => count(array_filter($counted, fn ($d) => array_intersect(['missing_time_in', 'missing_time_out'], $d['discrepancies']) !== [])),
            'no_record_days' => $with('no_record'),
            'outside_schedule_days' => $with('outside_schedule'),
            'unscheduled_record_days' => $with('unscheduled_record'),
            'shift_mismatch_days' => $with('shift_mismatch'),
            'minutes_after_end' => array_sum(array_column($counted, 'minutes_after_end')),
            'excused_days' => $excused,
            'awaiting_dtr_days' => $awaiting,
            'no_schedule_days' => $noSchedule,
            'records' => count(array_filter($counted, fn ($d) => $d['has_record'])),

            // Whether there was enough to compare at all. "no_dtr" and
            // "no_schedule" mean the figures above say nothing yet about how
            // the employee kept their schedule.
            'data_status' => match (true) {
                $scheduled === 0 && $noSchedule > 0 => 'no_schedule',
                $scheduled - $excused > 0 && $compared === 0 => 'no_dtr',
                $awaiting > 0 || $noSchedule > 0 => 'partial',
                default => 'complete',
            },
        ];
    }
}
