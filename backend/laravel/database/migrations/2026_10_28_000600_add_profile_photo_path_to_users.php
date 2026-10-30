<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff account's own profile picture - administrators, HR, and
 * coordinators, set from Settings.
 *
 * Kept on the account rather than on a person record because staff have none:
 * a portal login's picture stays on its applicant record, where HR sees it
 * beside the application. As there, only the storage key is held here; the
 * image lives on the documents disk, and every upload gets a fresh random key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_photo_path', 255)->nullable()->after('mobile_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_photo_path');
        });
    }
};
