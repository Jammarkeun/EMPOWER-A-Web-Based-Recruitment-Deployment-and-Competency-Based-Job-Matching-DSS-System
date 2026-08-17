<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('criteria_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('criteria_code', 60)->unique();
            $table->string('criteria_name', 120);

            // hard_filter    - a pass/fail gate; failing it disqualifies outright
            // weighted_binary- scored all-or-nothing against an expected value
            // weighted_scale - scored proportionally between min and max
            $table->string('criteria_type', 40);

            // boolean | number | text | enum
            $table->string('value_type', 40);

            // Whether a larger measured value is better. Distance is the reason
            // this exists: an applicant 2 km from the plant should outscore one
            // 40 km away, which is the opposite of how experience or height are
            // graded.
            $table->string('score_direction', 20)->default('higher_better');

            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active', 'idx_criteria_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('criteria_catalog');
    }
};
