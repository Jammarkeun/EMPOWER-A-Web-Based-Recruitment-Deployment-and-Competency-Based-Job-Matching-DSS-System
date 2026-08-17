<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Resolve schema path relative to the database folder to avoid relying on framework helper
        $schemaFile = dirname(__DIR__) . '/schema/empower_schema_mysql.sql';

        if (! file_exists($schemaFile)) {
            throw new RuntimeException("Schema file not found: {$schemaFile}");
        }

        DB::unprepared(file_get_contents($schemaFile));
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        $tables = [
            'report_exports',
            'audit_logs',
            'archives',
            'employee_status_history',
            'terminations',
            'resignations',
            'employee_violations',
            'deployment_history',
            'deployments',
            'employees',
            'job_request_matches',
            'training_enrollments',
            'trainings',
            'application_status_history',
            'applicant_requirements',
            'requirement_types',
            'applicant_certifications',
            'applicant_skills',
            'applicant_experiences',
            'applicant_educations',
            'applicants',
            'request_criteria',
            'criteria_catalog',
            'job_requests',
            'client_departments',
            'client_companies',
            'user_roles',
            'role_permissions',
            'permissions',
            'roles',
            'users',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
};
