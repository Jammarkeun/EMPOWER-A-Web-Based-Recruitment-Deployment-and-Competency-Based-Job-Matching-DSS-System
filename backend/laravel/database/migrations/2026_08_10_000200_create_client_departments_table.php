<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_company_id')->constrained('client_companies');
            $table->string('department_code', 40);
            $table->string('department_name', 190);
            $table->string('status', 30)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            // A department name is only meaningful within its own company:
            // "Packaging" may exist at several clients at once.
            $table->unique(['client_company_id', 'department_code'], 'uq_client_department_code');
            $table->unique(['client_company_id', 'department_name'], 'uq_client_department_name');
            $table->index('status', 'idx_client_departments_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_departments');
    }
};
