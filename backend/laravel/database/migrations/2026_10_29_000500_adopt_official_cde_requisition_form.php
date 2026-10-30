<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CDE's own Manpower Requisition Form replaces the temporary default.
 *
 * Received 29 October 2026 as a photo of a filled-in form. Its fields are:
 * Date requested, Date Needed, Total Manpower Needed, Required
 * Qualification(s), Other Remarks, Reason of Request (New Position /
 * Replacement / Additional Manpower / Reliever Manpower / Others: ____),
 * Requested by and Acknowledged by.
 *
 * Purely additive. Every existing column stays, so requisitions written on the
 * temporary form (form_version "provisional-2026-10") keep exactly what they
 * recorded and are shown as such:
 *
 *   - `request_reason` is the box ticked under "Reason of Request".
 *   - `request_reason_details` is what is written beside it: the blank after
 *     "Others:", or, as on CDE's own sample, whom a replacement is for.
 *   - `other_remarks` is "Other Remarks".
 *   - `reason` was the temporary form's free-text reason. It becomes nullable,
 *     because the official form has no such field; earlier rows keep theirs.
 *
 * "Date requested", "Date Needed", "Total Manpower Needed" and "Required
 * Qualification(s)" reuse date_requested, required_by, workers_needed (with
 * male_count / female_count) and qualifications, which already meant the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manpower_requisitions', function (Blueprint $table) {
            // new_position | replacement | additional_manpower | reliever_manpower | others
            $table->string('request_reason', 30)->nullable()->after('reason');
            $table->string('request_reason_details', 190)->nullable()->after('request_reason');
            $table->text('other_remarks')->nullable()->after('qualifications');
        });

        Schema::table('manpower_requisitions', function (Blueprint $table) {
            $table->text('reason')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A requisition written on the official form has no free-text reason.
        // Give it one from the ticked box before the column is required again,
        // so rolling back loses nothing that was recorded.
        DB::table('manpower_requisitions')
            ->whereNull('reason')
            ->update(['reason' => DB::raw("COALESCE(request_reason, 'Not recorded')")]);

        Schema::table('manpower_requisitions', function (Blueprint $table) {
            $table->text('reason')->nullable(false)->change();
        });

        Schema::table('manpower_requisitions', function (Blueprint $table) {
            $table->dropColumn(['request_reason', 'request_reason_details', 'other_remarks']);
        });
    }
};
