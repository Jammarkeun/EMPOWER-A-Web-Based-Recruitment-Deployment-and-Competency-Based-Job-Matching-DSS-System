<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->string('training_code', 50)->unique();
            $table->string('training_title', 190);
            $table->date('training_date');
            $table->string('location', 190);
            $table->string('trainer_name', 190);

            // scheduled | ongoing | completed | cancelled
            $table->string('status', 30)->default('scheduled');
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('training_date', 'idx_trainings_date');
            $table->index('status', 'idx_trainings_status');
        });

        Schema::create('training_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();

            $table->string('attendance_status', 30)->default('pending');       // pending | present | absent
            $table->string('completion_status', 30)->default('not_completed'); // not_completed | completed
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['training_id', 'applicant_id'], 'uq_training_applicant');
            $table->index('attendance_status', 'idx_training_enrollments_attendance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_enrollments');
        Schema::dropIfExists('trainings');
    }
};
