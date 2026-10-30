<?php

namespace Tests\Support;

use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\User;
use App\Services\DeploymentService;

/**
 * Deployed employees with a schedule, for the attendance tests.
 *
 * Dates are fixed so the weekdays are known: 7 September 2026 is a Monday,
 * and the tests travel to 30 September 2026.
 */
trait AttendanceFixtures
{
    use SeedsDomainData;

    protected function deployedEmployee(
        User $hr,
        string $on = '2026-09-01',
        ?ClientCompany $company = null,
        array $applicant = [],
        array $deployment = [],
    ): Employee {
        $company ??= $this->clientCompany($hr, ['company_name' => 'Client '.uniqid()]);
        $department = $company->departments()->first() ?? $this->department($company, $hr);
        $request = $this->jobRequest($department, $hr, ['workers_needed' => 20]);

        $person = $this->applicant($hr, ['current_status' => 'ready_for_deployment'] + $applicant);
        $this->verifyRequirements($person, $hr, 'all');
        $this->completeTraining($person, $hr);

        return app(DeploymentService::class)
            ->deploy($person, $request, ['deployment_date' => $on] + $deployment, $hr)
            ->employee;
    }

    protected function dayShift(Employee $employee, string $from = '2026-09-01'): void
    {
        $this->postJson("/api/v1/employees/{$employee->id}/schedules", [
            'effective_from' => $from,
            'work_days' => [1, 2, 3, 4, 5],
            'shift_start' => '08:00',
            'shift_end' => '17:00',
        ])->assertCreated();
    }

    protected function days(Employee $employee, string $from, string $to): array
    {
        return collect(
            $this->getJson("/api/v1/employees/{$employee->id}/attendance?from={$from}&to={$to}")->assertOk()->json('data.days')
        )->keyBy('date')->all();
    }

    protected function encode(Employee $employee, array $entries, array $extra = [])
    {
        return $this->postJson("/api/v1/employees/{$employee->id}/attendance", ['entries' => $entries] + $extra);
    }
}
