<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();

            $table->string('email', 190)->unique();
            $table->string('mobile_number', 30)->nullable();
            $table->string('password');

            // admin | hr | applicant | employee. Kept as a string rather than a
            // native enum so that adding a role later is a seeder change rather
            // than a migration against a live table.
            $table->string('user_type', 30)->default('hr');

            // Portal accounts are linked to their applicant or employee record.
            // The foreign keys are added in a later migration because those
            // tables in turn reference users.created_by.
            $table->unsignedBigInteger('applicant_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_type', 'idx_users_user_type');
            $table->index('is_active', 'idx_users_active');
            $table->index('applicant_id', 'idx_users_applicant');
            $table->index('employee_id', 'idx_users_employee');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
