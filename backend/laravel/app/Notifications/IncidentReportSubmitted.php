<?php

namespace App\Notifications;

use App\Models\IncidentReport;

/**
 * Tells the office a coordinator has sent in an incident report to read.
 */
class IncidentReportSubmitted extends EmpowerNotification
{
    public function __construct(private readonly IncidentReport $report)
    {
    }

    protected function category(): string
    {
        return 'coordination';
    }

    protected function title(): string
    {
        return 'Incident reported at '.($this->report->company?->company_name ?? 'a client');
    }

    protected function body(): string
    {
        return sprintf(
            '%s: "%s" on %s. Reported by %s.',
            $this->report->report_code,
            $this->report->title,
            $this->report->incident_date?->format('M j, Y'),
            $this->report->reporter?->full_name ?? 'the coordinator',
        );
    }

    protected function link(): ?string
    {
        return '/incident-reports/'.$this->report->id;
    }
}
