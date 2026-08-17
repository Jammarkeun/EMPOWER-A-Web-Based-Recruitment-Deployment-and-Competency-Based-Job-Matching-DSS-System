<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per applicant evaluated against one manpower request.
 *
 * This is a decision-support artefact, not a decision. The engine ranks and
 * recommends; a row only becomes a hiring action when HR explicitly shortlists
 * it and later records a deployment. Nothing here deploys anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_request_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_request_id')->constrained('job_requests')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();

            $table->decimal('raw_score', 10, 2);
            $table->decimal('max_score', 10, 2);
            $table->decimal('percentage_score', 6, 2);
            $table->unsignedInteger('rank_order')->nullable();

            // highly_recommended | recommended | reserve_pool | not_recommended
            $table->string('recommendation_level', 40);

            // False when any mandatory criterion failed. Such candidates are kept
            // rather than discarded so HR can see, and justify, why they were
            // excluded.
            $table->boolean('hard_filter_pass')->default(true);

            // Per-criterion trace: weight, awarded score, and a plain-language
            // explanation. This is what makes the recommendation defensible to
            // both the applicant and the client company.
            $table->jsonb('breakdown_json');

            // Set when HR shortlists this candidate for client endorsement.
            $table->boolean('is_shortlisted')->default(false);
            $table->timestamp('shortlisted_at')->nullable();
            $table->foreignId('shortlisted_by')->nullable()->constrained('users');

            $table->foreignId('evaluated_by')->constrained('users');
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->unique(['job_request_id', 'applicant_id'], 'uq_match_request_applicant');
            $table->index(['job_request_id', 'rank_order'], 'idx_matches_request_rank');
            $table->index('percentage_score', 'idx_matches_percentage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_request_matches');
    }
};
