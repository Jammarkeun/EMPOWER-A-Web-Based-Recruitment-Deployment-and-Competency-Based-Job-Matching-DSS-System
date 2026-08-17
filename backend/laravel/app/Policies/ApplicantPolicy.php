<?php

namespace App\Policies;

use App\Models\Applicant;
use App\Models\User;

/**
 * Staff act through permissions; portal users reach only their own record.
 *
 * The portal check is the important one. An applicant account holds no staff
 * permissions at all, so without an explicit ownership test every portal request
 * would simply be denied - and with a careless one, an applicant could read
 * another applicant's birth certificate.
 */
class ApplicantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('applicants.view');
    }

    public function view(User $user, Applicant $applicant): bool
    {
        if ($user->can('applicants.view')) {
            return true;
        }

        return $this->owns($user, $applicant);
    }

    public function create(User $user): bool
    {
        return $user->can('applicants.create');
    }

    public function update(User $user, Applicant $applicant): bool
    {
        if ($user->can('applicants.update')) {
            return true;
        }

        // A portal user may correct their own contact details, but only while
        // their application is still being screened. Once they are deployment
        // ready the record has been verified against documents and must not
        // change underneath HR.
        return $this->owns($user, $applicant)
            && in_array($applicant->current_status, ['applied', 'initial_screening', 'incomplete_requirements'], true);
    }

    public function changeStatus(User $user, Applicant $applicant): bool
    {
        // Never available to portal users: an applicant cannot advance their own
        // application.
        return $user->can('applicants.change_status');
    }

    public function uploadRequirement(User $user, Applicant $applicant): bool
    {
        return $user->can('requirements.upload') || $this->owns($user, $applicant);
    }

    public function verifyRequirement(User $user, Applicant $applicant): bool
    {
        // Verification is a staff judgement about a document's authenticity, so
        // an applicant can never verify their own submission.
        return $user->can('requirements.verify');
    }

    public function delete(User $user, Applicant $applicant): bool
    {
        return $user->can('applicants.delete');
    }

    private function owns(User $user, Applicant $applicant): bool
    {
        return $user->applicant_id === $applicant->id;
    }
}
