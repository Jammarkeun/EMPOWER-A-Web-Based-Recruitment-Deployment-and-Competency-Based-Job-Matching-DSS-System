<?php

namespace App\Notifications;

use App\Models\ViolationReport;

/**
 * Tells the coordinator what HR did with their report.
 */
class ViolationReportReviewed extends EmpowerNotification
{
    public function __construct(private readonly ViolationReport $report)
    {
    }

    protected function category(): string
    {
        return 'coordination';
    }

    protected function title(): string
    {
        $who = $this->report->employee?->full_name ?? 'an employee';

        return match ($this->report->status) {
            'under_review' => "HR is reviewing your report on {$who}",
            'returned' => "Your report on {$who} was returned for correction",
            'validated' => "Your report on {$who} was validated",
            'dismissed' => "Your report on {$who} was dismissed",
            default => "Your report on {$who} was updated",
        };
    }

    protected function body(): string
    {
        $outcome = match ($this->report->status) {
            'under_review' => 'HR has started reviewing it.',
            'returned' => 'Please correct it and submit it again.',
            'validated' => 'It is now an official violation on the employee\'s record.',
            'dismissed' => 'No violation was recorded.',
            default => 'Its status has changed.',
        };

        return trim($this->report->report_code.': '.$outcome
            .($this->report->review_note ? ' HR\'s note: '.$this->report->review_note : ''));
    }

    protected function link(): ?string
    {
        return '/coordinator/reports/'.$this->report->id;
    }

    protected function severity(): string
    {
        return $this->report->status === 'returned' ? 'warning' : 'info';
    }
}
