<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which client company each CDE coordinator looks after, and since when.
 *
 * A coordinator is an agency employee assigned to one client: the agency's
 * person on site, who receives the client's reports about deployed workers and
 * passes them to HR. Everything a coordinator can see is decided by this row,
 * on the server, so the assignment is the security boundary as well as the
 * business fact.
 *
 * Kept as a history rather than a column on users. Reassigning a coordinator
 * ends the current row and starts a new one, so "who covered Best Tiwi in
 * August" stays answerable after they have moved on, and a report filed under
 * the old assignment still reads correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordinator_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coordinator_user_id')->constrained('users');
            $table->foreignId('client_company_id')->constrained('client_companies');

            // active | ended. At most one active row per coordinator, enforced
            // by the assignment service inside a locked transaction - a partial
            // unique index would not be portable across MySQL and PostgreSQL.
            $table->string('status', 20)->default('active');
            // Nullable, though the service always sets it: on MySQL/MariaDB
            // with explicit_defaults_for_timestamp off, the first NOT NULL
            // timestamp in a table silently becomes ON UPDATE
            // CURRENT_TIMESTAMP, and ending an assignment would rewrite when
            // it began.
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('unassigned_at')->nullable();

            $table->foreignId('assigned_by')->constrained('users');
            $table->foreignId('ended_by')->nullable()->constrained('users');
            $table->string('remarks', 255)->nullable();
            $table->timestamps();

            $table->index(['coordinator_user_id', 'status'], 'idx_coordinator_assignments_user_status');
            $table->index(['client_company_id', 'status'], 'idx_coordinator_assignments_company_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coordinator_assignments');
    }
};
