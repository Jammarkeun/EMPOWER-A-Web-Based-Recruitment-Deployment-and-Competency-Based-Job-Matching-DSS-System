<?php

namespace App\Policies;

use App\Models\Deployment;
use App\Models\User;

class DeploymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('deployment.view');
    }

    public function view(User $user, Deployment $deployment): bool
    {
        return $user->can('deployment.view')
            || $user->employee_id === $deployment->employee_id;
    }

    public function create(User $user): bool
    {
        return $user->can('deployment.create');
    }

    public function reassign(User $user, Deployment $deployment): bool
    {
        return $user->can('deployment.reassign');
    }
}
