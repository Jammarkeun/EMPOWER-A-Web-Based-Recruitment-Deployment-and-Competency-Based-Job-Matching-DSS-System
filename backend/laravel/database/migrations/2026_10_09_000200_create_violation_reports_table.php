<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coordinator's report of an incident involving a deployed worker.
 *
 * The client reports a problem to the CDE coordinator on site; the coordinator
 * writes it up and submits it; HR reviews it. Only when HR validates it does it
 * become an official violation in employee_violations - the record that counts
 * towards the disciplinary threshold. Keeping the report separate means a draft,
 * or a report HR dismisses, can never be counted against anybody, while the
 * official record still links back to the report it came from.
 *
 * The company and department are stamped from the employee's placement when the
 * report is made, never taken from the request, so a report always belongs to
 * the client the coordinator was assigned to at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('violation_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_code', 50)->unique();

            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('client_company_id')->constrained('client_companies');
            $table->foreignId('client_department_id')->nullable()->constrained('client_departments');
            $table->foreignId('reported_by')->constrained('users');

            // The same vocabulary as employee_violations.violation_type.
            $table->string('violation_type', 60);
            $table->date('incident_date');
            $table->text('details');
            $table->text('notes')->nullable();

            $table->string('evidence_path', 255)->nullable();
            $table->string('evidence_name', 255)->nullable();

            // draft | submitted | under_review | returned | validated | dismissed
            $table->string('status', 30)->default('draft');
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index('status', 'idx_violation_reports_status');
            $table->index(['client_company_id', 'status'], 'idx_violation_reports_company_status');
            $table->index('employee_id', 'idx_violation_reports_employee');
        });

        /*
         * The official record remembers where it came from: the report, the
         * coordinator who reported it, and the client and department the
         * worker was placed in on the day. Nullable, because violations HR
         * records directly - and every violation recorded before this - have
         * no report behind them.
         */
        Schema::table('employee_violations', function (Blueprint $table) {
            $table->foreignId('violation_report_id')->nullable()->after('employee_id')
                ->constrained('violation_reports')->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->after('issued_by')->constrained('users');
            $table->foreignId('client_company_id')->nullable()->after('violation_report_id')->constrained('client_companies');
            $table->foreignId('client_department_id')->nullable()->after('client_company_id')->constrained('client_departments');
        });
    }

    public function down(): void
    {
        Schema::table('employee_violations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('violation_report_id');
            $table->dropConstrainedForeignId('reported_by');
            $table->dropConstrainedForeignId('client_company_id');
            $table->dropConstrainedForeignId('client_department_id');
        });

        Schema::dropIfExists('violation_reports');
    }
};
