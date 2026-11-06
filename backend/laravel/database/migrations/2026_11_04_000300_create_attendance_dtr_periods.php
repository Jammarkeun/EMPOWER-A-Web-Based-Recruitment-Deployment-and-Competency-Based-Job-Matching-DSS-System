<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DTR periods: whether a client's DTR for a stretch of days has arrived in full.
 *
 * An import used to declare, by itself succeeding, that the DTR was received
 * for every employee who appeared in the file. A client that sent half a
 * cutoff then turned every missing day into "No DTR record". Receiving a file,
 * importing it, and knowing the submission is complete are three different
 * things, so they are now recorded apart:
 *
 * - each upload is filed under a DTR period - one client, one run of days;
 * - the period reads Received (a file is in, none imported), Processing
 *   (imported, not yet checked for completeness), Incomplete Submission (HR
 *   found something missing) or Complete for Period (HR confirmed it);
 * - only Complete for Period makes a scheduled day with nothing on file a gap
 *   to verify. Until then it stays Awaiting DTR.
 *
 * Every move HR makes is kept in attendance_dtr_period_events with a note.
 *
 * Additive only. Imports made before this table existed are filed under a
 * period of their own declared dates, Received or Processing; the coverage
 * they already recorded is left exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_dtr_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_company_id')->constrained('client_companies', indexName: 'fk_dtr_periods_company');
            $table->date('period_from');
            $table->date('period_to');

            // received | processing | incomplete | complete
            $table->string('status', 20)->default('received');

            // HR's last decision on it, and why.
            $table->string('status_note', 1000)->nullable();
            $table->foreignId('status_by')->nullable()->constrained('users', indexName: 'fk_dtr_periods_status_by');
            $table->timestamp('status_at')->nullable();

            $table->foreignId('created_by')->constrained('users', indexName: 'fk_dtr_periods_created_by');
            $table->timestamps();

            // One record per client and run of days, so two people cannot
            // each confirm their own copy of the same period.
            $table->unique(['client_company_id', 'period_from', 'period_to'], 'uq_dtr_periods_company_range');
            $table->index(['status', 'client_company_id'], 'idx_dtr_periods_status_company');
        });

        Schema::create('attendance_dtr_period_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_dtr_period_id')
                ->constrained('attendance_dtr_periods', indexName: 'fk_dtr_period_events_period');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('note', 1000)->nullable();
            // What HR was shown when deciding: who was placed, who had rows.
            $table->json('details')->nullable();
            $table->foreignId('actor_id')->constrained('users', indexName: 'fk_dtr_period_events_actor');
            $table->timestamps();

            $table->index('attendance_dtr_period_id', 'idx_dtr_period_events_period');
        });

        Schema::table('attendance_imports', function (Blueprint $table) {
            $table->foreignId('attendance_dtr_period_id')->nullable()->after('client_company_id')
                ->constrained('attendance_dtr_periods', indexName: 'fk_attendance_imports_dtr_period');
        });

        $this->fileExistingImports();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table attendance_dtr_periods enable row level security');
            DB::statement('alter table attendance_dtr_period_events enable row level security');
        }
    }

    /**
     * Each earlier import under a period of its own declared dates.
     */
    private function fileExistingImports(): void
    {
        $now = now();

        foreach (DB::table('attendance_imports')->orderBy('id')->get() as $import) {
            $periodId = DB::table('attendance_dtr_periods')
                ->where('client_company_id', $import->client_company_id)
                ->whereDate('period_from', $import->period_from)
                ->whereDate('period_to', $import->period_to)
                ->value('id');

            if (! $periodId) {
                $periodId = DB::table('attendance_dtr_periods')->insertGetId([
                    'client_company_id' => $import->client_company_id,
                    'period_from' => $import->period_from,
                    'period_to' => $import->period_to,
                    'status' => 'received',
                    'created_by' => $import->uploaded_by,
                    'created_at' => $import->created_at ?? $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('attendance_imports')->where('id', $import->id)->update(['attendance_dtr_period_id' => $periodId]);

            if ($import->status === 'completed') {
                DB::table('attendance_dtr_periods')->where('id', $periodId)->update(['status' => 'processing']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('attendance_imports', function (Blueprint $table) {
            $table->dropForeign('fk_attendance_imports_dtr_period');
            $table->dropColumn('attendance_dtr_period_id');
        });

        Schema::dropIfExists('attendance_dtr_period_events');
        Schema::dropIfExists('attendance_dtr_periods');
    }
};
