<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stops ending an assignment from rewriting when it began.
 *
 * On MySQL and MariaDB with explicit_defaults_for_timestamp off - XAMPP's
 * default - the first NOT NULL timestamp column in a table is silently given
 * ON UPDATE CURRENT_TIMESTAMP. coordinator_assignments.assigned_at was that
 * column, so ending an assignment overwrote its start time with the moment it
 * ended, and the history read backwards. Redefining it as nullable removes the
 * implicit clause; the service always sets it.
 *
 * Rows already affected are repaired from created_at, which the service writes
 * in the same statement as assigned_at, so the two only differ where the
 * database rewrote one of them. PostgreSQL never had the problem, and there the
 * repair changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coordinator_assignments', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->change();
        });

        DB::table('coordinator_assignments')
            ->whereNotNull('created_at')
            ->whereColumn('assigned_at', '!=', 'created_at')
            ->update(['assigned_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Restoring the implicit ON UPDATE behaviour would reintroduce the bug.
    }
};
