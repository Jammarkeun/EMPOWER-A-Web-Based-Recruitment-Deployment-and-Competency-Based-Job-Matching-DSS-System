<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records how a requirement was checked, not just that it was.
 *
 * Not every applicant uploads a file. Most walk into the office and hand over
 * originals across the counter, and an officer checks them there. Until now the
 * system refused to record that: verification required a stored file, so a
 * walk-in applicant whose papers had all been seen and approved still appeared
 * incomplete, and the only way to fix it was for staff to scan documents purely
 * to satisfy the software.
 *
 * Separating the two ideas is what fixes it. Whether a file exists is already
 * answered by file_path; this column answers the different question of how the
 * check was performed. A requirement can now be verified with no file at all,
 * and the record still says who checked it, when, and by what means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_requirements', function (Blueprint $table) {
            // online_upload | walk_in | other. Null until something is verified,
            // which keeps the existing rows honest rather than guessing at how
            // they were checked before this column existed.
            $table->string('verification_method', 30)->nullable()->after('verified_by');

            // Free text for the unusual case: "original sighted, photocopy on
            // file", "confirmed with the issuing office by phone".
            $table->string('verification_note', 255)->nullable()->after('verification_method');
        });
    }

    public function down(): void
    {
        Schema::table('applicant_requirements', function (Blueprint $table) {
            $table->dropColumn(['verification_method', 'verification_note']);
        });
    }
};
