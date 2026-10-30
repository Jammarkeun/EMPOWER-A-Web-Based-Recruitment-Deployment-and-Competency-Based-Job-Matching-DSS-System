<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passwords the office sets are temporary (audit SEC-07, FN-03; decided
 * 2026-10-19).
 *
 * A password an administrator issued - on a reset, on portal access, on a new
 * staff account - is known to the office. Until the account holder replaces it
 * with their own, the API answers that account only to let them do so.
 *
 * Existing accounts default to false: nobody is locked into a password change
 * by the migration. Rollback drops the column and the requirement with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
