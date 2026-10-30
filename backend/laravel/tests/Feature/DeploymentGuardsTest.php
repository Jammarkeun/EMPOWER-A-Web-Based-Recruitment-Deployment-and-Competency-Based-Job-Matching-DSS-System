<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\JobRequest;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The guards against conflicting deployments that can be shown in one process
 * (audit S-04). ConcurrentDeploymentTest shows the same rules holding between
 * real simultaneous requests against PostgreSQL and MariaDB.
 *
 * A deployment re-reads the applicant and the request under lock rather than
 * trusting the copies it was handed, and the database itself refuses a second
 * active deployment for one employee and a headcount past what was asked for.
 */
class DeploymentGuardsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function trainedApplicant(User $hr): Applicant
    {
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return $applicant;
    }

    private function request(User $hr, int $workersNeeded = 5): JobRequest
    {
        return $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr, ['workers_needed' => $workersNeeded]);
    }

    private function deploy(Applicant $applicant, JobRequest $request, User $hr): Deployment
    {
        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
        ], $hr);
    }

    public function test_a_copy_of_the_applicant_loaded_before_their_deployment_cannot_deploy_them_again(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->trainedApplicant($hr);
        $stale = Applicant::find($applicant->id);

        $this->deploy(Applicant::find($applicant->id), $this->request($hr), $hr);

        try {
            $this->deploy($stale, $this->request($hr), $hr);
            $this->fail('A second deployment went through.');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('already deployed', $e->getMessage());
        }

        $this->assertSame(1, Deployment::count());
    }

    public function test_a_copy_of_the_request_loaded_before_its_last_seat_was_taken_cannot_overfill_it(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr, 1);
        $stale = JobRequest::find($request->id);

        $this->deploy($this->trainedApplicant($hr), JobRequest::find($request->id), $hr);

        try {
            $this->deploy($this->trainedApplicant($hr), $stale, $hr);
            $this->fail('The request was filled past its headcount.');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->assertSame(1, (int) $request->fresh()->workers_fulfilled);
        $this->assertSame(1, Deployment::where('job_request_id', $request->id)->count());
    }

    public function test_the_database_holds_at_most_one_active_deployment_per_employee(): void
    {
        $hr = $this->hrUser();
        $deployment = $this->deploy($this->trainedApplicant($hr), $this->request($hr), $hr);
        $this->assertSame($deployment->employee_id, (int) $deployment->fresh()->active_employee_id);

        $second = fn (string $status) => Deployment::create([
            'deployment_code' => 'DEP-TEST-'.uniqid(),
            'employee_id' => $deployment->employee_id,
            'job_request_id' => $deployment->job_request_id,
            'client_company_id' => $deployment->client_company_id,
            'client_department_id' => $deployment->client_department_id,
            'position_title' => 'Production Helper',
            'deployment_date' => now()->toDateString(),
            'deployment_status' => $status,
            'created_by' => $hr->id,
        ]);

        // Bypassing the service entirely: the database still says no.
        $refused = false;
        try {
            DB::transaction(fn () => $second('active'));
        } catch (UniqueConstraintViolationException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'The database accepted a second active deployment.');

        // Past placements are not limited.
        $ended = $second('ended');
        $this->assertNull($ended->fresh()->active_employee_id);
    }

    public function test_ending_employment_releases_the_employee_for_a_later_placement(): void
    {
        $hr = $this->hrUser();
        $deployment = $this->deploy($this->trainedApplicant($hr), $this->request($hr), $hr);
        $employee = Employee::find($deployment->employee_id);

        Sanctum::actingAs($hr);
        $termination = $this->postJson("/api/v1/employees/{$employee->id}/terminations", [
            'reason' => 'End of contract with the client.',
            'termination_date' => now()->toDateString(),
            'status' => 'for_review',
        ])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->adminUser());
        $this->postJson("/api/v1/employees/{$employee->id}/terminations/{$termination}/finalise", ['confirm' => true])
            ->assertOk();

        $deployment->refresh();
        $this->assertSame('ended', $deployment->deployment_status);
        $this->assertNull($deployment->active_employee_id);

        // The guard no longer stands in the way of a new active placement.
        $next = Deployment::create([
            'deployment_code' => 'DEP-TEST-'.uniqid(),
            'employee_id' => $employee->id,
            'job_request_id' => $deployment->job_request_id,
            'client_company_id' => $deployment->client_company_id,
            'client_department_id' => $deployment->client_department_id,
            'position_title' => 'Production Helper',
            'deployment_date' => now()->toDateString(),
            'deployment_status' => 'active',
            'created_by' => $hr->id,
        ]);
        $this->assertSame($employee->id, (int) $next->fresh()->active_employee_id);
    }

    /**
     * A failure after the employee and the deployment are written - here, in
     * the request's status change at the very end - undoes all of it.
     */
    public function test_a_failure_part_way_through_a_deployment_leaves_nothing_behind(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->trainedApplicant($hr);
        $request = $this->request($hr);

        $this->app->instance(\App\Services\JobRequestLifecycleService::class, new class(app(\App\Services\AuditService::class)) extends \App\Services\JobRequestLifecycleService
        {
            public function syncToHeadcount(JobRequest $jobRequest, User $actor, string $reason, string $source): JobRequest
            {
                throw new RuntimeException('Simulated failure at the last step.', 500);
            }
        });

        try {
            $this->deploy($applicant, $request, $hr);
            $this->fail('The simulated failure did not surface.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure at the last step.', $e->getMessage());
        }

        $this->assertSame(0, Deployment::count());
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, (int) $request->fresh()->workers_fulfilled);
        $this->assertSame('ready_for_deployment', $applicant->fresh()->current_status);
        $this->assertSame(0, $applicant->statusHistory()->whereIn('to_status', ['deployed', 'active'])->count());
    }

    /**
     * The headcount is counted in one conditional statement, so even a path
     * that skipped the lock and the checks could not count past what was
     * asked for.
     */
    public function test_the_headcount_is_never_counted_past_the_number_needed(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr, 1);
        $request->forceFill(['workers_fulfilled' => 1])->save();

        $increment = new ReflectionMethod(DeploymentService::class, 'incrementFulfilment');

        try {
            $increment->invoke(app(DeploymentService::class), $request, new Deployment(['deployment_code' => 'DEP-TEST']), $hr);
            $this->fail('The headcount was counted past the number needed.');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->assertSame(1, (int) $request->fresh()->workers_fulfilled);
    }
}
