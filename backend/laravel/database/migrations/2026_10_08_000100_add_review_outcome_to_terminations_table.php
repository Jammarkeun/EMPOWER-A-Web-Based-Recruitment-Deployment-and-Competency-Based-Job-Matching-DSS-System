<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records how a termination's review ended.
 *
 * A termination is filed for review and an administrator finalises it. Until
 * now that was the only way the review could end, so a case filed in error, or
 * one whose grounds did not hold up, stayed "for review" forever with nothing
 * to close it. Withdrawal is the other outcome, and like finalisation it keeps
 * the record exactly as filed and says who decided, when, and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminations', function (Blueprint $table) {
            $table->timestamp('finalized_at')->nullable()->after('approved_by');
            $table->foreignId('withdrawn_by')->nullable()->after('finalized_at')->constrained('users');
            $table->timestamp('withdrawn_at')->nullable()->after('withdrawn_by');
            $table->string('withdrawal_reason', 255)->nullable()->after('withdrawn_at');
        });
    }

    public function down(): void
    {
        Schema::table('terminations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropColumn(['finalized_at', 'withdrawn_at', 'withdrawal_reason']);
        });
    }
};
