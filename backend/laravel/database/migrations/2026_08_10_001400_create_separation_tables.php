<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discipline and separation. These records carry legal weight: they justify a
 * termination and are the agency's evidence if a dismissal is ever questioned,
 * which is why nothing here is ever hard-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('violation_date');

            // awol | absences | suspension | late | misconduct | policy_violation
            $table->string('violation_type', 60);
            $table->text('description');
            $table->string('evidence_path', 255)->nullable();

            $table->foreignId('issued_by')->constrained('users');
            $table->text('remarks')->nullable();
            $table->string('penalty', 190)->nullable();

            // open | under_review | resolved | escalated
            $table->string('status', 40)->default('open');
            $table->date('resolution_date')->nullable();
            $table->timestamps();

            $table->index('employee_id', 'idx_employee_violations_employee');
            $table->index('violation_type', 'idx_employee_violations_type');
            $table->index('status', 'idx_employee_violations_status');
        });

        Schema::create('resignations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('resignation_letter_path', 255)->nullable();
            $table->string('reason', 255)->nullable();

            // Philippine labour practice expects 30 days' notice; stored rather
            // than assumed so waived or shortened notice is visible.
            $table->unsignedInteger('rendering_days')->nullable();

            $table->date('filing_date');
            $table->date('exit_date')->nullable();

            $table->string('clearance_status', 40)->default('pending'); // pending | in_progress | cleared
            $table->string('status', 40)->default('filed');             // filed | accepted | completed | withdrawn

            $table->foreignId('processed_by')->nullable()->constrained('users');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('employee_id', 'idx_resignations_employee');
            $table->index('status', 'idx_resignations_status');
        });

        Schema::create('terminations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('reason', 255);
            $table->date('termination_date');
            $table->string('documents_path', 255)->nullable();
            $table->text('remarks')->nullable();

            // draft | for_review | finalized
            $table->string('status', 40)->default('draft');

            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('employee_id', 'idx_terminations_employee');
            $table->index('status', 'idx_terminations_status');
        });

        Schema::create('employee_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('reason', 255)->nullable();
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->index('employee_id', 'idx_employee_status_history_employee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_status_history');
        Schema::dropIfExists('terminations');
        Schema::dropIfExists('resignations');
        Schema::dropIfExists('employee_violations');
    }
};
