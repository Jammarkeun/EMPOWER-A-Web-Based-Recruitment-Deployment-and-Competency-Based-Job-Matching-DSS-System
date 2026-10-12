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

    /**
     * Schedules and daily time records.
     *
     * Staff only for now. The employee's own view of their attendance would be
     * read through the portal, which resolves them from their login; the staff
     * routes take an employee id and so are never opened to the owner.
     */
    public function viewAnyAttendance(User $user): bool
    {
        return $user->can('attendance.view');
    }

    public function viewAttendance(User $user, Employee $employee): bool
    {
        return $user->can('attendance.view');
    }

    public function manageAttendance(User $user, Employee $employee): bool
    {
        return $user->can('attendance.manage');
    }

    public function recordViolation(User $user, Employee $employee): bool
    {
        // Never available to the employee themselves: a disciplinary record is
        // issued by the agency, not self-reported.
        return $user->can('violations.create');
    }

    /**
     * Filing or updating a separation from the staff screens.
     *
     * Staff only. An employee files their own resignation through the portal,
     * which resolves them from their own login and accepts nothing else. The
     * owner fallback this used to carry let an employee reach the staff routes
     * too — filing a termination against themselves, or marking their own
     * clearance "cleared" and their resignation "accepted".
     */
    public function separate(User $user, Employee $employee): bool
    {
        return $user->can('separation.create');
    }

    /**
     * Withdrawing a termination that should not go ahead.
     *
     * Open to whoever filed it and to whoever reviews it. A review that can
     * only ever end in a dismissal is not a review.
     */
    public function withdrawTermination(User $user, Employee $employee): bool
    {
        return $user->can('separation.create') || $user->can('separation.finalize_termination');
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
