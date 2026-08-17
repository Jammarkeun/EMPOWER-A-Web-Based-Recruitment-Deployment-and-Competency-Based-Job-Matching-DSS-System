<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_code', 50)->unique();

            $table->foreignId('client_company_id')->constrained('client_companies');
            $table->foreignId('client_department_id')->constrained('client_departments');

            $table->string('position_title', 150);
            $table->string('required_education', 150)->nullable();
            $table->unsignedInteger('required_experience_months')->nullable();
            $table->text('required_certifications')->nullable();
            $table->string('gender_preference', 20)->nullable();
            $table->unsignedSmallInteger('age_min')->nullable();
            $table->unsignedSmallInteger('age_max')->nullable();
            $table->decimal('height_min_cm', 5, 2)->nullable();
            $table->text('physical_requirement')->nullable();
            $table->string('availability_requirement', 120)->nullable();

            $table->unsignedInteger('workers_needed');
            $table->unsignedInteger('workers_fulfilled')->default(0);

            $table->date('date_requested');
            $table->date('deployment_deadline')->nullable();

            // open | in_progress | partially_fulfilled | fulfilled | closed | cancelled
            $table->string('request_status', 40)->default('open');
            $table->string('request_source', 80)->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('client_company_id', 'idx_job_requests_company');
            $table->index('client_department_id', 'idx_job_requests_department');
            $table->index('request_status', 'idx_job_requests_status');
            $table->index('deployment_deadline', 'idx_job_requests_deadline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_requests');
    }
};
