<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work schedules proposed by a coordinator and approved by HR.
 *
 * The coordinator is the agency's person at the client, and the one told what
 * hours the client has given each worker. They write the schedule down here;
 * HR approves it or returns it with a reason. Only an approved submission
 * becomes an employee_schedules row, which is the only thing the DTR is ever
 * compared with - so a schedule waiting for HR changes no comparison.
 *
 * A submission is never rewritten once approved and never deleted: a later
 * change is a new submission from a later date, and every step is kept in
 * schedule_submission_events with who took it and why.
 *
 * Additive only. Nothing existing is altered, and schedules HR set directly
 * before this table existed stay exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('submission_code', 30)->unique();

            $table->foreignId('employee_id')->constrained('employees');
            // The placement the schedule is for, on the day it takes effect.
            $table->foreignId('deployment_id')->nullable()->constrained('deployments');
            $table->foreignId('client_company_id')->constrained('client_companies');
            $table->foreignId('client_department_id')->nullable()->constrained('client_departments');

            // What the coordinator proposes, in the same shape as a schedule.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('work_days');
            $table->time('shift_start');
            $table->time('shift_end');
            $table->time('break_start')->nullable();
            $table->unsignedSmallInteger('break_minutes')->nullable();
            $table->string('remarks', 255)->nullable();

            // Where the hours came from - the client's memo, the supervisor's
            // message - so HR can check them against something.
            $table->string('source_note', 500)->nullable();

            // draft | submitted | returned | approved | withdrawn
            $table->string('status', 20)->default('draft');

            // The approved schedule this one would take over from, as it stood
            // when last submitted, so HR sees the change rather than a form.
            $table->foreignId('replaces_schedule_id')->nullable()->constrained('employee_schedules');
            // The schedule approval created. Set once, never changed.
            $table->foreignId('employee_schedule_id')->nullable()->unique()->constrained('employee_schedules');

            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'client_company_id'], 'idx_schedule_submissions_status_company');
            $table->index(['employee_id', 'status'], 'idx_schedule_submissions_employee_status');
        });

        Schema::create('schedule_submission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_submission_id')
                ->constrained('schedule_submissions', indexName: 'fk_schedule_events_submission');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('note', 1000)->nullable();
            // On a resubmission, the fields the coordinator changed.
            $table->json('changes')->nullable();
            $table->foreignId('actor_id')->constrained('users');
            $table->timestamps();

            $table->index('schedule_submission_id', 'idx_schedule_events_submission');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table schedule_submissions enable row level security');
            DB::statement('alter table schedule_submission_events enable row level security');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_submission_events');
        Schema::dropIfExists('schedule_submissions');
    }
};
