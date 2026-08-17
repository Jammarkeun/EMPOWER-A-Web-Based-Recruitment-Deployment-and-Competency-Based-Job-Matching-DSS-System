# EMPOWER Laravel Migration Blueprint

## Goal
Convert the SQL schema into modular Laravel migration files that align with domain boundaries and dependency order.

## Conventions
- Use Laravel 12 migration classes with anonymous class return style.
- Use unsignedBigInteger for foreign keys.
- Add softDeletes where archive and retention policy requires logical deletion.
- Keep enums as string columns plus validation in FormRequest classes for flexibility.

## Suggested Migration File Order
1. 2026_08_04_000001_create_users_table.php
2. 2026_08_04_000002_create_roles_table.php
3. 2026_08_04_000003_create_permissions_table.php
4. 2026_08_04_000004_create_role_permissions_table.php
5. 2026_08_04_000005_create_user_roles_table.php
6. 2026_08_04_000006_create_client_companies_table.php
7. 2026_08_04_000007_create_client_departments_table.php
8. 2026_08_04_000008_create_job_requests_table.php
9. 2026_08_04_000009_create_criteria_catalog_table.php
10. 2026_08_04_000010_create_request_criteria_table.php
11. 2026_08_04_000011_create_applicants_table.php
12. 2026_08_04_000012_create_applicant_educations_table.php
13. 2026_08_04_000013_create_applicant_experiences_table.php
14. 2026_08_04_000014_create_applicant_skills_table.php
15. 2026_08_04_000015_create_applicant_certifications_table.php
16. 2026_08_04_000016_create_requirement_types_table.php
17. 2026_08_04_000017_create_applicant_requirements_table.php
18. 2026_08_04_000018_create_application_status_history_table.php
19. 2026_08_04_000019_create_trainings_table.php
20. 2026_08_04_000020_create_training_enrollments_table.php
21. 2026_08_04_000021_create_job_request_matches_table.php
22. 2026_08_04_000022_create_employees_table.php
23. 2026_08_04_000023_create_deployments_table.php
24. 2026_08_04_000024_create_deployment_history_table.php
25. 2026_08_04_000025_create_employee_violations_table.php
26. 2026_08_04_000026_create_resignations_table.php
27. 2026_08_04_000027_create_terminations_table.php
28. 2026_08_04_000028_create_employee_status_history_table.php
29. 2026_08_04_000029_create_archives_table.php
30. 2026_08_04_000030_create_audit_logs_table.php
31. 2026_08_04_000031_create_report_exports_table.php

## Domain-Based Foldering for Future Maintenance
- app/Domains/AccessControl
- app/Domains/ClientManagement
- app/Domains/Recruitment
- app/Domains/Matching
- app/Domains/Training
- app/Domains/Deployment
- app/Domains/EmployeeLifecycle
- app/Domains/Reporting
- app/Domains/Audit

## Migration Template Example

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_companies', function (Blueprint $table) {
            $table->id();
            $table->string('company_code', 40)->unique();
            $table->string('company_name', 190);
            $table->string('business_type', 120);
            $table->string('contact_person', 190)->nullable();
            $table->string('contact_number', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('office_address', 255);
            $table->string('status', 30)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('company_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_companies');
    }
};
```

## Seeder Registration Order
1. RolesTableSeeder
2. PermissionsTableSeeder
3. RolePermissionSeeder
4. RequirementTypesSeeder
5. CriteriaCatalogSeeder
6. InitialUsersSeeder
7. ClientCompanySeeder
8. JobRequestSeeder
9. RequestCriteriaSeeder

## Transaction-Sensitive Operations
- Implement service-layer DB transactions for:
1. Requirement verify plus folder category recalculation.
2. Applicant status transition plus history log plus audit entry.
3. Deployment creation plus applicant to employee conversion plus job request fulfillment update.
4. Separation finalization plus employee status update plus archive snapshot creation.

## Recommended Artisan Commands
```bash
php artisan make:migration create_client_companies_table
php artisan make:migration create_job_requests_table
php artisan make:seeder RolesTableSeeder
php artisan make:seeder RequirementTypesSeeder
php artisan migrate
php artisan db:seed
```

## Guardrails
1. Keep payroll-related columns and tables out of the schema.
2. Do not auto-create deployment records from matching results.
3. Validate lifecycle transitions in policies or dedicated transition service.
4. Enforce audit log writes for all high-impact actions.
