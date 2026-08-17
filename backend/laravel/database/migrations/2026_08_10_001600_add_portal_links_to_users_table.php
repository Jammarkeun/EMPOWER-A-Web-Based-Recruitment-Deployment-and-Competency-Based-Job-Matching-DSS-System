<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a portal login to the applicant or employee record it belongs to.
 *
 * These constraints are added here rather than in the users migration because
 * users is created first: applicants and employees both carry created_by columns
 * that reference users, so the dependency runs in both directions and has to be
 * closed after both sides exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('applicant_id', 'fk_users_applicant')
                ->references('id')->on('applicants')->nullOnDelete();

            $table->foreign('employee_id', 'fk_users_employee')
                ->references('id')->on('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('fk_users_applicant');
            $table->dropForeign('fk_users_employee');
        });
    }
};
