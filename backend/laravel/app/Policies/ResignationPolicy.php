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
        return $user->can('separation.view')
            || $user->employee_id === $resignation->employee_id;
    }
}
