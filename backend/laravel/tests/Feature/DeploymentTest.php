<?php

namespace Tests\Feature;

use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Deployment is the highest-impact action in the system and the one the agency
 * cannot cleanly undo, so each precondition has its own test.
 */
class DeploymentTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_successful_deployment_converts_applicant_to_employee(): void
    {
        $hr = $this->hrUser();
        $department = $this->department($this->clientCompany($hr), $hr);
        $request = $this->jobRequest($department, $hr, ['workers_needed' => 2]);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        $deployment = app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
            'supervisor_name' => 'Mark Reyes',
        ], $hr);

        $this->assertNotNull($deployment->employee);
        $this->assertSame('active', $applicant->fresh()->current_status);
        $this->assertSame('active', $deployment->employee->employment_status);
        $this->assertStringStartsWith('EMP-', $deployment->employee->employee_number);

        // The request counter moves, so the remaining headcount stays truthful.
        $this->assertSame(1, $request->fresh()->workers_fulfilled);
        $this->assertSame('partially_fulfilled', $request->fresh()->request_status);

        // The recruitment history survives the conversion.
        $this->assertSame($applicant->id, $deployment->employee->applicant_id);
        $this->assertDatabaseHas('employee_status_history', [
            'employee_id' => $deployment->employee->id,
            'to_status' => 'active',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action_type' => 'deployment',
            'module_key' => 'deployment',
        ]);
    }

    public function test_cannot_deploy_applicant_with_outstanding_requirements(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'primary');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outstanding requirements');

        app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
        ], $hr);
    }

    public function test_cannot_deploy_against_a_fulfilled_request(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr, [
            'workers_needed' => 1,
            'request_status' => 'fulfilled',
        ]);
        $request->forceFill(['workers_fulfilled' => 1])->save();

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot accept another deployment');

        app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
        ], $hr);
    }

    public function test_cannot_deploy_to_an_inactive_client_company(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr, ['status' => 'inactive']);
        $request = $this->jobRequest($this->department($company, $hr), $hr);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('client company is inactive');

        app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
        ], $hr);
    }

    public function test_cannot_deploy_an_applicant_still_in_screening(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be approved or ready for deployment');

        app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
        ], $hr);
    }

    /**
     * A failed deployment must leave nothing behind. Without the surrounding
     * transaction an employee record could survive a later failure, leaving a
     * person who is an employee of no one.
     */
    public function test_failed_deployment_leaves_no_partial_records(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'primary');

        try {
            app(DeploymentService::class)->deploy($applicant, $request, [
                'deployment_date' => now()->toDateString(),
            ], $hr);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('deployments', 0);
        $this->assertSame(0, $request->fresh()->workers_fulfilled);
        $this->assertSame('ready_for_deployment', $applicant->fresh()->current_status);
    }

    public function test_filling_the_last_slot_marks_the_request_fulfilled(): void
    {
        $hr = $this->hrUser();
        $department = $this->department($this->clientCompany($hr), $hr);
        $request = $this->jobRequest($department, $hr, ['workers_needed' => 1]);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
        ], $hr);

        $this->assertSame('fulfilled', $request->fresh()->request_status);
        $this->assertSame(0, $request->fresh()->remaining_headcount);
    }

    public function test_reassignment_preserves_the_previous_placement(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $production = $this->department($company, $hr);
        $warehouse = $this->department($company, $hr, [
            'department_code' => 'WHSE',
            'department_name' => 'Warehouse',
        ]);
        $request = $this->jobRequest($production, $hr);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        $deployment = app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
        ], $hr);

        app(DeploymentService::class)->reassign($deployment, [
            'client_company_id' => $company->id,
            'client_department_id' => $warehouse->id,
            'effective_date' => now()->addWeek()->toDateString(),
            'change_type' => 'transfer',
        ], $hr);

        $this->assertDatabaseHas('deployment_history', [
            'deployment_id' => $deployment->id,
            'from_department_id' => $production->id,
            'to_department_id' => $warehouse->id,
            'change_type' => 'transfer',
        ]);

        $this->assertSame($warehouse->id, $deployment->fresh()->client_department_id);
        $this->assertSame($warehouse->id, $deployment->employee->fresh()->current_department_id);
    }
}
