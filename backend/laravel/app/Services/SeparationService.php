<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\Resignation;
use App\Models\Termination;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resignation, termination, and archiving.
 *
 * Separation records carry legal weight: they are the agency's evidence if a
 * dismissal is ever questioned. Nothing here is deleted, and finalising a
 * separation always writes both a status history entry and an archive snapshot
 * so the record remains readable years later even if the client company is
 * renamed or the department is retired.
 */
class SeparationService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function fileResignation(Employee $employee, array $data, User $actor): Resignation
    {
        if (! $employee->isActive()) {
            throw new RuntimeException(sprintf(
                'Only an active employee can file a resignation. This employee is %s.',
                $employee->employment_status
            ));
        }

        $resignation = Resignation::create(array_merge($data, [
            'employee_id' => $employee->id,
            'status' => 'filed',
            'clearance_status' => 'pending',
            'processed_by' => $actor->id,
        ]));

        $this->audit->record(
            'resignation',
            'separation',
            Resignation::class,
            $resignation->id,
            null,
            ['employee_number' => $employee->employee_number, 'filing_date' => $data['filing_date']]
        );

        return $resignation;
    }

    /**
     * Completes a resignation and moves the employee out of active service.
     */
    public function completeResignation(Resignation $resignation, User $actor): Resignation
    {
        if (! $resignation->canBeCompleted()) {
            throw new RuntimeException(
                'The employee must be cleared and have an exit date recorded before the resignation can be completed.'
            );
        }

        return DB::transaction(function () use ($resignation, $actor) {
            $resignation->update(['status' => 'completed', 'processed_by' => $actor->id]);

            $this->changeEmploymentStatus(
                $resignation->employee,
                'resigned',
                $actor,
                'Resignation completed on '.$resignation->exit_date->toDateString().'.'
            );

            $this->archive($resignation->employee, 'Resigned', $actor);

            return $resignation->fresh();
        });
    }

    public function fileTermination(Employee $employee, array $data, User $actor): Termination
    {
        if (! $employee->isActive()) {
            throw new RuntimeException(sprintf(
                'Only an active employee can be terminated. This employee is %s.',
                $employee->employment_status
            ));
        }

        $termination = Termination::create(array_merge($data, [
            'employee_id' => $employee->id,
            'status' => $data['status'] ?? 'draft',
            'created_by' => $actor->id,
        ]));

        $this->audit->record(
            'termination',
            'separation',
            Termination::class,
            $termination->id,
            null,
            [
                'employee_number' => $employee->employee_number,
                'reason' => $data['reason'],
                'termination_date' => $data['termination_date'],
            ]
        );

        return $termination;
    }

    /**
     * Finalises a termination. Separate from filing so that a dismissal passes
     * through review rather than taking effect the moment it is typed in.
     */
    public function finaliseTermination(Termination $termination, User $actor): Termination
    {
        if ($termination->isFinalized()) {
            throw new RuntimeException('This termination has already been finalised.');
        }

        return DB::transaction(function () use ($termination, $actor) {
            $termination->update([
                'status' => 'finalized',
                'approved_by' => $actor->id,
            ]);

            $this->changeEmploymentStatus(
                $termination->employee,
                'terminated',
                $actor,
                'Terminated: '.$termination->reason
            );

            $this->archive($termination->employee, 'Terminated: '.$termination->reason, $actor);

            return $termination->fresh();
        });
    }

    /**
     * Writes a point-in-time snapshot of the employee and their full history.
     */
    public function archive(Employee $employee, string $reason, User $actor): Archive
    {
        $employee->load([
            'applicant.educations',
            'applicant.experiences',
            'applicant.requirements.requirementType',
            'deployments.company',
            'deployments.department',
            'violations',
            'statusHistory',
        ]);

        $snapshot = [
            'employee' => $employee->only([
                'id', 'employee_number', 'biometric_number', 'hire_date',
                'current_position_title', 'employment_status',
            ]),
            'person' => $employee->applicant?->only([
                'applicant_code', 'first_name', 'middle_name', 'last_name',
                'birth_date', 'sex', 'contact_number', 'email', 'present_address',
            ]),
            'deployments' => $employee->deployments->map(fn ($d) => [
                'deployment_code' => $d->deployment_code,
                'company' => $d->company?->company_name,
                'department' => $d->department?->department_name,
                'position' => $d->position_title,
                'from' => $d->deployment_date?->toDateString(),
                'to' => $d->end_date?->toDateString(),
            ])->all(),
            'violations' => $employee->violations->map(fn ($v) => [
                'date' => $v->violation_date?->toDateString(),
                'type' => $v->violation_type,
                'description' => $v->description,
                'penalty' => $v->penalty,
                'status' => $v->status,
            ])->all(),
            'status_history' => $employee->statusHistory->map(fn ($h) => [
                'from' => $h->from_status,
                'to' => $h->to_status,
                'reason' => $h->reason,
                'at' => $h->changed_at?->toIso8601String(),
            ])->all(),
            'archived_reason' => $reason,
        ];

        $archive = Archive::create([
            'entity_type' => 'employee',
            'entity_id' => $employee->id,
            'archive_reason' => $reason,
            'snapshot_json' => $snapshot,
            'archived_by' => $actor->id,
            'archived_at' => now(),
        ]);

        $this->audit->record('update', 'archives', Archive::class, $archive->id, null, [
            'entity' => 'employee',
            'employee_number' => $employee->employee_number,
            'reason' => $reason,
        ]);

        return $archive;
    }

    private function changeEmploymentStatus(Employee $employee, string $to, User $actor, string $reason): void
    {
        $from = $employee->employment_status;
        $allowed = config("empower.employee_transitions.{$from}", []);

        if (! in_array($to, $allowed, true)) {
            throw new RuntimeException(sprintf(
                'An employee cannot move from "%s" to "%s".',
                $from,
                $to
            ));
        }

        $employee->forceFill(['employment_status' => $to])->save();

        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);

        // Close the active placement so the client company's headcount stops
        // counting someone who has left.
        $employee->deployments()->where('deployment_status', 'active')->update([
            'deployment_status' => 'ended',
            'end_date' => now()->toDateString(),
        ]);
    }
}
