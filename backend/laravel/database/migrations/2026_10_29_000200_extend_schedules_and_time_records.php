<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections to the schedule and the DTR that keep their history.
 *
 * Schedules gain the placement they were set under, a break, and a status, so
 * one entered in error can be voided rather than left in force or deleted.
 * Time records gain their source - typed in, or imported from a named file -
 * and every change to one is written to a revision first. Until now clearing a
 * day deleted its record outright and an edit overwrote it, so the times the
 * comparison had been made from were lost.
 *
 * Additive only: no column is dropped and no row is removed. At the time of
 * writing neither the local database nor Supabase held any schedule or time
 * record, but the backfill below is written for a database that does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_schedules', function (Blueprint $table) {
            // The placement in force when the schedule began. A schedule set at
            // one client does not follow the worker to the next.
            $table->foreignId('deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->foreignId('client_company_id')->nullable()->constrained('client_companies');
            $table->foreignId('client_department_id')->nullable()->constrained('client_departments');

            // Shown beside the schedule; nothing is deducted for it, because
            // CDE has not said how a break is treated.
            $table->time('break_start')->nullable();
            $table->unsignedSmallInteger('break_minutes')->nullable();

            // active | voided
            $table->string('status', 20)->default('active');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason', 500)->nullable();
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            // manual | import
            $table->string('source', 20)->default('manual');
            $table->foreignId('attendance_import_id')->nullable()->constrained('attendance_imports');
            $table->unsignedInteger('source_row')->nullable();
        });

        Schema::create('attendance_record_revisions', function (Blueprint $table) {
            $table->id();

            // By employee and day rather than only by record, because clearing
            // a day removes the record and its history must outlive it.
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('work_date');
            $table->foreignId('attendance_record_id')->nullable()->constrained('attendance_records')->nullOnDelete();

            // created | updated | cleared
            $table->string('action', 20);
            $table->dateTime('old_time_in')->nullable();
            $table->dateTime('old_time_out')->nullable();
            $table->string('old_remarks', 255)->nullable();
            $table->string('old_source', 20)->nullable();
            $table->dateTime('new_time_in')->nullable();
            $table->dateTime('new_time_out')->nullable();
            $table->string('new_remarks', 255)->nullable();
            $table->string('new_source', 20)->nullable();

            $table->string('reason', 500)->nullable();
            $table->foreignId('attendance_import_id')->nullable()->constrained('attendance_imports');
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();

            $table->index(['employee_id', 'work_date'], 'idx_attendance_revisions_employee_date');
        });

        $this->linkSchedulesToPlacements();
    }

    /**
     * Stamps any existing schedule with the placement in force on its first day.
     */
    private function linkSchedulesToPlacements(): void
    {
        DB::table('employee_schedules')->whereNull('deployment_id')->orderBy('id')->get()
            ->each(function ($schedule) {
                $deployment = DB::table('deployments')
                    ->where('employee_id', $schedule->employee_id)
                    ->whereDate('deployment_date', '<=', $schedule->effective_from)
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $schedule->effective_from))
                    ->orderByDesc('deployment_date')
                    ->first();

                if ($deployment) {
                    DB::table('employee_schedules')->where('id', $schedule->id)->update([
                        'deployment_id' => $deployment->id,
                        'client_company_id' => $deployment->client_company_id,
                        'client_department_id' => $deployment->client_department_id,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_record_revisions');

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_import_id');
            $table->dropColumn(['source', 'source_row']);
        });

        Schema::table('employee_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deployment_id');
            $table->dropConstrainedForeignId('client_company_id');
            $table->dropConstrainedForeignId('client_department_id');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['break_start', 'break_minutes', 'status', 'voided_at', 'void_reason']);
        });
    }
};
