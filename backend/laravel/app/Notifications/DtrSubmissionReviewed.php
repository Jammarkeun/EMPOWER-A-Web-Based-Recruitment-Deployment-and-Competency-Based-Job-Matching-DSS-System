<?php

namespace App\Notifications;

use App\Models\DtrSubmission;

/**
 * Tells the coordinator what became of a DTR file they handed over: imported,
 * returned for correction, or rejected.
 */
class DtrSubmissionReviewed extends EmpowerNotification
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
        return match ($this->submission->status) {
            'imported' => 'DTR file imported: '.$this->submission->submission_code,
            'returned' => 'DTR file needs correction: '.$this->submission->submission_code,
            default => 'DTR file rejected: '.$this->submission->submission_code,
        };
    }

    protected function body(): string
    {
        return match ($this->submission->status) {
            'imported' => sprintf(
                '%s (%s to %s) was imported by HR%s.',
                $this->submission->original_filename,
                $this->submission->period_from?->format('M j'),
                $this->submission->period_to?->format('M j, Y'),
                $this->submission->import ? ' as '.$this->submission->import->import_code : ''
            ),
            default => sprintf('%s: %s', $this->submission->original_filename, $this->submission->review_note),
        };
    }

    protected function link(): ?string
    {
        return '/coordinator/dtr-submissions/'.$this->submission->id;
    }

    protected function severity(): string
    {
        return match ($this->submission->status) {
            'imported' => 'success',
            default => 'warning',
        };
    }
}
