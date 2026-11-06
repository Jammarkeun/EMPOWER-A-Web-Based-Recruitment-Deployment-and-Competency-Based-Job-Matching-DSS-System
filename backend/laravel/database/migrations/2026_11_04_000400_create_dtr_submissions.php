<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DTR files a coordinator hands over to HR.
 *
 * The coordinator is the agency's person at the client and the one the
 * client's DTR reaches first. A submission keeps the file exactly as received,
 * with the client, the period it says it covers, and where it came from. It
 * writes no attendance: HR opens it in the existing DTR importer, which copies
 * the file into an ordinary import draft (attendance_imports.dtr_submission_id)
 * and checks, previews and imports it as it does any file. A returned file is
 * corrected by a new submission that points back to it; nothing is replaced.
 *
 * Additive only: two new tables and one nullable column on attendance_imports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtr_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('submission_code', 30)->unique();
            $table->foreignId('client_company_id')->constrained('client_companies');

            // The days the coordinator says the file covers.
            $table->date('period_from');
            $table->date('period_to');
            $table->string('source_note', 500)->nullable();

            // The file as received, on the private document disk.
            $table->string('original_filename', 255);
            $table->string('file_disk', 30);
            $table->string('file_path', 500);
            $table->string('file_hash', 64);
            $table->unsignedBigInteger('file_size_bytes');
            $table->unsignedInteger('total_rows')->default(0);

            // submitted | under_review | returned | rejected | imported
            $table->string('status', 20)->default('submitted');

            // A corrected file sent after HR returned this one's predecessor.
            $table->foreignId('resubmission_of_id')->nullable()
                ->constrained('dtr_submissions', indexName: 'fk_dtr_submissions_resubmission_of');

            $table->foreignId('submitted_by')->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();

            // The import that took it in, once HR has confirmed one.
            $table->foreignId('attendance_import_id')->nullable()
                ->constrained('attendance_imports', indexName: 'fk_dtr_submissions_import');
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'client_company_id'], 'idx_dtr_submissions_status_company');
            $table->index('file_hash', 'idx_dtr_submissions_hash');
        });

        Schema::create('dtr_submission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dtr_submission_id')
                ->constrained('dtr_submissions', indexName: 'fk_dtr_submission_events_submission');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('note', 1000)->nullable();
            $table->foreignId('attendance_import_id')->nullable()
                ->constrained('attendance_imports', indexName: 'fk_dtr_submission_events_import');
            $table->foreignId('actor_id')->constrained('users');
            $table->timestamps();

            $table->index('dtr_submission_id', 'idx_dtr_submission_events_submission');
        });

        Schema::table('attendance_imports', function (Blueprint $table) {
            // The coordinator's submission this import was opened from, if any.
            $table->foreignId('dtr_submission_id')->nullable()
                ->constrained('dtr_submissions', indexName: 'fk_attendance_imports_dtr_submission');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table dtr_submissions enable row level security');
            DB::statement('alter table dtr_submission_events enable row level security');
        }
    }

    public function down(): void
    {
        Schema::table('attendance_imports', function (Blueprint $table) {
            $table->dropForeign('fk_attendance_imports_dtr_submission');
            $table->dropColumn('dtr_submission_id');
        });

        Schema::dropIfExists('dtr_submission_events');
        Schema::dropIfExists('dtr_submissions');
    }
};
