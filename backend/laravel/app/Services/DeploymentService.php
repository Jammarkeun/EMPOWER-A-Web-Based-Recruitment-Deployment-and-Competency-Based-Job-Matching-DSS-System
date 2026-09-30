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
    ) {
    }

    public function deploy(Applicant $applicant, JobRequest $jobRequest, array $data, User $actor): Deployment
    {
        $department = $this->resolveDepartment($jobRequest, $data);

        $this->assertDeployable($applicant, $jobRequest);

        $deployment = DB::transaction(function () use ($applicant, $jobRequest, $department, $data, $actor) {
            $employee = $this->resolveEmployee($applicant, $jobRequest, $department, $data, $actor);

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

            $this->advanceApplicant($applicant, $actor);
            $this->incrementFulfilment($jobRequest);

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
    public function reassign(Deployment $deployment, array $data, User $actor): Deployment
    {
        $employee = $deployment->employee;

        if ($deployment->deployment_status !== 'active') {
            throw new RuntimeException('Only an active deployment can be reassigned.');
        }

        $toDepartment = ClientDepartment::findOrFail($data['client_department_id']);

        if ((int) $toDepartment->client_company_id !== (int) ($data['client_company_id'] ?? $toDepartment->client_company_id)) {
            throw new RuntimeException('The department does not belong to the selected client company.');
        }

        return DB::transaction(function () use ($deployment, $employee, $toDepartment, $data, $actor) {
            DeploymentHistory::create([
                'deployment_id' => $deployment->id,
                'from_company_id' => $deployment->client_company_id,
                'from_department_id' => $deployment->client_department_id,
                'from_position_title' => $deployment->position_title,
                'to_company_id' => $toDepartment->client_company_id,
                'to_department_id' => $toDepartment->id,
                'to_position_title' => $data['position_title'] ?? $deployment->position_title,
                'change_type' => $data['change_type'] ?? 'reassignment',
                'effective_date' => $data['effective_date'],
                'remarks' => $data['remarks'] ?? null,
                'changed_by' => $actor->id,
            ]);

            $deployment->update([
                'client_company_id' => $toDepartment->client_company_id,
                'client_department_id' => $toDepartment->id,
                'position_title' => $data['position_title'] ?? $deployment->position_title,
                'supervisor_name' => $data['supervisor_name'] ?? $deployment->supervisor_name,
            ]);

            $employee->update([
                'current_client_company_id' => $toDepartment->client_company_id,
                'current_department_id' => $toDepartment->id,
                'current_position_title' => $deployment->position_title,
                'current_supervisor_name' => $deployment->supervisor_name,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                action: 'update',
                module: 'deployment',
                recordType: Deployment::class,
                recordId: $deployment->id,
                newValues: ['reassigned_to_department' => $toDepartment->department_name],
            );

            return $deployment->refresh()->load(['employee.applicant', 'company', 'department']);
        });
    }

    /**
     * Every reason a deployment must be refused, checked before any write.
     */
    private function assertDeployable(Applicant $applicant, JobRequest $jobRequest): void
    {
        if (! $jobRequest->canAcceptDeployment()) {
            throw new RuntimeException(sprintf(
                'Request %s cannot accept another deployment. Status is "%s" with %d of %d positions filled.',
                $jobRequest->request_code,
                $jobRequest->request_status,
                $jobRequest->workers_fulfilled,
                $jobRequest->workers_needed,
            ));
        }

        if (! $jobRequest->company->isActive()) {
            throw new RuntimeException('The client company is inactive and cannot receive deployments.');
        }

        if (! in_array($applicant->current_status, ['approved', 'ready_for_deployment'], true)) {
            throw new RuntimeException(sprintf(
                'The applicant must be approved or ready for deployment. Current status is "%s".',
                str_replace('_', ' ', $applicant->current_status),
            ));
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
         * The applicant's own answer counts.
         *
         * The agency's process has the candidate decide, after training and
         * after seeing the site, whether they still want the job - and people do
         * say no. Deploying somebody who has declined means sending a worker who
         * will not turn up, which costs the agency its standing with the client.
         *
         * Refused rather than warned about: if the applicant has changed their
         * mind, HR clears the response and deploys, and there is then a record
         * that somebody made that decision knowingly.
         */
        if ($applicant->placement_response === 'declined') {
            throw new RuntimeException(
                'This applicant has declined the placement. Confirm they still want the job '
                .'and clear their response before deploying them.'
            );
        }

        if ($applicant->employee?->isActive()) {
            throw new RuntimeException('This applicant is already deployed as an active employee.');
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
     * The closing sequence of the recruitment lifecycle, in order.
     */
    private const DEPLOYMENT_PATH = [
        'client_evaluation' => 'Endorsed to the client company for evaluation.',
        'approved' => 'Approved by the client company.',
        'deployed' => 'Deployment recorded.',
        'active' => 'Now an active employee.',
    ];

    /**
     * Walks the applicant through the remaining lifecycle steps to "active".
     *
     * Recording a deployment implies the client evaluated and approved the
     * candidate, so any steps not already logged are written here rather than
     * skipped. Each one is historised, which keeps the applicant timeline
     * complete instead of jumping from screening straight to employed.
     */
    private function advanceApplicant(Applicant $applicant, User $actor): void
    {
        $statuses = array_keys(self::DEPLOYMENT_PATH);

        // Resume from wherever the applicant already is, so an approved
        // candidate is never walked backwards into client evaluation.
        $position = array_search($applicant->current_status, $statuses, true);
        $startIndex = $position === false ? 0 : $position + 1;

        for ($i = $startIndex; $i < count($statuses); $i++) {
            $status = $statuses[$i];

            if (! $this->lifecycle->canTransition($applicant->current_status, $status)) {
                continue;
            }

            $this->lifecycle->transition($applicant, $status, $actor, self::DEPLOYMENT_PATH[$status]);
        }
    }

    private function incrementFulfilment(JobRequest $jobRequest): void
    {
        $jobRequest->increment('workers_fulfilled');
        $jobRequest->refresh();

        // forceFill, not update: request_status is deliberately excluded from
        // $fillable so it cannot be set straight from request input. A plain
        // update() here would be silently discarded and a filled request would
        // never close.
        $jobRequest->forceFill([
            'request_status' => match (true) {
                $jobRequest->workers_fulfilled >= $jobRequest->workers_needed => 'fulfilled',
                $jobRequest->workers_fulfilled > 0 => 'partially_fulfilled',
                default => $jobRequest->request_status,
            },
        ])->save();
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
