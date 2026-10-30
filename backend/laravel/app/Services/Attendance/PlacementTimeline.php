<?php

namespace App\Services\Attendance;

use App\Models\Deployment;
use App\Models\DeploymentHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Where an employee was placed on any given day.
 *
 * Attendance has to be read against the placement in force on the day, not
 * today's: a worker who moved to another client last week was still at the
 * first one on the days before. A reassignment rewrites the department on the
 * deployment row and records the move in deployment_history, so the department
 * on an earlier day is the one the first later move came from.
 */
final class PlacementTimeline
{
    /**
     * @param  Collection<int, Deployment>  $deployments  newest first, history loaded
     */
    public function __construct(private readonly Collection $deployments)
    {
    }

    /**
     * @return array{deployment_id: int, client_company_id: int, client_department_id: ?int}|null
     */
    public function on(CarbonInterface $date): ?array
    {
        $deployment = $this->deployments->first(fn (Deployment $d) => $d->deployment_date
            && $date->greaterThanOrEqualTo($d->deployment_date->startOfDay())
            && ($d->end_date === null || $date->lessThanOrEqualTo($d->end_date->startOfDay())));

        if (! $deployment) {
            return null;
        }

        $laterMove = $deployment->history
            ->filter(fn (DeploymentHistory $h) => $h->from_department_id && $h->effective_date
                && $h->effective_date->startOfDay()->greaterThan($date))
            ->sortBy(fn (DeploymentHistory $h) => $h->effective_date->toDateString())
            ->first();

        return [
            'deployment_id' => $deployment->id,
            'client_company_id' => $deployment->client_company_id,
            'client_department_id' => $laterMove?->from_department_id ?? $deployment->client_department_id,
        ];
    }

    /**
     * Timelines for several employees, in one query.
     *
     * @param  iterable<int>  $employeeIds
     * @return Collection<int, self> keyed by employee id
     */
    public static function forEmployees(iterable $employeeIds): Collection
    {
        $ids = collect($employeeIds)->unique()->values();

        $deployments = Deployment::query()
            ->with('history')
            ->whereIn('employee_id', $ids)
            ->orderByDesc('deployment_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');

        return $ids->mapWithKeys(fn ($id) => [$id => new self($deployments->get($id, collect()))]);
    }
}
