<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A coordinator's manpower requisition: the client asking CDE for people.
 *
 * Asked for by CDE on 26 October 2026, alongside the violation and incident
 * reports. The agency described its paper form - when it was requested, when
 * the people are needed, how many, how many women and how many men - and
 * promised a photo of the blank. That form has not arrived, so the columns
 * below are a PROVISIONAL default. `form_version` records which version of
 * the form each requisition was written on, so when the official form
 * replaces this one the older records can be read, and mapped, for what they
 * are instead of being silently reinterpreted.
 *
 * A requisition is not a manpower request. HR reviews it, and an approved one
 * is recorded as a manpower request (job_requests) through the ordinary
 * screen, which links the two. Submission alone approves nothing.
 *
 * The company is stamped from the coordinator's assignment, never taken from
 * the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manpower_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('requisition_code', 50)->unique();
            $table->string('form_version', 40);

            $table->foreignId('client_company_id')->constrained('client_companies');
            $table->foreignId('client_department_id')->nullable()->constrained('client_departments');
            $table->foreignId('job_position_id')->nullable()->constrained('job_positions');
            // The wording on the requisition, kept even if the position is
            // later renamed.
            $table->string('position_title', 150);
            $table->unsignedInteger('workers_needed');

            $table->date('date_requested');
            $table->date('required_by');
            $table->text('reason');

            // Provisional optional fields; see config('empower.requisition_form').
            $table->string('gender_requirement', 20)->nullable();
            $table->unsignedInteger('male_count')->nullable();
            $table->unsignedInteger('female_count')->nullable();
            $table->string('employment_type', 30)->nullable();
            $table->string('work_location', 190)->nullable();
            $table->text('qualifications')->nullable();
            $table->string('work_schedule', 190)->nullable();
            $table->text('compensation_notes')->nullable();
            $table->text('additional_instructions')->nullable();
            $table->string('client_requester_name', 150)->nullable();

            $table->string('attachment_path', 255)->nullable();
            $table->string('attachment_name', 255)->nullable();

            // draft | submitted | under_review | returned | approved | rejected | fulfilled
            $table->string('status', 30)->default('draft');
            $table->foreignId('requested_by')->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            // The manpower request HR recorded from it, once approved.
            $table->foreignId('job_request_id')->nullable()->constrained('job_requests');
            $table->timestamps();

            $table->index(['client_company_id', 'status'], 'idx_requisitions_company_status');
            $table->index('status', 'idx_requisitions_status');
        });

        /*
         * Every status change, with who, when and the note given - the same
         * shape as job_request_status_history. Written only by
         * ManpowerRequisitionService, never edited.
         */
        Schema::create('manpower_requisition_status_history', function (Blueprint $table) {
            $table->id();
            // Named by hand: the generated name is 67 characters, past MySQL's
            // 64 and PostgreSQL's 63.
            $table->foreignId('manpower_requisition_id')
                ->constrained('manpower_requisitions', indexName: 'fk_requisition_history_requisition')
                ->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('note', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->index(['manpower_requisition_id', 'changed_at'], 'idx_requisition_history_requisition');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table manpower_requisitions enable row level security');
            DB::statement('alter table manpower_requisition_status_history enable row level security');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manpower_requisition_status_history');
        Schema::dropIfExists('manpower_requisitions');
    }
};
