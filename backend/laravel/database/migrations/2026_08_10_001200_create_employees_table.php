<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee is an applicant who has been deployed, not a separate person.
 *
 * The one-to-one link back to applicants is deliberate: it preserves the entire
 * recruitment history - requirements, training, and the competency evaluation
 * that led to the hire - as part of the employee's permanent record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->unique()->constrained('applicants');
            $table->string('employee_number', 60)->unique();
            $table->string('biometric_number', 60)->nullable();

            // Denormalised "where are they now" pointers. The authoritative
            // assignment history lives in deployments and deployment_history;
            // these exist so employee lists do not need a subquery per row.
            $table->foreignId('current_client_company_id')->nullable()->constrained('client_companies');
            $table->foreignId('current_department_id')->nullable()->constrained('client_departments');
            $table->string('current_position_title', 150)->nullable();
            $table->string('current_supervisor_name', 190)->nullable();

            $table->date('hire_date');

            // active | resigned | terminated | archived
            $table->string('employment_status', 40)->default('active');
            $table->text('profile_notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('employment_status', 'idx_employees_status');
            $table->index('current_client_company_id', 'idx_employees_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
