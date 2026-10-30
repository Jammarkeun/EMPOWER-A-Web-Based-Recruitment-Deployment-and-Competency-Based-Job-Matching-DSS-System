<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The applicant's own profile picture.
 *
 * Only the storage key is kept here; the image itself lives on the documents
 * disk under its own prefix, like every other uploaded file. Each upload gets a
 * fresh random key, so the key doubles as the picture's version: a new picture
 * is a new URL, and nothing cached against the old one can show it.
 *
 * Deliberately separate from the "2x2 ID Picture" requirement. That is an
 * employment document reviewed and verified by HR; this is how the person
 * appears on screen, and the applicant may change it whenever they like.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('profile_photo_path', 255)->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn('profile_photo_path');
        });
    }
};
