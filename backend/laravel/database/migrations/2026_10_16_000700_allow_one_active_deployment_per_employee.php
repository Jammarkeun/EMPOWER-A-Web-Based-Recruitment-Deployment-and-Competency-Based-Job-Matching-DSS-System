<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database itself refuses a second active deployment for one employee
 * (audit S-04).
 *
 * Deployments are checked and written under row locks, which stops two
 * officers deploying the same person at the same moment. This is the backstop
 * beneath that: whatever path a future change takes, the database will not
 * hold two active placements for one worker.
 *
 * A plain column rather than a partial index, because MariaDB has no partial
 * indexes, and rather than a generated column, because empower:copy-database
 * copies every column between MariaDB and PostgreSQL and a generated column
 * cannot be written to. It holds the employee's id while the deployment is
 * active and is empty otherwise; a unique index allows any number of empty
 * values. Deployment and SeparationService keep it in step.
 *
 * Refuses to run, changing nothing, if an employee already has two active
 * deployments: which one is real is a decision for HR, not for a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conflicts = DB::table('deployments')
            ->where('deployment_status', 'active')
            ->select('employee_id')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($conflicts > 0) {
            throw new RuntimeException(
                "{$conflicts} employee(s) have more than one active deployment. End the superseded "
                .'deployment for each before running this migration. Nothing has been changed.'
            );
        }

        Schema::table('deployments', function (Blueprint $table) {
            $table->unsignedBigInteger('active_employee_id')->nullable();
        });

        DB::table('deployments')
            ->where('deployment_status', 'active')
            ->update(['active_employee_id' => DB::raw('employee_id')]);

        Schema::table('deployments', function (Blueprint $table) {
            $table->unique('active_employee_id', 'uq_deployments_one_active_per_employee');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropUnique('uq_deployments_one_active_per_employee');
            $table->dropColumn('active_employee_id');
        });
    }
};
