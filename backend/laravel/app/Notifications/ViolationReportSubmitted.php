<?php

namespace App\Notifications;

use App\Models\ViolationReport;

/**
 * Tells HR a coordinator has sent in a report that needs reviewing.
 */
class ViolationReportSubmitted extends EmpowerNotification
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
        return 'New violation report for '.($this->report->employee?->full_name ?? 'an employee');
    }

    protected function body(): string
    {
        return sprintf(
            '%s reported %s on %s at %s. Submitted by %s for HR review.',
            $this->report->report_code,
            strtolower(str_replace('_', ' ', $this->report->violation_type)),
            $this->report->incident_date?->format('M j, Y'),
            $this->report->company?->company_name ?? 'the client',
            $this->report->reporter?->full_name ?? 'the coordinator',
        );
    }

    protected function link(): ?string
    {
        return '/violation-reports/'.$this->report->id;
    }
}
