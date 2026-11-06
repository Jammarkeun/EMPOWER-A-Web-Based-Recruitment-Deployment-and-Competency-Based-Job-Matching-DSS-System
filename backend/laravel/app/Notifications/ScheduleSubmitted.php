<?php

namespace App\Notifications;

use App\Models\ScheduleSubmission;

/**
 * Tells HR a coordinator has sent in a work schedule to approve.
 */
class ScheduleSubmitted extends EmpowerNotification
{
    public function __construct(private readonly ScheduleSubmission $submission)
    {
    }

    protected function category(): string
    {
        return 'coordination';
    }

    protected function title(): string
    {
        return sprintf('Work schedule for %s', $this->submission->employee?->full_name ?? 'an employee');
    }

    protected function body(): string
    {
        return sprintf(
            '%s: %s from %s, at %s. Submitted by %s for HR approval.',
            $this->submission->submission_code,
            $this->submission->toSchedule()->describe(),
            $this->submission->effective_from?->format('M j, Y'),
            $this->submission->company?->company_name ?? 'the client',
            $this->submission->creator?->full_name ?? 'the coordinator',
        );
    }

    protected function link(): ?string
    {
        return '/attendance?tab=schedules&submission='.$this->submission->id;
    }
}
