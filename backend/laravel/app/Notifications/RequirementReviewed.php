<?php

namespace App\Notifications;

use App\Models\ApplicantRequirement;

/**
 * Tells an applicant that one of their submitted documents has been checked.
 *
 * A rejection has to say why. Under the paper process an applicant would be told
 * to "come back with the right one" without knowing what was wrong, which is the
 * single most common cause of a wasted return trip to the office.
 */
class RequirementReviewed extends EmpowerNotification
{
    public function __construct(
        private readonly ApplicantRequirement $requirement,
        private readonly string $outcome,
    ) {
    }

    protected function category(): string
    {
        return 'requirements';
    }

    protected function title(): string
    {
        $name = $this->requirement->requirementType?->requirement_name ?? 'A document';

        return match ($this->outcome) {
            'verified' => "{$name} accepted",
            'rejected' => "{$name} needs to be resubmitted",
            default => "{$name} updated",
        };
    }

    protected function body(): string
    {
        return match ($this->outcome) {
            'verified' => 'This requirement is now complete. No further action is needed.',
            'rejected' => $this->requirement->rejection_reason
                ? 'Reason: '.$this->requirement->rejection_reason
                : 'Please submit a replacement copy at the office.',
            default => 'The status of this requirement has changed.',
        };
    }

    protected function link(): ?string
    {
        return '/portal/documents';
    }

    protected function severity(): string
    {
        return $this->outcome === 'rejected' ? 'warning' : 'success';
    }
}
