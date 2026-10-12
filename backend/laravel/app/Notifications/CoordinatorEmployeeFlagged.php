<?php

namespace App\Notifications;

use App\Models\Employee;

/**
 * Tells a client's coordinator that one of the workers there has reached the
 * disciplinary review threshold.
 *
 * Sent alongside the notice HR and administrators receive, with a link into the
 * coordinator's own workspace. It informs; the decision stays with HR and,
 * for a termination, the administrator.
 */
class CoordinatorEmployeeFlagged extends EmpowerNotification
{
    public function __construct(
        private readonly Employee $employee,
        private readonly int $activeCount,
    ) {
    }

    protected function category(): string
    {
        return 'coordination';
    }

    protected function title(): string
    {
        return ($this->employee->full_name ?? $this->employee->employee_number).' has reached the review threshold';
    }

    protected function body(): string
    {
        return sprintf(
            '%s now has %d active violations. HR and the administrator will review the record; no action is needed from you unless HR asks.',
            $this->employee->employee_number,
            $this->activeCount,
        );
    }

    protected function link(): ?string
    {
        return '/coordinator/employees/'.$this->employee->id;
    }

    protected function severity(): string
    {
        return 'warning';
    }
}
