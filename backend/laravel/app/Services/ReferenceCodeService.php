<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generates the human-readable codes HR reads out over the phone, in the form
 * PREFIX-YEAR-SEQUENCE, for example APP-2026-00042.
 *
 * The sequence is derived inside a locking read so two staff registering
 * applicants at the same counter cannot be handed the same number.
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

    private function next(string $table, string $column, string $prefix): string
    {
        $year = now()->year;
        $pattern = "{$prefix}-{$year}-";

        // lockForUpdate serialises concurrent generation for the same prefix and
        // year, which is what stops two simultaneous registrations colliding on
        // the unique index.
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
}
