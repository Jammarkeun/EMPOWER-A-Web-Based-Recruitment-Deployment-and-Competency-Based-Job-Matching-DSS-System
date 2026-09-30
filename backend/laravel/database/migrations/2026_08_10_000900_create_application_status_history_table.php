<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only record of every applicant status change. This is what makes the
 * applicant timeline reconstructable and gives HR an answer to "who moved this
 * applicant to Ready for Deployment, and when".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained('applicants')->cascadeOnDelete();
            $table->string('from_status', 60)->nullable();
            $table->string('to_status', 60);
            $table->string('reason', 255)->nullable();
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->index('applicant_id', 'idx_app_status_history_applicant');
            $table->index('to_status', 'idx_app_status_history_to_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_status_history');
    }
};
