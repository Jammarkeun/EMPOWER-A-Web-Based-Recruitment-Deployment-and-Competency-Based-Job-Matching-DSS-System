<?php

namespace App\Notifications;

use App\Models\Applicant;

/**
 * Tells HR that someone has registered online and will be visiting the office.
 *
 * Worth an alert rather than leaving it to be noticed in a list: the person is
 * expecting to walk in, and arriving to a counter that has never heard of them
 * is exactly the impression the system exists to avoid.
 */
class ApplicantSelfRegistered extends EmpowerNotification
{
    public function __construct(private readonly Applicant $applicant)
    {
    }

    protected function category(): string
    {
        return 'application';
    }

    protected function title(): string
    {
        return $this->applicant->full_name.' registered online';
    }

    protected function body(): string
    {
        return sprintf(
            '%s. Awaiting an identity check at the office before screening can begin.',
            $this->applicant->applicant_code
            .($this->applicant->preferred_position ? ' - applying for '.$this->applicant->preferred_position : '')
        );
    }

    protected function link(): ?string
    {
        return '/applicants?awaiting_identity_check=1';
    }

    protected function severity(): string
    {
        return 'info';
    }
}
