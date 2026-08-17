<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->string('deployment_code', 60)->unique();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('job_request_id')->constrained('job_requests');
            $table->foreignId('client_company_id')->constrained('client_companies');
            $table->foreignId('client_department_id')->constrained('client_departments');

            $table->string('position_title', 150);
            $table->string('supervisor_name', 190)->nullable();
            $table->date('deployment_date');
            $table->date('end_date')->nullable();

            // active | completed | transferred | ended
            $table->string('deployment_status', 40)->default('active');
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('employee_id', 'idx_deployments_employee');
            $table->index('client_company_id', 'idx_deployments_company');
            $table->index('deployment_status', 'idx_deployments_status');
        });

        Schema::create('deployment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained('deployments')->cascadeOnDelete();

            $table->foreignId('from_company_id')->nullable()->constrained('client_companies');
            $table->foreignId('from_department_id')->nullable()->constrained('client_departments');
            $table->string('from_position_title', 150)->nullable();

            $table->foreignId('to_company_id')->nullable()->constrained('client_companies');
            $table->foreignId('to_department_id')->nullable()->constrained('client_departments');
            $table->string('to_position_title', 150)->nullable();

            // reassignment | transfer | return
            $table->string('change_type', 40);
            $table->date('effective_date');
            $table->text('remarks')->nullable();

            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();

            $table->index('deployment_id', 'idx_deployment_history_deployment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_history');
        Schema::dropIfExists('deployments');
    }
};
