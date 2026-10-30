<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every folder recalculation (audit R-17).
 *
 * An applicant's folder is stored, and it used to be recalculated only when
 * one of their own documents changed. Making a document required in Settings,
 * or a verified clearance expiring overnight, left every stored folder - and
 * every dashboard count built on them - out of date. Recalculations now run
 * after such changes, and each run is recorded here: what triggered it, how
 * many applicants it checked and moved, and whether it finished. A run that
 * failed is visible, and can be repeated with `empower:recalculate-folders`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_recalculations', function (Blueprint $table) {
            $table->id();
            // requirement_change | document_expiry | manual
            $table->string('trigger', 30);
            $table->foreignId('requirement_type_id')->nullable()->constrained('requirement_types');
            $table->string('reason', 255)->nullable();
            // queued | running | completed | failed
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('applicants_checked')->default(0);
            $table->unsignedInteger('applicants_changed')->default(0);
            // Counts per move, e.g. {"folder_1>folder_2": 3}; no applicant data.
            $table->json('summary')->nullable();
            $table->string('error', 255)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_folder_recalculations_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table folder_recalculations enable row level security');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_recalculations');
    }
};
