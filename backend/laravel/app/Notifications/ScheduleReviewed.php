<?php

namespace App\Notifications;

use App\Models\ScheduleSubmission;

/**
 * Tells the coordinator what HR decided about a schedule they submitted.
 */
class ScheduleReviewed extends EmpowerNotification
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
        return $this->submission->status === 'approved'
            ? sprintf('Schedule approved: %s', $this->submission->employee?->full_name ?? 'employee')
            : sprintf('Schedule returned: %s', $this->submission->employee?->full_name ?? 'employee');
    }

    protected function body(): string
    {
        return $this->submission->status === 'approved'
            ? sprintf(
                '%s is approved and applies from %s. The DTR is compared with it from that day.',
                $this->submission->submission_code,
                $this->submission->effective_from?->format('M j, Y'),
            )
            : sprintf(
                '%s was returned for correction: %s',
                $this->submission->submission_code,
                $this->submission->review_note,
            );
    }

    protected function link(): ?string
    {
        return '/coordinator/schedules/'.$this->submission->id;
    }

    protected function severity(): string
    {
        return $this->submission->status === 'approved' ? 'success' : 'warning';
    }
}
