<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Which device each sign-in came from, for the administrator to see.
 *
 * CDE, 26 October 2026: the administrator "kita niya lang yung lahat ng
 * device na pumapasok sa system ... para iwas po sa hack" - sees every device
 * that signs in, to guard against intrusion. Every attempt was already in the
 * audit trail with its address and browser; the live session (the Sanctum
 * token) did not say where it was signed in from, so "who is signed in right
 * now, and on what" could not be answered. The token's secret is never
 * stored or shown - only its hash exists, as before.
 *
 * Two administrator-only permissions go with it: seeing sessions and sign-in
 * history, and signing a device out.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'sessions.view' => ['sessions', 'view', 'See who is signed in, from which device, and the sign-in history'],
        'sessions.revoke' => ['sessions', 'revoke', 'Sign a device out of the system'],
    ];

    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('abilities');
            $table->string('user_agent', 255)->nullable()->after('ip_address');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name => [$module, $action, $description]) {
            Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['module_key' => $module, 'action_key' => $action, 'description' => $description]
            );
        }

        Role::where('name', 'admin')->where('guard_name', 'web')->first()
            ?->givePermissionTo(array_keys(self::PERMISSIONS));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_keys(self::PERMISSIONS))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
