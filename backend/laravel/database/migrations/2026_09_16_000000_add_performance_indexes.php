<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->index(['current_status', 'application_date'], 'applicants_status_date_index');
            $table->index('folder_category', 'applicants_folder_index');
            $table->index('source_channel', 'applicants_source_index');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->index(['employment_status', 'hire_date'], 'employees_status_hire_index');
            $table->index('current_client_company_id', 'employees_company_index');
            $table->index('current_department_id', 'employees_department_index');
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->index(['deployment_status', 'deployment_date'], 'deployments_status_date_index');
            $table->index(['client_company_id', 'client_department_id'], 'deployments_company_department_index');
        });

        Schema::table('employee_violations', function (Blueprint $table) {
            $table->index(['violation_type', 'status', 'violation_date'], 'violations_filter_index');
        });

        Schema::table('job_requests', function (Blueprint $table) {
            $table->index(['request_status', 'deployment_deadline'], 'requests_status_deadline_index');
            $table->index(['client_company_id', 'client_department_id'], 'requests_company_department_index');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', fn (Blueprint $table) => $table->dropIndex('applicants_status_date_index'));
        Schema::table('applicants', fn (Blueprint $table) => $table->dropIndex('applicants_folder_index'));
        Schema::table('applicants', fn (Blueprint $table) => $table->dropIndex('applicants_source_index'));
        Schema::table('employees', fn (Blueprint $table) => $table->dropIndex('employees_status_hire_index'));
        Schema::table('employees', fn (Blueprint $table) => $table->dropIndex('employees_company_index'));
        Schema::table('employees', fn (Blueprint $table) => $table->dropIndex('employees_department_index'));
        Schema::table('deployments', fn (Blueprint $table) => $table->dropIndex('deployments_status_date_index'));
        Schema::table('deployments', fn (Blueprint $table) => $table->dropIndex('deployments_company_department_index'));
        Schema::table('employee_violations', fn (Blueprint $table) => $table->dropIndex('violations_filter_index'));
        Schema::table('job_requests', fn (Blueprint $table) => $table->dropIndex('requests_status_deadline_index'));
        Schema::table('job_requests', fn (Blueprint $table) => $table->dropIndex('requests_company_department_index'));
    }
};