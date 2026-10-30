<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Endorsements, and the applicant's answer to each one (audit R-05, R-07).
 *
 * The applicant's answer to an offer of work used to be three columns on the
 * applicant: one answer for life. A decline for one client blocked every later
 * deployment, and nothing could correct it. An endorsement is now a record of
 * its own - the applicant put forward to a client, optionally for a particular
 * request - and each answer belongs to one endorsement. Corrections are new
 * rows that point at the answer they correct, so nothing is overwritten.
 *
 * Timestamps are nullable and set by the application: XAMPP's MariaDB would
 * otherwise make the first NOT NULL timestamp ON UPDATE CURRENT_TIMESTAMP.
 * The old applicant columns stay in place, untouched, for rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endorsements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants');
            // The request the applicant was put forward for, when the officer
            // said which. Optional: CDE's process does not always name one.
            $table->foreignId('job_request_id')->nullable()->constrained('job_requests');
            // The status change that endorsed them, where there was one.
            $table->foreignId('status_history_id')->nullable()->constrained('application_status_history');
            $table->foreignId('endorsed_by')->nullable()->constrained('users');
            $table->timestamp('endorsed_at')->nullable();
            // True when this row was written after the fact - by the backfill,
            // or when an answer arrived for an endorsement nobody recorded -
            // rather than at the moment of endorsement.
            $table->boolean('recorded_retroactively')->default(false);
            $table->string('note', 255)->nullable();
            $table->timestamp('ended_at')->nullable();
            // deployed | returned | archived
            $table->string('end_reason', 30)->nullable();
            $table->foreignId('deployment_id')->nullable()->constrained('deployments');
            $table->timestamps();

            $table->index(['applicant_id', 'ended_at'], 'idx_endorsements_applicant_open');
            $table->index('job_request_id', 'idx_endorsements_request');
        });

        Schema::create('placement_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants');
            // Null only for answers given before endorsements were recorded.
            $table->foreignId('endorsement_id')->nullable()->constrained('endorsements');
            // accepted | declined
            $table->string('decision', 20);
            $table->string('note', 255)->nullable();
            // applicant (their own portal) | staff (recorded for them, or a correction)
            $table->string('source', 20);
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->unsignedBigInteger('corrects_decision_id')->nullable();
            $table->string('correction_reason', 255)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('corrects_decision_id', 'fk_placement_decisions_corrects')
                ->references('id')->on('placement_decisions');
            $table->index(['endorsement_id', 'id'], 'idx_placement_decisions_endorsement');
            $table->index('applicant_id', 'idx_placement_decisions_applicant');
        });

        // Keep the new tables out of Supabase's public Data API, like the rest.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table endorsements enable row level security');
            DB::statement('alter table placement_decisions enable row level security');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_decisions');
        Schema::dropIfExists('endorsements');
    }
};
