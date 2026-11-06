<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The digital Bio-Data form (adviser's recommendation, November 2026).
 *
 * Bio-Data stays one requirement - the `bio_data` row of the applicant's
 * checklist, which folder categorisation already reads. The form is a second
 * way of submitting it, beside the office visit and the old upload, so these
 * tables hold only what a form needs and a checklist row cannot:
 *
 * 1. applicant_bio_data - the applicant's working copy, saved as a draft.
 *    Private to them: a draft is not a submission and changes no status.
 *
 * 2. applicant_bio_data_versions - each submission as it was sent, never
 *    edited afterwards, with what HR decided about it. This is the history
 *    HR reviews and the record a PDF is printed from.
 *
 * 3. applicant_requirements.submitted_via - how the current submission
 *    arrived (portal_upload | digital_form; null for rows that predate this
 *    column). A digital submission has no file, and every "sent in" list -
 *    the review inbox, the dashboard figure, the document library - used to
 *    mean "has a file". This lets them include it without guessing.
 *
 * Why separate tables rather than columns on applicants: the personal
 * details themselves stay where they are (applicants.*), and are only
 * changed there when HR verifies a submission. What is new is a draft that
 * must not touch the profile and a history of submissions, neither of which
 * an applicants row can hold.
 *
 * Nothing existing is changed: no checklist status, no uploaded file, and no
 * verification is touched. Applicants whose Bio-Data is already verified stay
 * verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_bio_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->unique()->constrained('applicants')->cascadeOnDelete();
            $table->json('draft')->nullable();
            $table->timestamp('draft_saved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('applicant_bio_data_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();
            $table->unsignedSmallInteger('version_number');

            // The whole form as submitted, profile-held details included as
            // they stood at that moment, so a version reads the same later.
            $table->json('data');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            // null while waiting for HR | verified | needs_correction
            $table->string('review_outcome', 30)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();

            // The profile fields HR's verification wrote, as [field => [from, to]].
            $table->json('applied_changes')->nullable();
            $table->timestamps();

            $table->unique(['applicant_id', 'version_number'], 'uq_bio_data_version');
        });

        Schema::table('applicant_requirements', function (Blueprint $table) {
            $table->string('submitted_via', 30)->nullable()->after('submitted_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table applicant_bio_data enable row level security');
            DB::statement('alter table applicant_bio_data_versions enable row level security');
        }
    }

    public function down(): void
    {
        Schema::table('applicant_requirements', function (Blueprint $table) {
            $table->dropColumn('submitted_via');
        });

        Schema::dropIfExists('applicant_bio_data_versions');
        Schema::dropIfExists('applicant_bio_data');
    }
};
