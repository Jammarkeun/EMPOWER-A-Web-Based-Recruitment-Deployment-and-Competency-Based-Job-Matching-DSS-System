<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A start (and optional end) time for each training session, and the
 * applicant's own word that they will attend.
 *
 * Sessions used to carry a date only, so the portal had to tell people to ring
 * the office to find out when to arrive. HR now sets the time when scheduling;
 * sessions already on record keep null rather than an invented hour.
 *
 * The confirmation is the applicant's, not attendance: it says they intend to
 * come, and HR still marks who actually did. Nullable timestamp on purpose -
 * XAMPP's MariaDB makes the first NOT NULL timestamp in a table ON UPDATE
 * CURRENT_TIMESTAMP, which would rewrite it on every save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('training_date');
            $table->time('end_time')->nullable()->after('start_time');
        });

        Schema::table('training_enrollments', function (Blueprint $table) {
            $table->timestamp('attendance_confirmed_at')->nullable()->after('attendance_status');
        });
    }

    public function down(): void
    {
        Schema::table('training_enrollments', function (Blueprint $table) {
            $table->dropColumn('attendance_confirmed_at');
        });

        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });
    }
};
