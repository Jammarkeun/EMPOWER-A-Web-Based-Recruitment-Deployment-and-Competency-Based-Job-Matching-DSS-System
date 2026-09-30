<?php

namespace App\Notifications;

use App\Models\Employee;

/**
 * Tells the office that an employee has reached the client's review threshold.
 *
 * The point of the alert is that nobody should have to notice this by counting
 * rows. CDE's handbook puts a worker up for review on the fourth offence within
 * a year, and the offences arrive weeks apart across different supervisors —
 * exactly the pattern a person misses and a system does not.
 *
 * It says "review", not "terminate", and that wording is the whole design.
 * Reaching a threshold raises a question; an administrator answers it. Nothing
 * in the system ends anybody's employment on a count.
 */
class ViolationThresholdReached extends EmpowerNotification
{
    public function __construct(
        private readonly Employee $employee,
        private readonly int $activeCount,
    ) {
    }

    protected function category(): string
    {
        return 'general';
    }

    protected function title(): string
    {
        return ($this->employee->full_name ?? $this->employee->employee_number)
            .' has reached the review threshold';
    }

    protected function body(): string
    {
        return sprintf(
            '%s now has %d active violation%s within the last %d months, at a threshold of %d. '
            .'An administrator should review the record and decide what follows.',
            $this->employee->employee_number,
            $this->activeCount,
            $this->activeCount === 1 ? '' : 's',
            (int) config('empower.violations.active_window_months', 12),
            (int) config('empower.violations.termination_threshold', 4),
        );
    }

    protected function link(): ?string
    {
        return '/employees/'.$this->employee->id;
    }

    /**
     * A warning rather than an alarm. Something needs a decision, but the
     * agency has not lost anything and no deadline has passed.
     */
    protected function severity(): string
    {
        return 'warning';
    }
}
