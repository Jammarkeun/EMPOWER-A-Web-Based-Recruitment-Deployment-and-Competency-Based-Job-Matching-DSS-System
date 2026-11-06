<?php

namespace App\Notifications;

use App\Models\ApplicantBioDataVersion;

/**
 * Tells the applicant what HR decided about their Bio-Data.
 *
 * What to correct is shown on the Bio-Data page rather than here: the note can
 * name personal details, and a notification preview is not the place for them.
 */
class BioDataReviewed extends EmpowerNotification
{
    public function __construct(private readonly string $outcome)
    {
    }

    protected function category(): string
    {
        return 'requirements';
    }

    protected function title(): string
    {
        return $this->outcome === ApplicantBioDataVersion::VERIFIED
            ? 'Bio-Data accepted'
            : 'Your Bio-Data needs correcting';
    }

    protected function body(): string
    {
        return $this->outcome === ApplicantBioDataVersion::VERIFIED
            ? 'The office has checked your Bio-Data. This requirement is now complete.'
            : 'Open My Bio-Data to see what the office asked you to change, then send it again.';
    }

    protected function link(): ?string
    {
        return '/portal/bio-data';
    }

    protected function severity(): string
    {
        return $this->outcome === ApplicantBioDataVersion::VERIFIED ? 'success' : 'warning';
    }
}
