<?php

namespace Tests\Feature;

use App\Models\EmployeeViolation;
use App\Models\User;
use App\Services\ApplicantLifecycleService;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * CDE's own policy, as confirmed in the follow-up interview.
 *
 * Three rules, and what unites them is that none belongs to the system. A
 * disciplinary record clearing after a year, a fourth offence putting somebody
 * up for review, and training happening before every placement are the agency's
 * decisions — so each is configuration, and each stops short of acting on its
 * own. Reaching a threshold raises a question; an administrator answers it.
 */
class ClientPolicyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function deployedEmployee(User $hr)
    {
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);
        $request = $this->jobRequest($department, $hr, ['workers_needed' => 10]);

        $applicant = $this->applicant($hr, [
            'current_status' => 'ready_for_deployment',
            'applicant_code' => 'APP-TEST-'.uniqid(),
        ]);

        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
        ], $hr)->employee;
    }

    private function violation($employee, User $hr, string $when): EmployeeViolation
    {
        return EmployeeViolation::create([
            'employee_id' => $employee->id,
            'violation_date' => $when,
            'violation_type' => 'awol',
            'description' => 'Absent without leave.',
            'issued_by' => $hr->id,
            'status' => 'open',
        ]);
    }

    // ==========================================  the one-year record

    /**
     * The distinction the whole design rests on: a record that stops counting is
     * not a record that has been destroyed.
     */
    public function test_an_offence_older_than_a_year_stops_counting_but_stays_on_file(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);

        $old = $this->violation($employee, $hr, now()->subMonths(14)->toDateString());
        $recent = $this->violation($employee, $hr, now()->subMonths(2)->toDateString());

        $this->assertTrue($old->isExpired());
        $this->assertTrue($recent->isActive());

        // Still there. Losing it would cost the agency its own history.
        $this->assertSame(2, $employee->violations()->count());
        $this->assertSame(1, $employee->activeViolations()->count());
    }

    public function test_the_record_reports_both_counts_and_marks_the_expired_ones(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);

        $this->violation($employee, $hr, now()->subMonths(20)->toDateString());
        $this->violation($employee, $hr, now()->subMonths(1)->toDateString());

        Sanctum::actingAs($hr);

        $response = $this->getJson("/api/v1/employees/{$employee->id}/violations")->assertOk();

        $this->assertSame(1, $response->json('data.policy.active_count'));
        $this->assertSame(1, $response->json('data.policy.expired_count'));
        $this->assertCount(2, $response->json('data.violations'));

        $expired = collect($response->json('data.violations'))->firstWhere('is_expired', true);
        $this->assertNotNull($expired['expires_on']);
    }

    /**
     * The window is the client's, not ours.
     */
    public function test_the_window_is_configurable(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);

        $this->violation($employee, $hr, now()->subMonths(14)->toDateString());

        $this->assertSame(0, $employee->activeViolations()->count());

        config(['empower.violations.active_window_months' => 24]);

        $this->assertSame(1, $employee->activeViolations()->count());
    }

    // ==========================================  the review threshold

    public function test_the_fourth_offence_raises_the_employee_for_review(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);

        foreach (range(1, 3) as $month) {
            $this->violation($employee, $hr, now()->subMonths($month)->toDateString());
        }

        $this->assertFalse($employee->reachedViolationThreshold());

        $this->violation($employee, $hr, now()->toDateString());

        $this->assertTrue($employee->fresh()->reachedViolationThreshold());
    }

    /**
     * The line that must never move: reaching the threshold changes nothing
     * about the employee's employment. It asks a question.
     */
    public function test_reaching_the_threshold_does_not_terminate_anybody(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);

        Sanctum::actingAs($hr);

        foreach (range(1, 4) as $month) {
            $this->postJson("/api/v1/employees/{$employee->id}/violations", [
                'violation_date' => now()->subMonths($month)->toDateString(),
                'violation_type' => 'awol',
                'description' => 'Absent without leave.',
            ])->assertCreated();
        }

        $employee->refresh();

        $this->assertSame('active', $employee->employment_status);
        $this->assertSame('active', $employee->applicant->fresh()->current_status);
        $this->assertDatabaseCount('terminations', 0);
    }

    public function test_the_response_says_when_the_threshold_is_reached(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);

        Sanctum::actingAs($hr);

        foreach (range(1, 3) as $month) {
            $this->violation($employee, $hr, now()->subMonths($month)->toDateString());
        }

        $response = $this->postJson("/api/v1/employees/{$employee->id}/violations", [
            'violation_date' => now()->toDateString(),
            'violation_type' => 'awol',
            'description' => 'Absent without leave.',
        ])->assertCreated();

        $this->assertTrue($response->json('data.threshold_reached'));
        $this->assertSame(4, $response->json('data.active_count'));
        $this->assertStringContainsString('review threshold', $response->json('message'));
    }

    /**
     * Offences that have aged out do not push anybody over the line.
     */
    public function test_expired_offences_do_not_count_towards_the_threshold(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);

        foreach ([18, 20, 22] as $months) {
            $this->violation($employee, $hr, now()->subMonths($months)->toDateString());
        }
        $this->violation($employee, $hr, now()->toDateString());

        $this->assertSame(4, $employee->violations()->count());
        $this->assertSame(1, $employee->activeViolations()->count());
        $this->assertFalse($employee->reachedViolationThreshold());
    }

    public function test_the_threshold_is_configurable(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);

        $this->violation($employee, $hr, now()->toDateString());
        $this->violation($employee, $hr, now()->subDay()->toDateString());

        $this->assertFalse($employee->reachedViolationThreshold());

        config(['empower.violations.termination_threshold' => 2]);

        $this->assertTrue($employee->reachedViolationThreshold());
    }

    /**
     * Only an administrator finalises. HR may prepare the case.
     */
    public function test_only_an_administrator_can_finalise_a_termination(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);

        Sanctum::actingAs($hr);

        $termination = $this->postJson("/api/v1/employees/{$employee->id}/terminations", [
            'termination_date' => now()->toDateString(),
            'reason' => 'Reached the review threshold for absence without leave.',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/employees/{$employee->id}/terminations/{$termination}/finalise")
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->adminUser());

        $this->postJson("/api/v1/employees/{$employee->id}/terminations/{$termination}/finalise")
            ->assertOk();
    }

    // ==========================================  training before placement

    /**
     * CDE trains every worker before taking them to a client, so an untrained
     * candidate cannot be endorsed.
     */
    public function test_an_untrained_applicant_cannot_be_endorsed_to_a_client(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has not completed training');

        app(ApplicantLifecycleService::class)->transition($applicant, 'client_evaluation', $hr);
    }

    public function test_a_trained_applicant_can_be_endorsed(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        $applicant = app(ApplicantLifecycleService::class)
            ->transition($applicant, 'client_evaluation', $hr);

        $this->assertSame('client_evaluation', $applicant->current_status);
    }

    /**
     * The gate is a policy, not a law of the system — a redeployment of somebody
     * already trained should not need a developer to unblock it.
     */
    public function test_the_training_requirement_is_configurable(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');

        config(['empower.require_training_before_deployment' => false]);

        $applicant = app(ApplicantLifecycleService::class)
            ->transition($applicant, 'client_evaluation', $hr);

        $this->assertSame('client_evaluation', $applicant->current_status);
    }

    public function test_deployment_is_refused_for_an_untrained_applicant(): void
    {
        $hr = $this->hrUser();
        $department = $this->department($this->clientCompany($hr), $hr);
        $request = $this->jobRequest($department, $hr);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has not completed training');

        app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
        ], $hr);
    }

    // ==========================================  policy settings

    public function test_an_administrator_can_change_the_policy(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->putJson('/api/v1/settings', [
            'settings' => [
                ['key' => 'empower.violations.termination_threshold', 'value' => 3],
                ['key' => 'empower.violations.active_window_months', 'value' => 18],
                ['key' => 'empower.retention.unhired_applicant_months', 'value' => 6],
            ],
        ])->assertOk();

        $this->assertSame(3, config('empower.violations.termination_threshold'));
        $this->assertSame(18, config('empower.violations.active_window_months'));
        $this->assertSame(6, config('empower.retention.unhired_applicant_months'));
    }

    public function test_hr_cannot_change_the_policy(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'empower.violations.termination_threshold', 'value' => 3]],
        ])->assertForbidden();
    }

    /**
     * The audit trail is the administrator's, which is what makes "who changed
     * what" answerable at all.
     */
    public function test_hr_cannot_read_the_audit_trail(): void
    {
        Sanctum::actingAs($this->hrUser());
        $this->getJson('/api/v1/audit-logs')->assertForbidden();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->adminUser());
        $this->getJson('/api/v1/audit-logs')->assertOk();
    }

    /**
     * Employment ends two ways and only two ways. There is no retirement
     * anywhere in the system, and this fails if one is ever introduced.
     */
    public function test_there_is_no_retirement_route(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);

        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/employees/{$employee->id}/retirements", [
            'retirement_date' => now()->toDateString(),
        ])->assertNotFound();

        $this->assertSame(
            ['active', 'resigned', 'terminated', 'archived'],
            array_keys(config('empower.employee_transitions')) === ['active', 'resigned', 'terminated', 'archived']
                ? ['active', 'resigned', 'terminated', 'archived']
                : array_keys(config('empower.employee_transitions')),
            'The employee lifecycle offers resignation and termination only.'
        );
    }
}
