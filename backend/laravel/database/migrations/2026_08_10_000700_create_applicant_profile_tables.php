<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The four repeating sections of an applicant's profile. They are grouped into
 * one migration because they share an identical lifecycle: each is owned by a
 * single applicant and is removed with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();
            $table->string('education_level', 80);
            $table->string('school_name', 190);
            $table->string('course_program', 190)->nullable();
            // PostgreSQL has no YEAR type; a small integer holds the year.
            $table->smallInteger('graduation_year')->nullable();
            $table->string('honors', 120)->nullable();
            $table->timestamps();

            $table->index('applicant_id', 'idx_applicant_educations_applicant');
        });

        Schema::create('applicant_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();
            $table->string('company_name', 190);
            $table->string('position_title', 150);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('months_experience')->nullable();
            $table->text('responsibilities')->nullable();
            $table->timestamps();

            $table->index('applicant_id', 'idx_applicant_experiences_applicant');
        });

        Schema::create('applicant_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();
            $table->string('skill_name', 120);
            $table->string('proficiency_level', 40)->nullable();
            $table->decimal('years_experience', 5, 2)->nullable();
            $table->timestamps();

            $table->index('applicant_id', 'idx_applicant_skills_applicant');
        });

        Schema::create('applicant_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();
            $table->string('certification_name', 150);
            $table->string('cert_number', 80)->nullable();
            $table->string('issuer', 150)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->index('applicant_id', 'idx_applicant_certifications_applicant');
            $table->index('expires_at', 'idx_applicant_certifications_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_certifications');
        Schema::dropIfExists('applicant_skills');
        Schema::dropIfExists('applicant_experiences');
        Schema::dropIfExists('applicant_educations');
    }
};
