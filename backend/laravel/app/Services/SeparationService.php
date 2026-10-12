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
    public function __construct(
        private readonly AuditService $audit,
        private readonly ApplicantLifecycleService $lifecycle,
    ) {
    }

    public function fileResignation(Employee $employee, array $data, User $actor): Resignation
    {
        if (! $employee->isActive()) {
            throw new RuntimeException(sprintf(
                'Only an active employee can file a resignation. This employee is %s.',
                $employee->employment_status
            ));
        }

        // One case at a time. Two open resignations for the same person would
        // leave HR clearing one while the other sat waiting to be completed.
        if ($employee->resignations()->whereIn('status', Resignation::OPEN_STATUSES)->exists()) {
            throw new RuntimeException(
                'This employee already has a resignation in progress. Update that one instead of filing another.'
            );
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
                'Resignation completed on '.$resignation->exit_date->toDateString().'.',
                $resignation->exit_date->toDateString(),
            );

            $this->archive($resignation->employee, 'Resigned', $actor);

            // Last, because it notifies the applicant: nothing after it can
            // roll back a separation they have already been told about.
            $this->closeApplicantRecord(
                $resignation->employee,
                'resigned',
                $actor,
                'Resignation completed on '.$resignation->exit_date->toDateString().'.'
            );

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

        // One case at a time, for the same reason as resignations: a second
        // open termination would be reviewed while the first still waited.
        if ($employee->terminations()->whereIn('status', Termination::OPEN_STATUSES)->exists()) {
            throw new RuntimeException(
                'A termination for this employee is already awaiting review. Finalise or withdraw it first.'
            );
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

        if (! $termination->isOpen()) {
            throw new RuntimeException('This termination was withdrawn and can no longer be finalised.');
        }

        return DB::transaction(function () use ($termination, $actor) {
            $termination->update([
                'status' => 'finalized',
                'approved_by' => $actor->id,
                'finalized_at' => now(),
            ]);

            $this->changeEmploymentStatus(
                $termination->employee,
                'terminated',
                $actor,
                'Terminated: '.$termination->reason,
                $termination->termination_date->toDateString(),
            );

            $this->archive($termination->employee, 'Terminated: '.$termination->reason, $actor);

            $this->closeApplicantRecord(
                $termination->employee,
                'terminated',
                $actor,
                'Terminated: '.$termination->reason
            );

            return $termination->fresh();
        });
    }

    /**
     * Closes a termination without dismissing anybody.
     *
     * The other way a review can end. Nothing about the employee changes; the
     * termination stays on file, marked withdrawn, with who withdrew it and
     * why, so a case that was opened and dropped is still visible later.
     */
    public function withdrawTermination(Termination $termination, string $reason, User $actor): Termination
    {
        if (! $termination->isOpen()) {
            throw new RuntimeException(sprintf(
                'Only a termination awaiting review can be withdrawn. This one is %s.',
                $termination->status
            ));
        }

        $before = $termination->status;

        $termination->update([
            'status' => 'withdrawn',
            'withdrawn_by' => $actor->id,
            'withdrawn_at' => now(),
            'withdrawal_reason' => $reason,
        ]);

        $this->audit->record(
            'update',
            'separation',
            Termination::class,
            $termination->id,
            ['status' => $before],
            ['status' => 'withdrawn', 'reason' => $reason]
        );

        return $termination->fresh();
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

    private function changeEmploymentStatus(
        Employee $employee,
        string $to,
        User $actor,
        string $reason,
        string $effectiveDate,
    ): void {
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
        // counting someone who has left. Dated by the separation itself - the
        // exit date, or the termination date - rather than by the day somebody
        // happened to process it, so the placement history says when the person
        // actually stopped working there.
        $employee->deployments()->where('deployment_status', 'active')->update([
            'deployment_status' => 'ended',
            'end_date' => $effectiveDate,
        ]);
    }

    /**
     * Moves the applicant record out of employment alongside the employee.
     *
     * The applicant and the employee are the same person, and the lifecycle
     * runs from application to resignation or termination on one record. This
     * used to stop at the employee, so the applicant stayed "active" forever:
     * the applicant list showed former workers as employed, and the portal told
     * a dismissed employee they were still deployed.
     *
     * Routed through the lifecycle service so the change is validated,
     * historised, audited, and notified like every other status move. Skipped
     * rather than refused if the applicant is somehow not in employment, since
     * the separation itself has already been decided and recorded.
     */
    private function closeApplicantRecord(Employee $employee, string $to, User $actor, string $reason): void
    {
        $applicant = $employee->applicant;

        if (! $applicant || ! $this->lifecycle->canTransition($applicant->current_status, $to)) {
            return;
        }

        $this->lifecycle->transition($applicant, $to, $actor, $reason);
    }
}
