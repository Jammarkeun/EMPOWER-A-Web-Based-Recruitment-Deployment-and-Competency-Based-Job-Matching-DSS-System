<?php

namespace App\Notifications;

use App\Models\DtrSubmission;

/**
 * Tells HR a coordinator has handed over a client's DTR file.
 */
class DtrSubmissionSubmitted extends EmpowerNotification
{
    public function __construct(private readonly DtrSubmission $submission)
    {
    }

    protected function category(): string
    {
        return 'coordination';
    }

    protected function title(): string
    {
        return sprintf('DTR file from %s', $this->submission->company?->company_name ?? 'a client');
    }

    protected function body(): string
    {
        return sprintf(
            '%s: %s, covering %s to %s. Sent by %s for HR to check and import.',
            $this->submission->submission_code,
            $this->submission->original_filename,
            $this->submission->period_from?->format('M j, Y'),
            $this->submission->period_to?->format('M j, Y'),
            $this->submission->submitter?->full_name ?? 'the coordinator',
        );
    }

    protected function link(): ?string
    {
        return '/attendance?tab=submissions&submission='.$this->submission->id;
    }
}
