<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Defines the permission vocabulary and the two operational roles.
 *
 * Permissions are named module.action so the permission matrix screen can group
 * them into reviewable sections. The Administrator role receives everything; HR
 * receives the day-to-day recruitment work but not user management, permission
 * configuration, or the audit trail, which keeps the person being audited
 * separate from the person who can alter the audit settings.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * module => [action => description]
     */
    private const MODULES = [
        'dashboard' => [
            'view' => 'View the dashboard and its statistics',
        ],
        'clients' => [
            'view' => 'View client companies and departments',
            'create' => 'Register a new client company or department',
            'update' => 'Edit client company or department details',
            'delete' => 'Deactivate a client company or department',
        ],
        'job_requests' => [
            'view' => 'View manpower requests',
            'create' => 'Record a new manpower request',
            'update' => 'Edit a manpower request',
            'configure_criteria' => 'Set the competency criteria and weights for a request',
            'close' => 'Close or cancel a manpower request',
        ],
        'applicants' => [
            'view' => 'View applicant records',
            'create' => 'Register a new applicant',
            'update' => 'Edit applicant details',
            'change_status' => 'Move an applicant through the recruitment lifecycle',
            'delete' => 'Remove an applicant record',
        ],
        'requirements' => [
            'view' => 'View submitted requirements',
            'upload' => 'Upload a requirement document',
            'verify' => 'Verify or reject a submitted requirement',
        ],
        'matching' => [
            'view' => 'View competency rankings',
            'evaluate' => 'Run the competency evaluation for a request',
            'shortlist' => 'Shortlist applicants for client endorsement',
        ],
        'training' => [
            'view' => 'View training schedules',
            'create' => 'Schedule a training session',
            'update' => 'Edit a training session or record attendance',
        ],
        'deployment' => [
            'view' => 'View deployment records',
            'create' => 'Record a deployment and convert an applicant to an employee',
            'reassign' => 'Reassign a deployed employee',
        ],
        'employees' => [
            'view' => 'View employee records',
            'update' => 'Edit employee details',
        ],
        'violations' => [
            'view' => 'View disciplinary records',
            'create' => 'Record a violation',
            'update' => 'Update or resolve a violation',
        ],
        'separation' => [
            'view' => 'View resignations and terminations',
            'create' => 'File a resignation or termination',
            'approve' => 'Complete a resignation and clear the employee',
            // Held separately from 'approve'. Completing a resignation is
            // administrative, but finalising a dismissal carries legal weight
            // and should not be done by the same person who filed it.
            'finalize_termination' => 'Finalise a termination',
        ],
        'archives' => [
            'view' => 'Search archived records',
            'create' => 'Archive a closed record',
        ],
        'reports' => [
            'view' => 'View reports',
            'export' => 'Export reports to PDF or Excel',
        ],
        'users' => [
            'view' => 'View system users',
            'create' => 'Create a system user',
            'update' => 'Edit a system user or reset a password',
            'manage_roles' => 'Assign roles and permissions',
        ],
        'audit' => [
            'view' => 'View the audit trail',
        ],
        'settings' => [
            'view' => 'View system configuration',
            'update' => 'Change competency scoring configuration and reference data',
        ],
    ];

    /**
     * Everything HR needs to run recruitment end to end. Deliberately excludes
     * users.*, audit.view, and settings.update.
     */
    private const HR_PERMISSIONS = [
        'dashboard.view',
        'clients.view', 'clients.create', 'clients.update',
        'job_requests.view', 'job_requests.create', 'job_requests.update', 'job_requests.configure_criteria', 'job_requests.close',
        'applicants.view', 'applicants.create', 'applicants.update', 'applicants.change_status',
        'requirements.view', 'requirements.upload', 'requirements.verify',
        'matching.view', 'matching.evaluate', 'matching.shortlist',
        'training.view', 'training.create', 'training.update',
        'deployment.view', 'deployment.create', 'deployment.reassign',
        'employees.view', 'employees.update',
        'violations.view', 'violations.create', 'violations.update',
        'separation.view', 'separation.create', 'separation.approve',
        'archives.view', 'archives.create',
        'reports.view', 'reports.export',
        'settings.view',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::MODULES as $module => $actions) {
            foreach ($actions as $action => $description) {
                Permission::updateOrCreate(
                    ['name' => "{$module}.{$action}", 'guard_name' => 'web'],
                    [
                        'module_key' => $module,
                        'action_key' => $action,
                        'description' => $description,
                    ]
                );
            }
        }

        $admin = Role::updateOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            [
                'description' => 'Full system access, including user management and the audit trail.',
                'is_system_role' => true,
            ]
        );

        $hr = Role::updateOrCreate(
            ['name' => 'hr', 'guard_name' => 'web'],
            [
                'description' => 'Day-to-day recruitment, deployment, and employee lifecycle work.',
                'is_system_role' => true,
            ]
        );

        $admin->syncPermissions(Permission::all());
        $hr->syncPermissions(Permission::whereIn('name', self::HR_PERMISSIONS)->get());

        // Portal accounts hold no staff permissions at all. Applicants and
        // employees reach only their own record, enforced by policy rather than
        // by permission grants.
        Role::updateOrCreate(
            ['name' => 'portal', 'guard_name' => 'web'],
            [
                'description' => 'Applicant and employee self-service portal access.',
                'is_system_role' => true,
            ]
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
