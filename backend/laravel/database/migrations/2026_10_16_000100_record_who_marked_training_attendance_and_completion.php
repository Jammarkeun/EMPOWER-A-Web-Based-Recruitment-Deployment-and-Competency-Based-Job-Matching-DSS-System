<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who marked a trainee present, and who recorded that they completed (audit R-06).
 *
 * Completed training is what releases an applicant to a client, so the record
 * that says so should name the officer who entered it and when, rather than
 * leaving that only in the audit trail. Existing rows keep nulls: they were
 * entered before this was tracked, and nothing is invented for them.
 *
 * Nullable on purpose - XAMPP's MariaDB makes the first NOT NULL timestamp in a
 * table ON UPDATE CURRENT_TIMESTAMP, which would rewrite these on every save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_enrollments', function (Blueprint $table) {
            $table->foreignId('attendance_recorded_by')->nullable()->after('attendance_status')->constrained('users');
            $table->timestamp('attendance_recorded_at')->nullable()->after('attendance_recorded_by');
            $table->foreignId('completion_recorded_by')->nullable()->after('completion_status')->constrained('users');
            $table->timestamp('completion_recorded_at')->nullable()->after('completion_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('training_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_recorded_by');
            $table->dropColumn('attendance_recorded_at');
            $table->dropConstrainedForeignId('completion_recorded_by');
            $table->dropColumn('completion_recorded_at');
        });
    }
};
