<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds descriptive metadata to the tables published by spatie/laravel-permission.
 *
 * The package supplies role and permission names plus the pivot tables, which
 * covers enforcement. What it does not carry is the information the permission
 * matrix screen needs: a human-readable label, and the module a permission
 * belongs to so that roughly a hundred permissions can be grouped into
 * reviewable sections such as "Applicants" or "Deployment" rather than listed as
 * one flat column of slugs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('module_key', 80)->nullable()->after('guard_name');
            $table->string('action_key', 40)->nullable()->after('module_key');
            $table->string('description', 255)->nullable()->after('action_key');

            $table->index('module_key', 'idx_permissions_module');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('guard_name');

            // Guards the two roles the system cannot operate without. Deleting
            // Administrator or HR would leave the agency locked out of its own
            // records, so the API refuses to remove a role flagged here.
            $table->boolean('is_system_role')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['description', 'is_system_role']);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex('idx_permissions_module');
            $table->dropColumn(['module_key', 'action_key', 'description']);
        });
    }
};
