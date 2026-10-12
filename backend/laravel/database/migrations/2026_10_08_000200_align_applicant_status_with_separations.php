<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brings the applicant record into line for anyone who has already left.
 *
 * Completing a resignation or finalising a termination used to change only the
 * employee record. The applicant record behind it stayed "active", so the
 * applicant list showed former workers as employed and the portal told a
 * dismissed employee "You are currently deployed". The separation service now
 * moves both together; this repairs the records separated before it did.
 *
 * Each repair is written to the status history with the same actor, time, and
 * reason as the employee's own separation entry, so the history reads as though
 * it had been recorded correctly at the time rather than inventing a new event.
 * Query builder only, so it runs unchanged on MySQL, PostgreSQL, and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $separated = DB::table('employees')
            ->join('applicants', 'applicants.id', '=', 'employees.applicant_id')
            ->whereIn('employees.employment_status', ['resigned', 'terminated'])
            ->whereIn('applicants.current_status', ['deployed', 'active'])
            ->select([
                'employees.id as employee_id',
                'employees.employment_status',
                'applicants.id as applicant_id',
                'applicants.current_status',
            ])
            ->get();

        foreach ($separated as $row) {
            $entry = DB::table('employee_status_history')
                ->where('employee_id', $row->employee_id)
                ->where('to_status', $row->employment_status)
                ->orderByDesc('changed_at')
                ->first();

            // With no separation entry there is nobody to attribute the change
            // to, and inventing an actor would falsify the history. Left as it
            // is for a person to look at.
            if (! $entry) {
                continue;
            }

            DB::transaction(function () use ($row, $entry) {
                DB::table('applicants')
                    ->where('id', $row->applicant_id)
                    ->update(['current_status' => $row->employment_status]);

                DB::table('application_status_history')->insert([
                    'applicant_id' => $row->applicant_id,
                    'from_status' => $row->current_status,
                    'to_status' => $row->employment_status,
                    'reason' => $entry->reason,
                    'changed_by' => $entry->changed_by,
                    'changed_at' => $entry->changed_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        // A data repair. Reversing it would put the inconsistency back.
    }
};
