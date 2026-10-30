<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records exactly which version of a profile and of a request's criteria each
 * ranking was scored against (audit S-02).
 *
 * Staleness used to be judged by comparing timestamps: a profile edited, or
 * criteria saved, after the evaluation. Timestamps here are kept to the
 * second, and an evaluation stamped itself when it finished rather than when
 * it read the data, so an edit made while an evaluation was running - or in
 * the same second as it - could leave a score computed from the old values
 * looking current. A counter bumped on every scored change, and copied onto
 * each result at the moment it is scored, cannot be fooled that way.
 *
 * The same applies to telling which results came from the latest run and
 * which were carried over from an earlier, narrower one (audit S-01): each run
 * of a request is numbered, and each result records the run that scored it.
 *
 * Additive only. Existing results are given the current version where the old
 * timestamp check considered them current, and left without one (so shown as
 * out of date) where it did not; nobody's verdict changes on deployment.
 * Existing results carry no run number; a request evaluated before this
 * counts as never numbered, so nothing is called carried over until its next
 * run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->unsignedInteger('profile_version')->default(0);
        });

        Schema::table('job_requests', function (Blueprint $table) {
            $table->unsignedInteger('criteria_version')->default(0);
            $table->unsignedInteger('evaluation_runs')->default(0);
        });

        Schema::table('job_request_matches', function (Blueprint $table) {
            $table->unsignedInteger('scored_profile_version')->nullable();
            $table->unsignedInteger('scored_criteria_version')->nullable();
            $table->unsignedInteger('evaluation_run')->nullable();
        });

        DB::table('job_request_matches')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('applicants')
                ->whereColumn('applicants.id', 'job_request_matches.applicant_id')
                ->where(fn ($q) => $q->whereNull('applicants.profile_updated_at')
                    ->orWhereColumn('applicants.profile_updated_at', '<=', 'job_request_matches.evaluated_at')))
            ->update(['scored_profile_version' => 0]);

        DB::table('job_request_matches')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('request_criteria')
                ->whereColumn('request_criteria.job_request_id', 'job_request_matches.job_request_id')
                ->whereColumn('request_criteria.updated_at', '>', 'job_request_matches.evaluated_at'))
            ->update(['scored_criteria_version' => 0]);
    }

    public function down(): void
    {
        Schema::table('job_request_matches', function (Blueprint $table) {
            $table->dropColumn(['scored_profile_version', 'scored_criteria_version', 'evaluation_run']);
        });

        Schema::table('job_requests', function (Blueprint $table) {
            $table->dropColumn(['criteria_version', 'evaluation_runs']);
        });

        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn('profile_version');
        });
    }
};
