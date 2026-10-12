<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The coordinator role and the report-review permissions, on existing databases.
 *
 * A fresh install receives these from RolesAndPermissionsSeeder. An existing
 * one has already been seeded, so without this the role would not exist and HR
 * could not review reports until somebody re-ran the seeder. Only the new role
 * and permissions are touched.
 *
 * The coordinator role holds exactly one permission. Every staff screen and
 * endpoint is gated by a permission the coordinator does not have, so the role
 * is shut out of HR and administration by construction, and reaches only the
 * coordinator routes, which scope everything to the assigned client.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'coordination.access' => ['coordination', 'access', "Use the coordinator workspace for the assigned client company"],
        'violation_reports.view' => ['violation_reports', 'view', "View coordinators' violation reports"],
        'violation_reports.review' => ['violation_reports', 'review', 'Review, return, validate, or dismiss violation reports'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name => [$module, $action, $description]) {
            Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['module_key' => $module, 'action_key' => $action, 'description' => $description]
            );
        }

        $coordinator = Role::updateOrCreate(
            ['name' => 'coordinator', 'guard_name' => 'web'],
            [
                'description' => 'CDE coordinator assigned to one client company: monitors deployed workers there and reports incidents to HR.',
                'is_system_role' => true,
            ]
        );
        $coordinator->syncPermissions(['coordination.access']);

        foreach (['admin', 'hr'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()
                ?->givePermissionTo(['violation_reports.view', 'violation_reports.review']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'coordinator')->where('guard_name', 'web')->delete();
        Permission::whereIn('name', array_keys(self::PERMISSIONS))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
