<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->string('applicant_code', 50)->unique();

            // walk_in | messenger | email. Every channel still ends with the
            // applicant visiting the office to submit documents, so this records
            // first contact only.
            $table->string('source_channel', 40);

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();
            $table->string('sex', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('civil_status', 30)->nullable();
            $table->string('nationality', 80)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();

            $table->string('contact_number', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('present_address', 255);
            $table->string('provincial_address', 255)->nullable();

            $table->string('preferred_position', 150)->nullable();
            $table->date('availability_date')->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();

            // HR-assigned interview ratings on a 0..5 scale, used by the
            // communication and reliability scoring criteria.
            $table->decimal('communication_rating', 5, 2)->nullable();
            $table->decimal('reliability_rating', 5, 2)->nullable();

            $table->date('application_date');
            $table->string('current_status', 60)->default('applied');

            // Derived from document completeness, never set by hand. Mirrors the
            // agency's physical folders: folder_3 holds resume-only applicants,
            // folder_2 those with complete primary requirements, and folder_1
            // those cleared for deployment.
            $table->string('folder_category', 20)->default('folder_3');

            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('current_status', 'idx_applicants_status');
            $table->index('folder_category', 'idx_applicants_folder');
            $table->index(['last_name', 'first_name'], 'idx_applicants_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicants');
    }
};
