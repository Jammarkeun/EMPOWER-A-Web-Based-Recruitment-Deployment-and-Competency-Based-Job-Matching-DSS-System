<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\ClientDepartment;
use App\Models\Deployment;
use App\Models\DeploymentHistory;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\JobRequest;
use App\Models\User;
use App\Notifications\DeploymentRecorded;
use App\Support\ApplicantStatuses;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deployment: the moment an applicant becomes an employee.
 *
 * This is the highest-impact action in the system and the one the agency has no
 * way to undo cleanly, so every precondition is checked before anything is
 * written, and the write itself is one transaction. Either the employee record,
 * the deployment, the status changes, and the request counter all land, or none
 * of them do.
 */
class DeploymentService
{
    public function __construct(
        private readonly ApplicantLifecycleService $lifecycle,
        private readonly FolderCategoryService $folders,
        private readonly ReferenceCodeService $codes,
        private readonly AuditService $audit,
        private readonly PlacementService $placements,
        private readonly JobRequestLifecycleService $requests,
    ) {
    }

    public function deploy(Applicant $applicant, JobRequest $jobRequest, array $data, User $actor): Deployment
    {
        $department = $this->resolveDepartment($jobRequest, $data);

        $deployment = DB::transaction(function () use ($applicant, $jobRequest, $department, $data, $actor) {
            /*
             * Every check runs again here, on rows locked for this transaction
             * (audit S-04). Two officers deploying at the same moment - the
             * last seat on a request, or the same applicant twice - used to
             * pass the checks together and both succeed. The request is locked
             * before the applicant, always in that order, so two deployments
             * can never wait on each other.
             */
            $jobRequest = JobRequest::query()->whereKey($jobRequest->getKey())->lockForUpdate()->firstOrFail();
            $applicant = Applicant::query()->whereKey($applicant->getKey())->lockForUpdate()->firstOrFail();

            /*
             * Nothing else is read until both locks are held. On MariaDB the
             * first ordinary read fixes the transaction's snapshot; taken
             * before the wait for the applicant's lock, it would show the
             * applicant as they were before a deployment that committed while
             * this one waited - found by ConcurrentDeploymentTest.
             */
            $jobRequest->load('company');

            $this->assertDeployable($applicant, $jobRequest);

            $employee = $this->resolveEmployee($applicant, $jobRequest, $department, $data, $actor);

            try {
                $deployment = Deployment::create([
                    'deployment_code' => $this->codes->deployment(),
                    'employee_id' => $employee->id,
                    'job_request_id' => $jobRequest->id,
                    'client_company_id' => $jobRequest->client_company_id,
                    'client_department_id' => $department->id,
                    'position_title' => $data['position_title'] ?? $jobRequest->position_title,
                    'supervisor_name' => $data['supervisor_name'] ?? null,
                    'deployment_date' => $data['deployment_date'],
                    'deployment_status' => 'active',
                    'remarks' => $data['remarks'] ?? null,
                    'created_by' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // The database's own guard: one active deployment per employee
                // (audit S-04). Unreachable while the lock above holds; answered
                // as the conflict it is, never as a server error. Any other
                // duplicate is not this, and is left to surface as it is.
                if (! $this->isActiveDeploymentConflict($e)) {
                    throw $e;
                }

                throw new RuntimeException('This applicant is already deployed as an active employee.', 409, $e);
            }

            $this->advanceApplicant($applicant, $deployment, $actor);
            $this->incrementFulfilment($jobRequest, $deployment, $actor);

            $this->audit->record(
                action: 'deployment',
                module: 'deployment',
                recordType: Deployment::class,
                recordId: $deployment->id,
                newValues: [
                    'applicant_id' => $applicant->id,
                    'employee_number' => $employee->employee_number,
                    'job_request_id' => $jobRequest->id,
                    'deployment_date' => $data['deployment_date'],
                ],
            );

            return $deployment->load(['employee.applicant', 'company', 'department']);
        });

        // Sent once the deployment has actually committed.
        $applicant->notify(new DeploymentRecorded($deployment));

        return $deployment;
    }

    /**
     * Move an already-deployed employee to a different assignment, keeping the
     * previous placement in the history rather than overwriting it.
     */
    /**
     * Rules, all checked on locked rows before anything is written:
     *
     * - Within the same client only (audit Q8, decided 2026-10-19). A
     *   deployment is counted against one client's manpower request, and the
     *   client's coordinator sees it through that client. Moving the worker to
     *   another client is a new deployment against that client's request.
     * - Only an active placement of an active employee, into an active
     *   department of that client.
     * - Dated on or after the placement began and after the last recorded
     *   move, and not in the future: the change is applied when it is saved,
     *   so a future date would show the new department before the worker is
     *   there. (Our rule; nothing schedules a change for later.)
     * - Something must actually change.
     *
     * The previous placement is kept as a history row, and the audit entry
     * records every field before and after.
     */
    public function reassign(Deployment $deployment, array $data, User $actor): Deployment
    {
        return DB::transaction(function () use ($deployment, $data, $actor) {
            // The department is eager-loaded: lazy loading throws in local
            // development, which the test suite (APP_ENV=testing) never sees.
            $deployment = Deployment::query()->with('department')->whereKey($deployment->getKey())->lockForUpdate()->firstOrFail();
            $employee = Employee::query()->whereKey($deployment->employee_id)->lockForUpdate()->firstOrFail();

            if ($deployment->deployment_status !== 'active' || ! $employee->isActive()) {
                throw new RuntimeException('Only an active employee\'s current placement can be reassigned.', 409);
            }

            if (isset($data['client_company_id']) && (int) $data['client_company_id'] !== (int) $deployment->client_company_id) {
                throw new RuntimeException(
                    'A move to another client company is not a reassignment. Record it as a new deployment against '
                    .'that client\'s manpower request, so the request\'s headcount and the client\'s coordinator stay correct.',
                    422
                );
            }

            $toDepartment = ClientDepartment::query()->whereKey($data['client_department_id'])->first();

            if (! $toDepartment || (int) $toDepartment->client_company_id !== (int) $deployment->client_company_id) {
                throw new RuntimeException('That department does not belong to this employee\'s client company.', 422);
            }

            if ($toDepartment->status !== 'active') {
                throw new RuntimeException('That department is inactive and cannot receive workers.', 422);
            }

            $effective = Carbon::parse($data['effective_date'])->startOfDay();
            // Not through the history() relation: it is ordered, and PostgreSQL
            // refuses an ORDER BY beside an aggregate.
            $earliest = DeploymentHistory::query()->where('deployment_id', $deployment->id)->max('effective_date')
                ?? $deployment->deployment_date;

            if ($effective->isFuture()) {
                throw new RuntimeException('Record a reassignment once it has taken effect; the date cannot be in the future.', 422);
            }

            if ($earliest && $effective->lt(Carbon::parse($earliest)->startOfDay())) {
                throw new RuntimeException(
                    'The date cannot be earlier than '.Carbon::parse($earliest)->toDateString()
                    .', when this placement began or was last changed.',
                    422
                );
            }

            $before = [
                'department' => $deployment->department?->department_name,
                'position_title' => $deployment->position_title,
                'supervisor_name' => $deployment->supervisor_name,
            ];

            $position = trim((string) ($data['position_title'] ?? '')) ?: $deployment->position_title;
            $supervisor = array_key_exists('supervisor_name', $data)
                ? (trim((string) $data['supervisor_name']) ?: null)
                : $deployment->supervisor_name;

            if ($toDepartment->id === $deployment->client_department_id
                && $position === $deployment->position_title
                && $supervisor === $deployment->supervisor_name) {
                throw new RuntimeException('Nothing would change. Choose a different department, position or supervisor.', 422);
            }

            DeploymentHistory::create([
                'deployment_id' => $deployment->id,
                'from_company_id' => $deployment->client_company_id,
                'from_department_id' => $deployment->client_department_id,
                'from_position_title' => $deployment->position_title,
                'to_company_id' => $deployment->client_company_id,
                'to_department_id' => $toDepartment->id,
                'to_position_title' => $position,
                'change_type' => $data['change_type'] ?? 'reassignment',
                'effective_date' => $effective->toDateString(),
                'remarks' => $data['remarks'] ?? null,
                'changed_by' => $actor->id,
            ]);

            $deployment->update([
                'client_department_id' => $toDepartment->id,
                'position_title' => $position,
                'supervisor_name' => $supervisor,
            ]);

            $employee->update([
                'current_department_id' => $toDepartment->id,
                'current_position_title' => $position,
                'current_supervisor_name' => $supervisor,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                action: 'update',
                module: 'deployment',
                recordType: Deployment::class,
                recordId: $deployment->id,
                oldValues: $before,
                newValues: [
                    'department' => $toDepartment->department_name,
                    'position_title' => $position,
                    'supervisor_name' => $supervisor,
                    'effective_date' => $effective->toDateString(),
                    'reason' => $data['remarks'] ?? null,
                ],
                clientCompanyId: $deployment->client_company_id,
            );

            return $deployment->refresh()->load(['employee.applicant', 'company', 'department', 'history']);
        });
    }

    /**
     * Every reason a deployment must be refused, checked on locked rows before
     * any write.
     */
    private function assertDeployable(Applicant $applicant, JobRequest $jobRequest): void
    {
        /*
         * Checked first, and answered as a conflict: the usual way to arrive
         * here is a second click, or a second officer, after the deployment
         * already went through.
         */
        if ($applicant->employee?->isActive()) {
            $existing = $applicant->employee->activeDeployment();

            throw new RuntimeException(
                'This applicant is already deployed as an active employee'
                .($existing ? " ({$existing->deployment_code})" : '').'.',
                409
            );
        }

        if (! $jobRequest->canAcceptDeployment()) {
            throw new RuntimeException(sprintf(
                'Request %s cannot accept another deployment. Status is "%s" with %d of %d positions filled.',
                $jobRequest->request_code,
                $jobRequest->request_status,
                $jobRequest->workers_fulfilled,
                $jobRequest->workers_needed,
            ), 409);
        }

        if (! $jobRequest->company->isActive()) {
            throw new RuntimeException('The client company is inactive and cannot receive deployments.');
        }

        if (! in_array($applicant->current_status, ApplicantStatuses::DEPLOYABLE_FROM, true)) {
            throw new RuntimeException(sprintf(
                'The applicant must be approved or ready for deployment. Current status is "%s".',
                str_replace('_', ' ', $applicant->current_status),
            ));
        }

        /*
         * Whether CDE requires the client's approval to be recorded before a
         * deployment is an open question (audit Q3). Off by default, which is
         * how the system has always behaved; when switched on, only an
         * applicant whose approval was recorded - moved to "approved" by an
         * officer - can be deployed.
         */
        if (config('empower.require_client_approval_before_deployment', false) && $applicant->current_status !== 'approved') {
            throw new RuntimeException(
                "The client company's approval must be recorded before this applicant can be deployed. "
                .'Endorse them to the client and record the approval by moving them to "Approved".'
            );
        }

        // The documentary gate. Deploying someone whose medical results are not
        // in exposes both the agency and the client company.
        if ($this->folders->determine($applicant) !== 'folder_1') {
            $explanation = $this->folders->explain($applicant);
            $outstanding = array_merge($explanation['missing_primary'], $explanation['missing_final']);

            throw new RuntimeException(
                'The applicant has outstanding requirements: '.implode(', ', $outstanding).'.'
            );
        }

        /*
         * Deploying straight from "ready for deployment" skips the endorsement,
         * which is where the training gate normally applies - so it applies
         * here instead. An applicant already approved passed it on the way.
         * Checked after the documents, the order it always came in.
         */
        if (
            $applicant->current_status === 'ready_for_deployment'
            && config('empower.require_training_before_deployment', true)
            && ! $this->lifecycle->hasCompletedTraining($applicant)
        ) {
            throw new RuntimeException(
                'This applicant has not completed training yet. CDE trains every worker before placing them, '
                .'so record their training on the Training screen first.'
            );
        }

        /*
         * The applicant's own answer counts - for this placement (R-05, R-07).
         *
         * After training, having seen the site, the candidate decides whether
         * they still want the job. A decline for this endorsement stops the
         * deployment until HR records a corrected answer, with the reason. A
         * decline for a different client's request does not.
         */
        $declined = $this->placements->decliningDecision($applicant, $jobRequest);

        if ($declined) {
            throw new RuntimeException(
                'This applicant declined this placement'
                .($declined->decided_at ? ' on '.$declined->decided_at->toDateString() : '').'. '
                .'If they have changed their mind, record their new answer on the applicant record first.'
            );
        }
    }

    /**
     * Returns the existing employee record for a redeployment, or creates one on
     * first deployment. A returning worker keeps their original employee number.
     */
    private function resolveEmployee(
        Applicant $applicant,
        JobRequest $jobRequest,
        ClientDepartment $department,
        array $data,
        User $actor,
    ): Employee {
        $employee = $applicant->employee;

        $assignment = [
            'current_client_company_id' => $jobRequest->client_company_id,
            'current_department_id' => $department->id,
            'current_position_title' => $data['position_title'] ?? $jobRequest->position_title,
            'current_supervisor_name' => $data['supervisor_name'] ?? null,
            'updated_by' => $actor->id,
        ];

        if ($employee) {
            $employee->update($assignment);
            $employee->forceFill(['employment_status' => 'active'])->save();

            return $employee;
        }

        $employee = Employee::create(array_merge($assignment, [
            'applicant_id' => $applicant->id,
            'employee_number' => $data['employee_number'] ?? $this->codes->employee(),
            'biometric_number' => $data['biometric_number'] ?? null,
            'hire_date' => $data['deployment_date'],
            'created_by' => $actor->id,
        ]));

        // employment_status is guarded, so it is set explicitly rather than
        // left to the column default, which create() would not load back into
        // the in-memory model.
        $employee->forceFill(['employment_status' => 'active'])->save();

        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'from_status' => null,
            'to_status' => 'active',
            'reason' => 'Initial deployment.',
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);

        return $employee;
    }

    /**
     * Moves the applicant into employment, recording only what happened.
     *
     * This used to walk the applicant through "endorsed to the client" and
     * "approved by the client company" on the way, writing both into their
     * history and sending both notifications, even when nobody had endorsed
     * them or recorded any approval (audit R-11). Now the history says what
     * actually happened: the deployment was recorded, and - when the applicant
     * came straight from "ready for deployment" - that no client approval was
     * recorded in EMPOWER.
     */
    private function advanceApplicant(Applicant $applicant, Deployment $deployment, User $actor): void
    {
        $context = ['deployment_id' => $deployment->id, 'notify' => false];

        if ($applicant->current_status === 'approved') {
            $applicant = $this->lifecycle->transition(
                $applicant,
                'deployed',
                $actor,
                "Deployment {$deployment->deployment_code} recorded.",
                [],
                $context
            );
        } else {
            $applicant = $this->lifecycle->transitionForWorkflow(
                $applicant,
                'deployed',
                $actor,
                "Deployment {$deployment->deployment_code} recorded directly from Ready for Deployment; "
                .'no client approval was recorded in EMPOWER.',
                ['ready_for_deployment'],
                [],
                $context
            );
        }

        $this->lifecycle->transition($applicant, 'active', $actor, 'Now an active employee.');
    }

    /**
     * Counts the deployment against the request, and lets the request's status
     * follow its headcount through the same rules as every other change.
     */
    private function incrementFulfilment(JobRequest $jobRequest, Deployment $deployment, User $actor): void
    {
        /*
         * Counted only while a position is still open, in the same statement
         * (audit S-04). The request is locked and was checked above, so this
         * does not refuse in practice; it is the database-level backstop,
         * should any future path arrive here without that lock.
         */
        $counted = JobRequest::query()
            ->whereKey($jobRequest->id)
            ->whereColumn('workers_fulfilled', '<', 'workers_needed')
            ->increment('workers_fulfilled');

        if ($counted === 0) {
            throw new RuntimeException(
                "Request {$jobRequest->request_code} has no open position left for this deployment.",
                409
            );
        }

        $jobRequest->refresh();

        $this->requests->syncToHeadcount(
            $jobRequest,
            $actor,
            sprintf(
                'Deployment %s recorded (%d of %d filled).',
                $deployment->deployment_code,
                $jobRequest->workers_fulfilled,
                $jobRequest->workers_needed
            ),
            'deployment'
        );
    }

    /**
     * Whether a duplicate-key error came from the one-active-deployment index.
     * MariaDB and PostgreSQL name the index in the message; SQLite names the
     * column.
     */
    private function isActiveDeploymentConflict(UniqueConstraintViolationException $e): bool
    {
        return str_contains($e->getMessage(), 'uq_deployments_one_active_per_employee')
            || str_contains($e->getMessage(), 'deployments.active_employee_id');
    }

    private function resolveDepartment(JobRequest $jobRequest, array $data): ClientDepartment
    {
        $departmentId = $data['client_department_id'] ?? $jobRequest->client_department_id;
        $department = ClientDepartment::findOrFail($departmentId);

        if ((int) $department->client_company_id !== (int) $jobRequest->client_company_id) {
            throw new RuntimeException('The department does not belong to the request\'s client company.');
        }

        return $department;
    }
}
