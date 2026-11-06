<?php

namespace App\Notifications;

use App\Models\Applicant;
use App\Models\ApplicantRequirement;

/**
 * Tells the office that an applicant has sent a document through the portal.
 *
 * An upload lands as "submitted" and waits for someone to check it against the
 * original. Without an alert it would wait until somebody happened to open the
 * applicant's folder, and the applicant - who was told the office will check
 * it - would hear nothing in the meantime.
 */
class RequirementSubmitted extends EmpowerNotification
{
    public function __construct(
        private readonly Applicant $applicant,
        private readonly ApplicantRequirement $requirement,
        private readonly bool $renewal = false,
    ) {
    }

    protected function category(): string
    {
        return 'requirements';
    }

    protected function title(): string
    {
        $name = $this->requirement->requirementType?->requirement_name ?? 'a document';

        return $this->renewal
            ? "{$this->applicant->full_name} renewed {$name}"
            : "{$this->applicant->full_name} uploaded {$name}";
    }

    protected function body(): string
    {
        return sprintf(
            '%s. Sent through the applicant portal and awaiting verification against the original.',
            $this->applicant->applicant_code
        );
    }

    protected function link(): ?string
    {
        return '/documents?applicant='.$this->applicant->id;
    }
}
