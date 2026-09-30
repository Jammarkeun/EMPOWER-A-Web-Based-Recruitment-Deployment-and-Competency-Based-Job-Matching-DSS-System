<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which kinds of notification a person wants to receive.
 *
 * Stored as JSON on the user rather than as a table of rows. There is one
 * record per user, it is read whenever a notification is about to be sent, and
 * the categories are a short fixed list — a join table would add a query to
 * every send and buy nothing.
 *
 * Null means "everything", which is deliberately different from an empty
 * object: a user who has never opened the settings screen should receive
 * everything, and distinguishing "not configured" from "configured to nothing"
 * is what makes that possible without writing a row for every account that
 * exists.
 *
 * Muting only suppresses the in-app alert. It does not change what happens to
 * the underlying record, and nothing in the recruitment process depends on a
 * notification having been delivered — a document is still verified, a
 * placement is still recorded. That is what makes this safe to offer at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
