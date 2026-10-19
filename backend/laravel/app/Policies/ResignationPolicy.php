<?php

namespace App\Policies;

use App\Models\Resignation;
use App\Models\User;

class ResignationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('separation.view');
    }

    public function view(User $user, Resignation $resignation): bool
    {
        // Staff only; an employee sees their own filing through the portal.
        return $user->can('separation.view');
    }
}
