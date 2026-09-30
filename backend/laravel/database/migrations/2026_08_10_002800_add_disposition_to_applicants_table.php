<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an application did not go further.
 *
 * The agency named two reasons an applicant does not proceed: their
 * requirements were never completed, or they were not suitable for the work.
 * Until now the only place that could be recorded was the free-text reason on a
 * status change, which meant the same two answers were written a dozen different
 * ways and neither could be counted.
 *
 * A structured reason with an optional note keeps both halves: the category is
 * countable and reportable, and the note carries whatever made this particular
 * case what it was. The note alone was never enough to answer "how many people
 * do we lose to incomplete requirements?", which is the question worth asking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            // incomplete_requirements | not_suitable | other. Null while the
            // application is still live, which is most of them.
            $table->string('disposition_reason', 40)->nullable()->after('current_status');
            $table->string('disposition_note', 255)->nullable()->after('disposition_reason');
            $table->timestamp('disposition_recorded_at')->nullable()->after('disposition_note');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn(['disposition_reason', 'disposition_note', 'disposition_recorded_at']);
        });
    }
};
