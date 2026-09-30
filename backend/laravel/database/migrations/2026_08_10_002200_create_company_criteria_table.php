<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client company's own requirements, set once instead of retyped per request.
 *
 * Criteria were previously attached only to an individual manpower request, so
 * every new request for the same client started from nothing and whoever raised
 * it had to remember what that client cares about. Two officers would weight the
 * same position differently, and neither weighting was written down anywhere the
 * other could see.
 *
 * These rows are the company's defaults. Raising a request for that company
 * copies them onto the request, where they can still be adjusted for the
 * specific posting — a client's standing preferences are a starting point, not a
 * rule the request cannot depart from. Nothing here is read by the scoring
 * engine directly; it always scores against the request's own criteria, so a
 * change to a company default never silently rewrites how past requests were
 * evaluated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_company_id')->constrained('client_companies')->cascadeOnDelete();
            $table->foreignId('criteria_id')->constrained('criteria_catalog');

            // Same shape as request_criteria on purpose: copying a company
            // default onto a request is then a straight field-for-field copy
            // rather than a translation that can drift.
            $table->boolean('mandatory_flag')->default(false);
            $table->decimal('weight_score', 7, 2)->default(0);

            $table->string('expected_value', 255)->nullable();
            $table->decimal('min_value', 10, 2)->nullable();
            $table->decimal('max_value', 10, 2)->nullable();
            $table->jsonb('rubric_json')->nullable();

            // Why this client asks for it. Shown to whoever raises the next
            // request, so the reasoning outlives the person who set it.
            $table->string('note', 255)->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['client_company_id', 'criteria_id'], 'uq_company_criterion');
            $table->index('client_company_id', 'idx_company_criteria_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_criteria');
    }
};
