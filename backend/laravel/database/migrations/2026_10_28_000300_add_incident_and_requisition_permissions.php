<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may read and act on coordinators' incident reports and requisitions,
 * on databases that were seeded before they existed.
 *
 * A fresh install receives these from RolesAndPermissionsSeeder. Only the new
 * permissions are touched. The coordinator role is deliberately left with its
 * single permission: coordinators reach their own reports and requisitions
 * through the coordinator routes, which scope by assignment.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'incident_reports.view' => ['incident_reports', 'view', "View coordinators' incident reports"],
        'incident_reports.review' => ['incident_reports', 'review', 'Add management notes to incident reports and mark them pending or resolved'],
        'requisitions.view' => ['requisitions', 'view', "View coordinators' manpower requisitions"],
        'requisitions.review' => ['requisitions', 'review', 'Review, return, approve, reject, or mark fulfilled a manpower requisition'],
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
