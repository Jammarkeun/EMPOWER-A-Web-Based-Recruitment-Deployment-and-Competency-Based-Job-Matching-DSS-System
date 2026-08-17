<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirement_types', function (Blueprint $table) {
            $table->id();
            $table->string('requirement_code', 50)->unique();
            $table->string('requirement_name', 150);

            // primary - resume, birth certificate, clearances, and so on
            // final   - the medical battery completed just before deployment
            $table->string('requirement_group', 30);

            $table->boolean('is_required')->default(true);
            $table->boolean('has_expiry')->default(false);
            $table->boolean('active_flag')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index('requirement_group', 'idx_requirement_types_group');
            $table->index('active_flag', 'idx_requirement_types_active');
        });

        Schema::create('applicant_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();
            $table->foreignId('requirement_type_id')->constrained('requirement_types');

            // missing | submitted | pending | verified | rejected | expired
            $table->string('status', 30)->default('missing');

            // Object key within the private Supabase Storage bucket. Files are
            // never served directly; downloads go through a signed URL.
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 190)->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('file_hash', 64)->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->date('expiry_date')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['applicant_id', 'requirement_type_id'], 'uq_applicant_requirement');
            $table->index('status', 'idx_applicant_requirements_status');
            $table->index('expiry_date', 'idx_applicant_requirements_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_requirements');
        Schema::dropIfExists('requirement_types');
    }
};
