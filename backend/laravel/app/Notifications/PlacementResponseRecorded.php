<?php

namespace App\Notifications;

use App\Models\Applicant;

/**
 * Tells the office what the applicant said about the placement.
 *
 * A decline is time-critical in a way most notifications are not: the client
 * company is expecting a worker on a stated date, and the agency would rather
 * hear it now than on the morning nobody arrives. An acceptance is sent too, so
 * whoever is preparing the endorsement knows the candidate is still coming.
 */
class PlacementResponseRecorded extends EmpowerNotification
{
    /**
     * The note is passed in rather than read off the applicant: answers are
     * kept per endorsement now (audit R-05), not in columns on the applicant.
     */
    public function __construct(
        private readonly Applicant $applicant,
        private readonly string $response,
        private readonly ?string $note = null,
    ) {
    }

    protected function category(): string
    {
        return 'application';
    }

    protected function title(): string
    {
        return $this->applicant->full_name.($this->response === 'accepted'
            ? ' accepted the placement'
            : ' declined the placement');
    }

    protected function body(): string
    {
        if ($this->response === 'declined') {
            return sprintf(
                '%s has withdrawn from this placement%s. They cannot be deployed to it '
                .'unless they change their mind and the office records their new answer.',
                $this->applicant->applicant_code,
                $this->note ? ': "'.$this->note.'"' : ''
            );
        }

        return sprintf(
            '%s has confirmed they want to go ahead with this placement.',
            $this->applicant->applicant_code
        );
    }

    protected function link(): ?string
    {
        return '/applicants/'.$this->applicant->id;
    }

    /**
     * A withdrawal leaves a client short a worker, which is a problem to act on
     * rather than a fact to note.
     */
    protected function severity(): string
    {
        return $this->response === 'declined' ? 'warning' : 'info';
    }
}
