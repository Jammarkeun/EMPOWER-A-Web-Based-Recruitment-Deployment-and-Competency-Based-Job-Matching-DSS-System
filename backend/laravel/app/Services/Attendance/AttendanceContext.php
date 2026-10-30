<?php

namespace App\Services\Attendance;

use App\Models\AttendanceCoveragePeriod;
use App\Models\AttendanceException;
use App\Models\AttendanceRecord;
use App\Models\EmployeeSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything a comparison reads, loaded once for a set of employees.
 *
 * A workforce summary compares every deployed worker over a month. Loading
 * schedules, time records, coverage, exceptions and placements per person
 * would cost six queries each; loading them for the whole set costs six in all.
 */
final class AttendanceContext
{
    public function __construct(
        /** employee id => schedules in force, newest first */
        public readonly Collection $schedules,
        /** employee id => records keyed by Y-m-d */
        public readonly Collection $records,
        /** employee id => coverage periods */
        public readonly Collection $coverage,
        /** employee id => exceptions recorded for that employee */
        public readonly Collection $employeeExceptions,
        /** exceptions recorded for a whole client or department */
        public readonly Collection $clientExceptions,
        /** employee id => PlacementTimeline */
        public readonly Collection $placements,
    ) {
    }

    /**
     * @param  iterable<int>  $employeeIds
     */
    public static function load(iterable $employeeIds, CarbonImmutable $from, CarbonImmutable $to): self
    {
        $ids = collect($employeeIds)->unique()->values();
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $schedules = EmployeeSchedule::query()
            ->inForce()
            ->whereIn('employee_id', $ids)
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('employee_id');

        $records = AttendanceRecord::query()
            ->with('import:id,import_code')
            ->whereIn('employee_id', $ids)
            ->whereDate('work_date', '>=', $fromDate)
            ->whereDate('work_date', '<=', $toDate)
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $group) => $group->keyBy(fn (AttendanceRecord $r) => $r->work_date->toDateString()));

        $coverage = AttendanceCoveragePeriod::query()
            ->whereIn('employee_id', $ids)
            ->whereDate('period_from', '<=', $toDate)
            ->whereDate('period_to', '>=', $fromDate)
            ->get()
            ->groupBy('employee_id');

        $exceptions = AttendanceException::query()
            ->inForce()
            ->whereDate('date_from', '<=', $toDate)
            ->whereDate('date_to', '>=', $fromDate)
            ->where(fn ($q) => $q->whereIn('employee_id', $ids)->orWhereNull('employee_id'))
            ->get();

        return new self(
            $schedules,
            $records,
            $coverage,
            $exceptions->whereNotNull('employee_id')->groupBy('employee_id'),
            $exceptions->whereNull('employee_id')->values(),
            PlacementTimeline::forEmployees($ids),
        );
    }

    public function schedulesFor(int $employeeId): Collection
    {
        return $this->schedules->get($employeeId, collect());
    }

    public function recordsFor(int $employeeId): Collection
    {
        return $this->records->get($employeeId, collect());
    }

    public function coverageFor(int $employeeId): Collection
    {
        return $this->coverage->get($employeeId, collect());
    }

    public function exceptionsFor(int $employeeId): Collection
    {
        return $this->employeeExceptions->get($employeeId, collect())->concat($this->clientExceptions);
    }

    public function placementsFor(int $employeeId): PlacementTimeline
    {
        return $this->placements->get($employeeId) ?? new PlacementTimeline(collect());
    }
}
