<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who acted in what capacity, and for which client.
 *
 * The audit trail recorded the user but not their role, and nothing tied an
 * entry to a client company. With coordinators acting for a single client, both
 * matter: "what did the Best Tiwi coordinator do this month" has to be
 * answerable from the log itself, and stay answerable after the coordinator is
 * reassigned. The role is copied at the time, for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor_role', 40)->nullable()->after('actor_user_id');
            $table->foreignId('client_company_id')->nullable()->after('record_id')
                ->constrained('client_companies')->nullOnDelete();

            $table->index('client_company_id', 'idx_audit_logs_company');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('idx_audit_logs_company');
            $table->dropConstrainedForeignId('client_company_id');
            $table->dropColumn('actor_role');
        });
    }
};
