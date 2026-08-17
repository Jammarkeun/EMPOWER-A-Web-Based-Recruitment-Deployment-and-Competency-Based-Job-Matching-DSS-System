<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
            $table->string('status', 30)->default('active'); // active | inactive
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status', 'idx_client_companies_status');
            $table->index('company_name', 'idx_client_companies_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_companies');
    }
};
