<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_request_id')->constrained('job_requests')->cascadeOnDelete();
            $table->foreignId('criteria_id')->constrained('criteria_catalog');

            $table->boolean('mandatory_flag')->default(false);
            $table->decimal('weight_score', 7, 2)->default(0);

            $table->string('expected_value', 255)->nullable();
            $table->decimal('min_value', 10, 2)->nullable();
            $table->decimal('max_value', 10, 2)->nullable();

            // Maps a discrete value to a 0..1 multiplier, e.g.
            // {"excellent": 1, "good": 0.8, "fair": 0.5, "poor": 0.2}
            $table->jsonb('rubric_json')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['job_request_id', 'criteria_id'], 'uq_request_criterion');
            $table->index('job_request_id', 'idx_request_criteria_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_criteria');
    }
};
