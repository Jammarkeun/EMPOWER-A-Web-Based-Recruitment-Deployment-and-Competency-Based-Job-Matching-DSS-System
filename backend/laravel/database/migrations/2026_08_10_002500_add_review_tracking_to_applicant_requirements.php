<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a document was first actually opened by an officer.
 *
 * The portal told applicants their document was "being checked" the instant the
 * upload finished. Nothing was being checked: the file had been stored, and it
 * might sit untouched for a week. The message was a guess dressed as a fact,
 * and the applicant had no way to tell the difference between a document nobody
 * had looked at and one under review.
 *
 * The honest distinction needs a recorded event, not a cleverer label, so this
 * column stores the moment a member of staff opened the file. Until then the
 * document is uploaded and no more than that; afterwards it is genuinely in
 * review, and the applicant is told so because it is true.
 *
 * The applicant opening their own document does not count and never sets this.
 * Only the staff download routes do.
 *
 * Deliberately not another value in the `status` column. Status is the
 * officer's decision - verified, rejected, needs correction - and folder
 * categorisation, reporting, and the lifecycle gates all read it. Whether
 * anyone has looked yet is a different question about the same row, and mixing
 * the two would have made "submitted" mean two things depending on context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicant_requirements', function (Blueprint $table) {
            // First, not last: the answer to "has this entered review?" must not
            // move every time somebody reopens the file.
            $table->timestamp('first_viewed_at')->nullable()->after('submitted_at');
            $table->foreignId('first_viewed_by')->nullable()->after('first_viewed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applicant_requirements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('first_viewed_by');
            $table->dropColumn('first_viewed_at');
        });
    }
};
