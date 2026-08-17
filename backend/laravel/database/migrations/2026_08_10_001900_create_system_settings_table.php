<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime-editable configuration.
 *
 * Values that HR policy may change — the recommendation bands, how far ahead to
 * warn about expiring documents, the document upload limit — start life in
 * config/empower.php but need to be adjustable without a developer editing a
 * file and redeploying. This table holds the overrides; anything absent falls
 * back to the config default, so the system still runs correctly on a fresh
 * install with this table empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();

            // Mirrors the config path it overrides, e.g. "empower.recommendation_bands".
            $table->string('key', 120)->unique();

            // Stored as JSON so a setting can be a number, a string, or a
            // structure such as the three recommendation bands, without needing
            // a column per shape.
            $table->jsonb('value');

            // Groups settings into the tabs of the settings screen.
            $table->string('group', 60)->default('system');

            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('group', 'idx_system_settings_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
