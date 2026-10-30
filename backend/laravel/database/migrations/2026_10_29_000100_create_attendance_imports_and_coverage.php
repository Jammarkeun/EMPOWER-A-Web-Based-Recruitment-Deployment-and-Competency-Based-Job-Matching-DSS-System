<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DTR files received from a client, and which days a DTR is known to cover.
 *
 * The client's biometric system keeps the time records; the agency receives a
 * copy. An import records where each batch came from - which client, which
 * period, which file, who brought it in, and what happened to every row - so a
 * time on file can always be traced back to its source.
 *
 * Coverage answers a different question: has the DTR for these days actually
 * been received? Without it, a scheduled day with nothing on file cannot be
 * told apart from a day whose DTR simply has not arrived, and the old
 * comparison called every such day an absence. A day counts as covered only
 * when an import or HR says the DTR for it is complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_imports', function (Blueprint $table) {
            $table->id();
            $table->string('import_code', 60)->unique();

            // Whose DTR this is, and the days the file was declared to cover.
            $table->foreignId('client_company_id')->constrained('client_companies');
            $table->date('period_from');
            $table->date('period_to');
            $table->string('source_note', 500)->nullable();

            // The file as received. The path is cleared when a draft that was
            // never imported is discarded, since nothing then depends on it.
            $table->string('original_filename', 190);
            $table->string('file_disk', 40);
            $table->string('file_path', 500)->nullable();
            $table->char('file_hash', 64);
            $table->unsignedInteger('file_size_bytes');

            // Which column holds what. Chosen by HR on each import, because
            // CDE has not supplied a sample export to fix a layout from.
            $table->json('column_mapping')->nullable();

            // draft | completed | discarded
            $table->string('status', 20)->default('draft');

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('replaced_rows')->default(0);
            $table->unsignedInteger('unchanged_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->json('results')->nullable();

            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();

            $table->index('file_hash', 'idx_attendance_imports_hash');
            $table->index(['client_company_id', 'period_from'], 'idx_attendance_imports_client_period');
        });

        Schema::create('attendance_coverage_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->date('period_from');
            $table->date('period_to');

            // Null when HR declared a hand-encoded period complete.
            $table->foreignId('attendance_import_id')->nullable()->constrained('attendance_imports');
            $table->string('note', 255)->nullable();
            $table->foreignId('declared_by')->constrained('users');
            $table->timestamps();

            $table->index(['employee_id', 'period_from'], 'idx_attendance_coverage_employee_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_coverage_periods');
        Schema::dropIfExists('attendance_imports');
    }
};
