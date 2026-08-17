<?php

namespace App\Notifications;

use App\Models\Deployment;

/**
 * Confirms a placement to the newly deployed employee.
 */
class DeploymentRecorded extends EmpowerNotification
{
    public function __construct(private readonly Deployment $deployment)
    {
    }

    protected function category(): string
    {
        return 'deployment';
    }

    protected function title(): string
    {
        return 'You have been deployed to '.($this->deployment->company?->company_name ?? 'a client company');
    }

    protected function body(): string
    {
        return sprintf(
            'Position: %s, %s. Start date: %s. Your employee number is %s.',
            $this->deployment->position_title,
            $this->deployment->department?->department_name ?? 'department to be confirmed',
            $this->deployment->deployment_date?->format('j F Y') ?? 'to be confirmed',
            $this->deployment->employee?->employee_number ?? 'pending',
        );
    }

    protected function link(): ?string
    {
        return '/portal/employment';
    }

    protected function severity(): string
    {
        return 'success';
    }
}
