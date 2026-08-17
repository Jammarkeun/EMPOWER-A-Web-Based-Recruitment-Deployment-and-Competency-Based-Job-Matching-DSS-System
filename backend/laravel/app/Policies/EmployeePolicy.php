<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->can('employees.view') || $user->employee_id === $employee->id;
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can('employees.update');
    }

    public function recordViolation(User $user, Employee $employee): bool
    {
        // Never available to the employee themselves: a disciplinary record is
        // issued by the agency, not self-reported.
        return $user->can('violations.create');
    }

    public function separate(User $user, Employee $employee): bool
    {
        // An employee may file their own resignation through the portal, which
        // is the one separation action they are entitled to start.
        return $user->can('separation.create') || $user->employee_id === $employee->id;
    }

    public function approveSeparation(User $user, Employee $employee): bool
    {
        // Completing a resignation is an agency decision, so an employee can
        // never approve their own.
        return $user->can('separation.approve');
    }

    /**
     * Finalising a dismissal is held apart from completing a resignation.
     *
     * A termination ends someone's livelihood and becomes the agency's evidence
     * if it is ever challenged, so it requires an administrator rather than the
     * same HR officer who filed it.
     */
    public function finaliseTermination(User $user, Employee $employee): bool
    {
        return $user->can('separation.finalize_termination');
    }
}
