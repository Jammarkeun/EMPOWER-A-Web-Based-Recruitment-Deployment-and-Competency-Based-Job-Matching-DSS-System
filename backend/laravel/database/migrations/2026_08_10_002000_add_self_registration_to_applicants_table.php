<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets applicants register themselves online.
 *
 * The agency's process requires every applicant to visit the office in person to
 * submit documents, so an online registration cannot be treated the same as one
 * an HR officer typed in at the counter: nobody has seen the person or checked a
 * single ID.
 *
 * These columns keep the two apart. A self-registered applicant exists, can sign
 * in and see what to bring, but is held before screening until an HR officer
 * confirms their identity in person. That preserves the office visit the agency
 * relies on while removing the phone calls asking "what do I need to bring?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            // Distinguishes an online sign-up from a record created at the
            // counter, which has already been checked against documents.
            $table->timestamp('self_registered_at')->nullable()->after('source_channel');

            // Set when an HR officer confirms the person matches their ID.
            $table->timestamp('identity_verified_at')->nullable()->after('self_registered_at');
            $table->foreignId('identity_verified_by')->nullable()->after('identity_verified_at')
                ->constrained('users')->nullOnDelete();

            // Lets HR pull up the queue of people who have registered online and
            // not yet come in.
            $table->index('self_registered_at', 'idx_applicants_self_registered');
        });

        /*
         * created_by becomes nullable.
         *
         * It was required because every applicant was typed in by a staff
         * member. A self-registered applicant has no such person, and inventing
         * one — attributing the record to whichever administrator happened to be
         * seeded first — would put a false name in the audit trail.
         */
        Schema::table('applicants', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropIndex('idx_applicants_self_registered');
            $table->dropConstrainedForeignId('identity_verified_by');
            $table->dropColumn(['self_registered_at', 'identity_verified_at']);
        });
    }
};
