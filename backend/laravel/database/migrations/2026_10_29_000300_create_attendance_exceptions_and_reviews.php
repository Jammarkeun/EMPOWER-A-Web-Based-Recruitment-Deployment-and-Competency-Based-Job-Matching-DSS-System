<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Known exceptions to a schedule, and the review of what the comparison flags.
 *
 * Exceptions are what the agency already knows about a day before the DTR is
 * read: approved leave, a holiday, a day the client's site did not operate.
 * A scheduled day covered by one is not a missing record.
 *
 * A review is a person's look at one flagged day. The comparison only points
 * at a difference; whether it was real, what explains it, and whether it goes
 * any further is decided here, by HR, with every step kept. A verified day may
 * then be recorded as a violation through the existing violation workflow, and
 * the review keeps a link to it. Nothing in either table acts on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_exceptions', function (Blueprint $table) {
            $table->id();

            // One employee, or everybody placed at a client - optionally only
            // one of its departments.
            $table->foreignId('employee_id')->nullable()->constrained('employees');
            $table->foreignId('client_company_id')->nullable()->constrained('client_companies');
            $table->foreignId('client_department_id')->nullable()->constrained('client_departments');

            // approved_leave | holiday | client_non_working_day | other
            $table->string('exception_type', 40);
            $table->date('date_from');
            $table->date('date_to');
            $table->string('remarks', 500)->nullable();

            $table->foreignId('recorded_by')->constrained('users');
            // Withdrawn rather than deleted, so a past comparison can still be
            // explained.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'date_from'], 'idx_attendance_exceptions_employee');
            $table->index(['client_company_id', 'date_from'], 'idx_attendance_exceptions_client');
        });

        Schema::create('attendance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('work_date');

            // Where the worker was placed on the day, so the review still reads
            // correctly after a transfer.
            $table->foreignId('client_company_id')->nullable()->constrained('client_companies');
            $table->foreignId('client_department_id')->nullable()->constrained('client_departments');

            // for_review | under_verification | verified | corrected | closed
            $table->string('status', 30)->default('for_review');

            // What the comparison showed when the review last moved.
            $table->json('discrepancy_types')->nullable();
            $table->json('snapshot')->nullable();
            $table->text('latest_note')->nullable();

            $table->foreignId('employee_violation_id')->nullable()->constrained('employee_violations');
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date'], 'uq_attendance_reviews_employee_date');
            $table->index('status', 'idx_attendance_reviews_status');
        });

        Schema::create('attendance_review_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_review_id')->constrained('attendance_reviews')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('note')->nullable();
            $table->json('snapshot')->nullable();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->timestamps();

            $table->index('attendance_review_id', 'idx_attendance_review_events_review');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_review_events');
        Schema::dropIfExists('attendance_reviews');
        Schema::dropIfExists('attendance_exceptions');
    }
};
