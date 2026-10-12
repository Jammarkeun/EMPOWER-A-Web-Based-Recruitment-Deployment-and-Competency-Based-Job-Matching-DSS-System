<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants the attendance permissions on databases that already exist.
 *
 * A fresh install receives them from RolesAndPermissionsSeeder like every
 * other permission. An existing one has already been seeded, and without this
 * the Attendance screen would refuse HR until somebody remembered to re-run the
 * seeder. Only the two new permissions are touched; nothing else a role holds
 * is changed.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'attendance.view' => ['view', 'View schedules, daily time records, and the comparison between them'],
        'attendance.manage' => ['manage', 'Set work schedules and encode daily time records'],
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
                ?->givePermissionTo(array_keys(self::PERMISSIONS));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_keys(self::PERMISSIONS))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
