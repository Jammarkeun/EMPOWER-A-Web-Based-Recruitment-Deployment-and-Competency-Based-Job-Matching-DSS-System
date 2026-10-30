<?php

namespace App\Notifications;

use App\Models\IncidentReport;
use App\Models\User;

/**
 * A new note on an incident report: to the coordinator when the office wrote
 * it, to the office when the coordinator did.
 */
class IncidentReportUpdated extends EmpowerNotification
{
    public function __construct(
        private readonly IncidentReport $report,
        private readonly User $author,
        private readonly ?string $note,
        private readonly bool $forOffice,
    ) {
    }

    protected function category(): string
    {
        return 'coordination';
    }

    protected function title(): string
    {
        if ($this->forOffice) {
            return 'Coordinator follow-up on '.$this->report->report_code;
        }

        return match ($this->report->status) {
            'resolved' => "Your incident report {$this->report->report_code} is resolved",
            'under_review' => "Your incident report {$this->report->report_code} is pending",
            default => "A note was added to {$this->report->report_code}",
        };
    }

    protected function body(): string
    {
        return trim(sprintf('%s: %s', $this->author->full_name, $this->note ?? IncidentReport::LABELS[$this->report->status] ?? ''));
    }

    protected function link(): ?string
    {
        return ($this->forOffice ? '/incident-reports/' : '/coordinator/incidents/').$this->report->id;
    }

    protected function severity(): string
    {
        return ! $this->forOffice && $this->report->status === 'resolved' ? 'success' : 'info';
    }
}
