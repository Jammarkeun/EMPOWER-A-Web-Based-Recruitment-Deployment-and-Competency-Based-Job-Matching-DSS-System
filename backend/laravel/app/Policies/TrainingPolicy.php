<?php

namespace App\Policies;

use App\Models\Training;
use App\Models\User;

class TrainingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('training.view');
    }

    public function view(User $user, Training $training): bool
    {
        return $user->can('training.view');
    }

    public function create(User $user): bool
    {
        return $user->can('training.create');
    }

    public function update(User $user, Training $training): bool
    {
        return $user->can('training.update');
    }
}
