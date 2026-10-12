<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ViolationReport;

/**
 * HR's access to coordinators' reports.
 *
 * Coordinators reach their own reports through the coordinator routes, which
 * scope by assignment; none of these permissions belongs to them.
 */
class ViolationReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('violation_reports.view');
    }

    public function view(User $user, ViolationReport $report): bool
    {
        // A draft has not been sent, so it is not HR's to read yet.
        return $user->can('violation_reports.view') && $report->status !== 'draft';
    }

    public function review(User $user, ViolationReport $report): bool
    {
        return $user->can('violation_reports.review') && $report->status !== 'draft';
    }
}
