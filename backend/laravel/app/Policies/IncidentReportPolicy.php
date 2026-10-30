<?php

namespace App\Policies;

use App\Models\IncidentReport;
use App\Models\User;

/**
 * The office's access to coordinators' incident reports.
 *
 * Coordinators reach their own through the coordinator routes, which scope by
 * assignment; none of these permissions belongs to them.
 */
class IncidentReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('incident_reports.view');
    }

    public function view(User $user, IncidentReport $report): bool
    {
        // A draft has not been sent, so it is not the office's to read yet.
        return $user->can('incident_reports.view') && $report->status !== 'draft';
    }

    public function review(User $user, IncidentReport $report): bool
    {
        return $user->can('incident_reports.review') && $report->status !== 'draft';
    }
}
