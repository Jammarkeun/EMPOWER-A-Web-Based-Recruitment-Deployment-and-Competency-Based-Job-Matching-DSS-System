<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The applicant's own answer to the offer of work.
 *
 * From the agency's account of its process: after training, the client company
 * assesses the candidate, the agency assesses them - and the candidate assesses
 * the job. Someone who has seen the site, the shift, and the travel decides
 * whether they still want it, and people do say no at that point.
 *
 * The system had no way to record that. Every status in the lifecycle described
 * something the agency or the client had decided, so an applicant who turned the
 * placement down looked identical to one still waiting, and the only record of
 * it was whoever took the phone call. Someone would keep being put forward for
 * work they had already declined.
 *
 * This is deliberately not a lifecycle status. Declining is not a stage of
 * recruitment, it is a fact about one placement that HR then acts on - by
 * returning the applicant to the pool, or archiving the record if they have
 * withdrawn altogether. Keeping it separate leaves that judgment with a person,
 * which is where the agency's process puts it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            // accepted | declined. Null means not asked yet, or asked and not
            // yet answered - which the client evaluation status already implies.
            $table->string('placement_response', 20)->nullable()->after('availability_date');
            $table->timestamp('placement_responded_at')->nullable()->after('placement_response');
            $table->string('placement_response_note', 255)->nullable()->after('placement_responded_at');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn([
                'placement_response',
                'placement_responded_at',
                'placement_response_note',
            ]);
        });
    }
};
