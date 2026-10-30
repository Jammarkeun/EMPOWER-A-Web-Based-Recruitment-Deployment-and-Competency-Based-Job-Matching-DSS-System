<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per code series - "EMP-2026-", "DEP-2026-" - for ReferenceCodeService
 * to lock while it works out the next number (audit S-04).
 *
 * Codes used to be serialised by locking the latest code already issued. That
 * holds on MariaDB, but on PostgreSQL a transaction that waited for the lock
 * then re-reads only the row it waited on, so it computes the same next number
 * as the one it waited for; and on either database, when no code exists yet
 * for the year, there is nothing to lock at all. Recording two deployments at
 * the same moment failed with a duplicate employee number - reproduced against
 * PostgreSQL 17 by ConcurrentDeploymentTest. A row that always exists can be
 * locked the same way on every database.
 *
 * It holds no numbers: the next number is still read from the codes already
 * issued, so nothing here can drift out of step with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_sequences', function (Blueprint $table) {
            $table->string('scope', 40)->primary();
            $table->timestamp('created_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table reference_sequences enable row level security');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sequences');
    }
};
