<?php

namespace App\Notifications;

use App\Models\Applicant;
use App\Models\ApplicantBioDataVersion;

/**
 * Tells the office an applicant has sent their Bio-Data through the form.
 *
 * Name and applicant code only, as for an upload: the details themselves are
 * read on the applicant's record, not in a notification preview.
 */
class BioDataSubmitted extends EmpowerNotification
{
    public function __construct(
        private readonly Applicant $applicant,
        private readonly ApplicantBioDataVersion $version,
    ) {
    }

    protected function category(): string
    {
        return 'requirements';
    }

    protected function title(): string
    {
        return $this->version->version_number > 1
            ? "{$this->applicant->full_name} resubmitted their Bio-Data"
            : "{$this->applicant->full_name} submitted their Bio-Data";
    }

    protected function body(): string
    {
        return sprintf(
            '%s. Filled in online and awaiting review. Verifying it does not verify any other document.',
            $this->applicant->applicant_code
        );
    }

    protected function link(): ?string
    {
        return '/documents?applicant='.$this->applicant->id;
    }
}
