<?php

namespace App\Services;

use App\Models\CoordinatorAssignment;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\User;
use App\Models\ViolationReport;
use App\Notifications\CoordinatorEmployeeFlagged;
use App\Notifications\ViolationThresholdReached;
use RuntimeException;

/**
 * Records an official violation, however it reached HR.
 *
 * HR records some offences directly and validates others from a coordinator's
 * report. Both end here, so the record, the audit entry, and the threshold
 * check are the same either way. Reaching the threshold flags the employee for
 * review and notifies; it never terminates anybody.
 */
class ViolationService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * @return array{0: EmployeeViolation, 1: bool} the violation, and whether
     *                                             the threshold has now been reached
     */
    public function record(Employee $employee, array $data, User $actor, ?ViolationReport $report = null): array
    {
        if (! $employee->isActive()) {
            throw new RuntimeException('Violations can only be recorded against an active employee.', 400);
        }

        $violation = EmployeeViolation::create([
            'employee_id' => $employee->id,
            'violation_report_id' => $report?->id,
            // Where the worker was placed on the day, so the record still
            // reads correctly after they move to another client.
            'client_company_id' => $report?->client_company_id ?? $employee->current_client_company_id,
            'client_department_id' => $report?->client_department_id ?? $employee->current_department_id,
            'violation_date' => $data['violation_date'],
            'violation_type' => $data['violation_type'],
            'description' => $data['description'],
            'evidence_path' => $data['evidence_path'] ?? $report?->evidence_path,
            'penalty' => $data['penalty'] ?? null,
            'status' => $data['status'] ?? 'open',
            'remarks' => $data['remarks'] ?? null,
            'issued_by' => $actor->id,
            'reported_by' => $report?->reported_by,
        ]);

        $this->audit->record('violation', 'violations', EmployeeViolation::class, $violation->id, null, [
            'employee_number' => $employee->employee_number,
            'type' => $data['violation_type'],
            'from_report' => $report?->report_code,
        ], clientCompanyId: $violation->client_company_id);

        $reached = $employee->reachedViolationThreshold();

        if ($reached) {
            $count = $employee->activeViolations()->reorder()->count();

            User::query()
                ->whereIn('user_type', ['admin', 'hr'])
                ->where('is_active', true)
                ->get()
                ->each->notify(new ViolationThresholdReached($employee->loadMissing('applicant'), $count));

            // The client's coordinator hears too, in their own workspace.
            $this->coordinatorsOf($employee->current_client_company_id)
                ->each->notify(new CoordinatorEmployeeFlagged($employee, $count));
        }

        return [$violation, $reached];
    }

    private function coordinatorsOf(?int $companyId)
    {
        if (! $companyId) {
            return collect();
        }

        return User::query()
            ->where('user_type', 'coordinator')
            ->where('is_active', true)
            ->whereIn('id', CoordinatorAssignment::query()
                ->active()
                ->where('client_company_id', $companyId)
                ->select('coordinator_user_id'))
            ->get();
    }
}
