<?php

namespace App\Policies;

use App\Models\ManpowerRequisition;
use App\Models\User;

/**
 * HR's access to coordinators' manpower requisitions.
 *
 * Coordinators reach their own through the coordinator routes, which scope by
 * assignment; none of these permissions belongs to them.
 */
class ManpowerRequisitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('requisitions.view');
    }

    public function view(User $user, ManpowerRequisition $requisition): bool
    {
        // A draft has not been sent, so it is not HR's to read yet.
        return $user->can('requisitions.view') && $requisition->status !== 'draft';
    }

    public function review(User $user, ManpowerRequisition $requisition): bool
    {
        return $user->can('requisitions.review')
            && $requisition->status !== 'draft'
            && (int) $requisition->requested_by !== (int) $user->id;
    }
}
