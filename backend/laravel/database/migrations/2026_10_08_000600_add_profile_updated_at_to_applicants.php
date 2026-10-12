<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the parts of an applicant that the matching engine scores last changed.
 *
 * Kept apart from updated_at on purpose. A status move or a document review
 * touches updated_at but changes nothing the engine reads, and flagging every
 * ranking as stale whenever somebody is shortlisted would teach HR to ignore
 * the warning. This moves only when education, experience, skills,
 * certifications, or a scored detail (height, distance, availability, the
 * interview ratings, sex, birth date) is edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->timestamp('profile_updated_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn('profile_updated_at');
        });
    }
};
