<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may review attendance discrepancies, on databases seeded before it
 * existed.
 *
 * Reviewing is held apart from encoding: the person who types or imports a DTR
 * need not be the one who decides that a flagged day was real. A fresh install
 * receives this from RolesAndPermissionsSeeder. The existing manage permission
 * keeps its name and only has its description brought up to date, since it now
 * also covers imports and leave or holidays. The coordinator role is untouched.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'attendance.manage' => ['manage', 'Set work schedules, encode or import daily time records, and record leave and holidays'],
        'attendance.review' => ['review', 'Review attendance discrepancies and link a verified one to a violation'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name => [$action, $description]) {
            Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['module_key' => 'attendance', 'action_key' => $action, 'description' => $description]
            );
        }

        foreach (['admin', 'hr'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()
                ?->givePermissionTo('attendance.review');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'attendance.review')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
