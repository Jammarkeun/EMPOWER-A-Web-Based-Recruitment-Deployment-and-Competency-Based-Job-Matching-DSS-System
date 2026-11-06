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

    /**
     * The 201 file is staff work. An employee sees their own employment through
     * the portal; the owner fallback this used to carry exposed the HR view -
     * profile notes, draft terminations and who filed them, who reported each
     * violation, and the evidence files.
     */
    public function view(User $user, Employee $employee): bool
    {
        return $user->can('employees.view');
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

    /**
     * Imports and client-wide leave or holidays, which belong to no one
     * employee.
     */
    public function manageAnyAttendance(User $user): bool
    {
        return $user->can('attendance.manage');
    }

    /**
     * Deciding what a flagged day was. Held apart from encoding, so the person
     * who keys in a DTR need not be the one who verifies it.
     */
    public function reviewAttendance(User $user): bool
    {
        return $user->can('attendance.review');
    }

    /**
     * The disciplinary record: the person's file and the violations in it.
     */
    public function viewViolations(User $user, Employee $employee): bool
    {
        return $user->can('employees.view') && $user->can('violations.view');
    }

    /**
     * Changing a recorded violation - its type, penalty, or resolution - is
     * held separately from recording a new one.
     */
    public function updateViolation(User $user, Employee $employee): bool
    {
        return $user->can('violations.update');
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
     * Open to HR, who filed it, and to the administrators who review it.
     */
    public function withdrawTermination(User $user, Employee $employee): bool
    {
        return $user->can('separation.create') || $user->can('separation.review_termination');
    }

    /**
     * Deciding HR's termination request: approve, return, or reject.
     *
     * An administrator's decision, held apart from filing. Approval ends
     * nobody's employment; it sends the case back to HR for clearance.
     */
    public function reviewTermination(User $user, Employee $employee): bool
    {
        return $user->can('separation.review_termination');
    }

    public function approveSeparation(User $user, Employee $employee): bool
    {
        // Completing a resignation is an agency decision, so an employee can
        // never approve their own.
        return $user->can('separation.approve');
    }

    /**
     * The processing after an administrator has approved a termination: the
     * pre-termination clearance, and the final step that ends the employment.
     *
     * The permission opens the door; SeparationService still refuses any
     * request that is not approved, or whose clearance is incomplete.
     */
    public function finaliseTermination(User $user, Employee $employee): bool
    {
        return $user->can('separation.finalize_termination');
    }
}
