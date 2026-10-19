<?php

namespace App\Policies;

use App\Models\Applicant;
use App\Models\User;

/**
 * The staff view of an applicant record, decided by permission alone.
 *
 * Portal accounts never pass these checks. An applicant reads their own record
 * through /portal, which resolves it from the signed-in account and returns
 * only what is meant for them; the staff routes are also closed to portal
 * accounts by the `staff` middleware.
 */
class ApplicantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('applicants.view');
    }

    /**
     * Viewing the staff record is staff work.
     *
     * An applicant reads their own record through the portal, which returns
     * only what is meant for them. The owner used to be let through here too,
     * which opened the HR view of their own file - interview ratings, internal
     * remarks, their ranking against other applicants - and let opening a
     * document mark it "under review" as if an officer had.
     */
    public function view(User $user, Applicant $applicant): bool
    {
        return $user->can('applicants.view');
    }

    /**
     * An applicant's documents: the record (applicants.view) and the papers
     * themselves (requirements.view).
     */
    public function viewRequirements(User $user, Applicant $applicant): bool
    {
        return $user->can('applicants.view') && $user->can('requirements.view');
    }

    /**
     * Closing a record off to the archive is archives.create, on top of being
     * allowed to move the applicant at all.
     */
    public function archive(User $user, Applicant $applicant): bool
    {
        return $user->can('applicants.change_status') && $user->can('archives.create');
    }

    public function create(User $user): bool
    {
        return $user->can('applicants.create');
    }

    /**
     * Editing the full applicant record is staff work.
     *
     * An applicant corrects their own contact details through the portal's
     * profile endpoint, which accepts only those fields. Letting the owner
     * through here as well opened every field on the staff form to them — the
     * interview ratings and the height, sex, birth date, and distance the
     * matching engine scores against included — so an applicant could rate
     * their own interview "5 of 5" before being ranked.
     */
    public function update(User $user, Applicant $applicant): bool
    {
        return $user->can('applicants.update');
    }

    public function changeStatus(User $user, Applicant $applicant): bool
    {
        // Never available to portal users: an applicant cannot advance their own
        // application.
        return $user->can('applicants.change_status');
    }

    /**
     * Not used by any route: applicants upload through the portal, which
     * resolves the record from their own account, and staff do not upload on
     * an applicant's behalf. Kept staff-only so it cannot become an owner
     * fallback if a route is added later.
     */
    public function uploadRequirement(User $user, Applicant $applicant): bool
    {
        return $user->can('requirements.upload');
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
}
