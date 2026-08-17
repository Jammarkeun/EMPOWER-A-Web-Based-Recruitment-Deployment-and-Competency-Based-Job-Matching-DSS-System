<?php

namespace App\Policies;

use App\Models\ClientCompany;
use App\Models\User;

class ClientCompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('clients.view');
    }

    public function view(User $user, ClientCompany $company): bool
    {
        return $user->can('clients.view');
    }

    public function create(User $user): bool
    {
        return $user->can('clients.create');
    }

    public function update(User $user, ClientCompany $company): bool
    {
        return $user->can('clients.update');
    }

    public function delete(User $user, ClientCompany $company): bool
    {
        return $user->can('clients.delete');
    }
}
