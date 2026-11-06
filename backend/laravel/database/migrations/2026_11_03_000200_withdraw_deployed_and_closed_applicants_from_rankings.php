<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Takes deployed workers and terminated, resigned and archived applicants off
 * the rankings they were left on.
 *
 * Deployment and closing a record now withdraw the applicant from every
 * request's ranking, but results saved before that kept their rank until the
 * request was evaluated again, so a deployed or terminated worker could still
 * show as a recommendation. Same treatment as the live path: unshortlisted
 * results removed, shortlisted ones unranked, and the ranks left behind
 * renumbered without gaps.
 *
 * "Deployed" is taken from the status and from the employment record both,
 * since the two have disagreed before.
 */
return new class extends Migration
{
    private const OFF_RANKINGS = ['deployed', 'active', 'resigned', 'terminated', 'archived'];

    public function up(): void
    {
        DB::transaction(function () {
            $byStatus = DB::table('applicants')->whereIn('current_status', self::OFF_RANKINGS)->pluck('id');

            $placed = DB::table('employees')
                ->where('employment_status', 'active')
                ->whereExists(fn ($q) => $q->from('deployments')
                    ->whereColumn('deployments.employee_id', 'employees.id')
                    ->where('deployments.deployment_status', 'active'))
                ->whereNotNull('applicant_id')
                ->pluck('applicant_id');

            $leaving = $byStatus->merge($placed)->unique()->values();

            if ($leaving->isEmpty()) {
                return;
            }

            $requestIds = DB::table('job_request_matches')
                ->whereIn('applicant_id', $leaving)
                ->whereNotNull('rank_order')
                ->distinct()
                ->pluck('job_request_id');

            if ($requestIds->isEmpty()) {
                return;
            }

            $left = DB::table('job_request_matches')
                ->whereIn('job_request_id', $requestIds)
                ->whereIn('applicant_id', $leaving);

            (clone $left)->where('is_shortlisted', false)->delete();
            (clone $left)->where('is_shortlisted', true)->update(['rank_order' => null]);

            foreach ($requestIds as $requestId) {
                $ranked = DB::table('job_request_matches')
                    ->where('job_request_id', $requestId)
                    ->whereNotNull('rank_order')
                    ->orderBy('rank_order')
                    ->orderBy('id')
                    ->get(['id', 'rank_order']);

                foreach ($ranked->values() as $index => $row) {
                    if ((int) $row->rank_order !== $index + 1) {
                        DB::table('job_request_matches')->where('id', $row->id)->update(['rank_order' => $index + 1]);
                    }
                }
            }
        });
    }

    /** The removed results are not recoverable; re-running the evaluation rebuilds the ranking. */
    public function down(): void
    {
    }
};
