<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily time records, and the schedule they are compared against.
 *
 * The review panel asked for the DTR to be compared with the schedule. Deployed
 * workers clock in on the client's own biometric system; the agency receives
 * the resulting DTR and has, until now, had nowhere to put it beside what the
 * worker was meant to work. HR encodes both here, and the comparison is worked
 * out from them on demand rather than stored, so correcting either one
 * corrects the comparison.
 *
 * This is attendance, not payroll. Nothing here holds a rate, an amount, or a
 * deduction, and nothing is computed from it but minutes against a schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What an employee is meant to work, and from when.
         *
         * A schedule changes - a move to the night shift, a new rest day - and
         * the comparison for last month must still use last month's schedule,
         * so a change closes the old row and opens a new one instead of
         * overwriting it.
         */
        Schema::create('employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('effective_from');
            // Null while it is the schedule in force.
            $table->date('effective_to')->nullable();

            // ISO weekday numbers, 1 = Monday to 7 = Sunday. Days not listed
            // are rest days.
            $table->json('work_days');

            // A shift that ends at or before it starts runs past midnight, so a
            // 22:00 to 06:00 night shift needs no separate flag.
            $table->time('shift_start');
            $table->time('shift_end');

            $table->string('remarks', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['employee_id', 'effective_from'], 'idx_employee_schedules_employee_from');
        });

        /*
         * One day's time-in and time-out, as the client's DTR records it.
         *
         * Full timestamps rather than times of day, so a time-out after
         * midnight belongs unambiguously to the shift that started the evening
         * before. Either may be missing - a forgotten punch is exactly the kind
         * of thing the comparison exists to surface.
         */
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('work_date');
            $table->dateTime('time_in')->nullable();
            $table->dateTime('time_out')->nullable();
            $table->string('remarks', 255)->nullable();

            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['employee_id', 'work_date'], 'uq_attendance_employee_date');
            $table->index('work_date', 'idx_attendance_work_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('employee_schedules');
    }
};
