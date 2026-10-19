<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generates the human-readable codes HR reads out over the phone, in the form
 * PREFIX-YEAR-SEQUENCE, for example APP-2026-00042.
 *
 * The sequence is derived while its series is locked, so two staff registering
 * applicants at the same counter - or recording deployments at the same moment
 * - cannot be handed the same number.
 */
class ReferenceCodeService
{
    public function applicant(): string
    {
        return $this->next('applicants', 'applicant_code', config('empower.code_prefixes.applicant'));
    }

    public function employee(): string
    {
        return $this->next('employees', 'employee_number', config('empower.code_prefixes.employee'));
    }

    public function jobRequest(): string
    {
        return $this->next('job_requests', 'request_code', config('empower.code_prefixes.job_request'));
    }

    public function deployment(): string
    {
        return $this->next('deployments', 'deployment_code', config('empower.code_prefixes.deployment'));
    }

    public function training(): string
    {
        return $this->next('trainings', 'training_code', config('empower.code_prefixes.training'));
    }

    public function client(): string
    {
        return $this->next('client_companies', 'company_code', config('empower.code_prefixes.client'));
    }

    public function position(): string
    {
        return $this->next('job_positions', 'position_code', config('empower.code_prefixes.position'));
    }

    public function violationReport(): string
    {
        return $this->next('violation_reports', 'report_code', config('empower.code_prefixes.violation_report'));
    }

    private function next(string $table, string $column, string $prefix): string
    {
        $year = now()->year;
        $pattern = "{$prefix}-{$year}-";

        $this->lockSeries($pattern);

        // Still a locking read: on MariaDB a plain read inside a transaction
        // can return an older snapshot, which would miss the code issued by
        // whoever held the series lock a moment ago.
        $latest = DB::table($table)
            ->where($column, 'like', $pattern.'%')
            ->orderByDesc($column)
            ->lockForUpdate()
            ->value($column);

        $sequence = $latest
            ? ((int) substr($latest, strlen($pattern))) + 1
            : 1;

        return $pattern.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Serialises generation within one series - one prefix, one year - for
     * the rest of the caller's transaction (audit S-04).
     *
     * Locking the latest code issued, as this used to, holds on MariaDB but
     * not on PostgreSQL: a transaction that waited for that row re-reads only
     * the row it waited on and computes the same next number. When no code
     * existed yet for the year there was no row to lock on either database.
     * Two deployments recorded at the same moment then failed on a duplicate
     * employee number. The series row always exists once created, so it can
     * be locked the same way everywhere.
     *
     * Outside a transaction the lock lasts one statement, which is no worse
     * than before; every caller that writes the code runs inside one.
     */
    private function lockSeries(string $scope): void
    {
        $locked = DB::table('reference_sequences')->where('scope', $scope)->lockForUpdate()->first();

        if ($locked === null) {
            // First code of a new series: create its row - harmlessly, if
            // somebody else has just done the same - then lock it.
            DB::table('reference_sequences')->insertOrIgnore(['scope' => $scope, 'created_at' => now()]);
            DB::table('reference_sequences')->where('scope', $scope)->lockForUpdate()->first();
        }
    }
}
