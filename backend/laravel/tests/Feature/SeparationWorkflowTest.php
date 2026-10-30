<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The end of the employment lifecycle, as the review panel asked for it.
 *
 * "HR process should be from application to resignation/termination", and "in
 * termination, add a confirmation box". These tests hold the system to both:
 * the applicant record follows the employee out of employment, nothing ends a
 * person's employment without an explicit confirmation, a termination's review
 * can end without a dismissal, and none of it can be reached by the employee
 * through the staff routes or by changing a status word.
 */
class SeparationWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function deployedEmployee(User $hr, string $deployedOn = null): Employee
    {
        $department = $this->department($this->clientCompany($hr), $hr);
        $request = $this->jobRequest($department, $hr, ['workers_needed' => 10]);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => $deployedOn ?? now()->subMonths(3)->toDateString(),
        ], $hr)->employee;
    }

    private function portalUserFor(Employee $employee): User
    {
        $user = User::factory()->create([
            'email' => 'employee'.$employee->id.'@example.test',
            'user_type' => 'employee',
            'applicant_id' => $employee->applicant_id,
            'employee_id' => $employee->id,
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('portal');

        return $user;
    }

    // ============================================== the lifecycle reaches the end

    public function test_a_completed_resignation_closes_the_applicant_record_too(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        Sanctum::actingAs($hr);

        $exit = now()->addDays(30)->toDateString();
        $resignation = $this->postJson("/api/v1/employees/{$employee->id}/resignations", [
            'reason' => 'Moving to another province',
            'filing_date' => now()->toDateString(),
            'rendering_days' => 30,
            'exit_date' => $exit,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/employees/{$employee->id}/resignations/{$resignation}", [
            'clearance_status' => 'cleared',
        ])->assertOk();

        $this->postJson("/api/v1/employees/{$employee->id}/resignations/{$resignation}/complete", ['confirm' => true])
            ->assertOk();

        $this->assertSame('resigned', $employee->fresh()->employment_status);
        $this->assertSame('resigned', $employee->applicant->fresh()->current_status);
        $this->assertDatabaseHas('application_status_history', [
            'applicant_id' => $employee->applicant_id,
            'from_status' => 'active',
            'to_status' => 'resigned',
        ]);

        // The placement ends on the day the person actually left, not on the
        // day somebody processed the paperwork.
        $this->assertSame($exit, $employee->deployments()->first()->end_date->toDateString());

        // History is kept: the record is archived, never deleted.
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('archives', ['entity_type' => 'employee', 'entity_id' => $employee->id]);
    }

    public function test_a_finalised_termination_closes_the_applicant_record_and_the_portal_says_so(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        Sanctum::actingAs($hr);

        $terminatedOn = now()->subDay()->toDateString();
        $termination = $this->postJson("/api/v1/employees/{$employee->id}/terminations", [
            'reason' => 'Fourth AWOL within twelve months',
            'termination_date' => $terminatedOn,
            'status' => 'for_review',
        ])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->adminUser());

        $this->postJson("/api/v1/employees/{$employee->id}/terminations/{$termination}/finalise", ['confirm' => true])
            ->assertOk();

        $this->assertSame('terminated', $employee->applicant->fresh()->current_status);
        $this->assertSame($terminatedOn, $employee->deployments()->first()->end_date->toDateString());
        $this->assertDatabaseHas('terminations', ['id' => $termination, 'status' => 'finalized']);
        $this->assertNotNull(\App\Models\Termination::find($termination)->finalized_at);

        // The employee's own view no longer claims they are deployed.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->portalUserFor($employee->fresh()));

        $this->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.application.status', 'terminated')
            ->assertJsonPath(
                'data.application.explanation',
                'Your employment record is closed. Please contact the HR office with any questions.'
            );
    }

    // ===================================================== the confirmation box

    public function test_finalising_a_termination_without_confirmation_changes_nothing(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        Sanctum::actingAs($hr);

        $termination = $this->postJson("/api/v1/employees/{$employee->id}/terminations", [
            'reason' => 'Misconduct',
            'termination_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->adminUser());

        $this->postJson("/api/v1/employees/{$employee->id}/terminations/{$termination}/finalise")
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');

        $this->postJson("/api/v1/employees/{$employee->id}/terminations/{$termination}/finalise", ['confirm' => false])
            ->assertStatus(422);

        $this->assertSame('active', $employee->fresh()->employment_status);
        $this->assertSame('active', $employee->applicant->fresh()->current_status);
        $this->assertDatabaseHas('terminations', ['id' => $termination, 'status' => 'draft']);
        $this->assertDatabaseMissing('archives', ['entity_type' => 'employee', 'entity_id' => $employee->id]);
    }

    public function test_completing_a_resignation_also_requires_confirmation(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        Sanctum::actingAs($hr);

        $resignation = $this->postJson("/api/v1/employees/{$employee->id}/resignations", [
            'reason' => 'Personal',
            'filing_date' => now()->toDateString(),
            'exit_date' => now()->addDays(30)->toDateString(),
        ])->json('data.id');

        $this->putJson("/api/v1/employees/{$employee->id}/resignations/{$resignation}", ['clearance_status' => 'cleared']);

        $this->postJson("/api/v1/employees/{$employee->id}/resignations/{$resignation}/complete")
            ->assertStatus(422);

        $this->assertSame('active', $employee->fresh()->employment_status);
    }

    // ========================================= a review can end without a dismissal

    public function test_a_termination_can_be_withdrawn_and_the_employee_stays_active(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        Sanctum::actingAs($hr);

        $termination = $this->postJson("/api/v1/employees/{$employee->id}/terminations", [
            'reason' => 'Reported AWOL',
            'termination_date' => now()->toDateString(),
            'status' => 'for_review',
        ])->json('data.id');

        // A reason is required: a dropped case must say why it was dropped.
        $this->postJson("/api/v1/employees/{$employee->id}/terminations/{$termination}/withdraw")
            ->assertStatus(422);

        $this->postJson("/api/v1/employees/{$employee->id}/terminations/{$termination}/withdraw", [
            'reason' => 'Absence was covered by a medical certificate submitted late.',
        ])->assertOk();

        $this->assertDatabaseHas('terminations', [
            'id' => $termination,
            'status' => 'withdrawn',
            'withdrawn_by' => $hr->id,
            'withdrawal_reason' => 'Absence was covered by a medical certificate submitted late.',
        ]);
        $this->assertSame('active', $employee->fresh()->employment_status);

        // A withdrawn termination cannot be finalised afterwards.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->adminUser());

        $this->postJson("/api/v1/employees/{$employee->id}/terminations/{$termination}/finalise", ['confirm' => true])
            ->assertStatus(400);

        $this->assertSame('active', $employee->fresh()->employment_status);
    }

    public function test_only_one_open_case_of_each_kind_at_a_time(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        Sanctum::actingAs($hr);

        $termination = [
            'reason' => 'Misconduct',
            'termination_date' => now()->toDateString(),
        ];

        $this->postJson("/api/v1/employees/{$employee->id}/terminations", $termination)->assertCreated();
        $this->postJson("/api/v1/employees/{$employee->id}/terminations", $termination)->assertStatus(400);

        $resignation = ['reason' => 'Personal', 'filing_date' => now()->toDateString()];

        $this->postJson("/api/v1/employees/{$employee->id}/resignations", $resignation)->assertCreated();
        $this->postJson("/api/v1/employees/{$employee->id}/resignations", $resignation)->assertStatus(400);
    }

    public function test_a_completed_resignation_cannot_be_edited_afterwards(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        Sanctum::actingAs($hr);

        $id = $this->postJson("/api/v1/employees/{$employee->id}/resignations", [
            'reason' => 'Personal',
            'filing_date' => now()->toDateString(),
            'exit_date' => now()->addDays(30)->toDateString(),
        ])->json('data.id');

        $this->putJson("/api/v1/employees/{$employee->id}/resignations/{$id}", ['clearance_status' => 'cleared']);
        $this->postJson("/api/v1/employees/{$employee->id}/resignations/{$id}/complete", ['confirm' => true])->assertOk();

        $this->putJson("/api/v1/employees/{$employee->id}/resignations/{$id}", ['status' => 'withdrawn'])
            ->assertStatus(409);

        $this->assertDatabaseHas('resignations', ['id' => $id, 'status' => 'completed']);
    }

    // =============================== no shortcut around the separation workflow

    public function test_employment_and_separation_statuses_cannot_be_set_by_hand(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        Sanctum::actingAs($hr);

        // The one-click "Terminated" button this used to allow.
        $this->patchJson("/api/v1/applicants/{$employee->applicant_id}/status", ['to_status' => 'terminated'])
            ->assertStatus(409);
        $this->patchJson("/api/v1/applicants/{$employee->applicant_id}/status", ['to_status' => 'resigned'])
            ->assertStatus(409);

        $this->assertSame('active', $employee->applicant->fresh()->current_status);
        $this->assertSame('active', $employee->fresh()->employment_status);
        $this->assertDatabaseCount('terminations', 0);

        // Nor is either offered on the applicant record.
        $this->getJson("/api/v1/applicants/{$employee->applicant_id}")
            ->assertOk()
            ->assertJsonPath('data.allowed_transitions', []);
    }

    public function test_deployed_cannot_be_set_without_recording_a_deployment(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'approved']);
        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", ['to_status' => 'deployed'])
            ->assertStatus(409);

        $this->assertSame('approved', $applicant->fresh()->current_status);
        $this->assertDatabaseCount('employees', 0);

        // Still offered, because the screen answers it with the deployment form.
        $this->assertContains(
            'deployed',
            $this->getJson("/api/v1/applicants/{$applicant->id}")->json('data.allowed_transitions')
        );
    }

    // ================================================ the employee's own access

    public function test_an_employee_cannot_file_a_termination_against_themselves(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        Sanctum::actingAs($this->portalUserFor($employee));

        $this->postJson("/api/v1/employees/{$employee->id}/terminations", [
            'reason' => 'I quit',
            'termination_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertDatabaseCount('terminations', 0);
    }

    public function test_an_employee_cannot_clear_their_own_resignation(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        $user = $this->portalUserFor($employee);
        Sanctum::actingAs($user);

        // Filing through the portal is theirs to do.
        $this->postJson('/api/v1/portal/resignation', [
            'reason' => 'Family reasons',
            'requested_exit_date' => now()->addDays(30)->toDateString(),
            'acknowledged' => true,
        ])->assertCreated();

        $resignation = $employee->resignations()->first();

        // Clearing and accepting it are not.
        $this->putJson("/api/v1/employees/{$employee->id}/resignations/{$resignation->id}", [
            'clearance_status' => 'cleared',
            'status' => 'accepted',
            'exit_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertDatabaseHas('resignations', [
            'id' => $resignation->id,
            'clearance_status' => 'pending',
            'status' => 'filed',
        ]);
    }

    public function test_an_applicant_cannot_edit_their_own_scored_attributes_through_the_staff_route(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);

        $user = User::factory()->create([
            'email' => 'applicant'.$applicant->id.'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]);
        $user->assignRole('portal');
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/applicants/{$applicant->id}", [
            'communication_rating' => 5,
            'reliability_rating' => 5,
            'height_cm' => 190,
        ])->assertForbidden();

        $fresh = $applicant->fresh();
        $this->assertNull($fresh->communication_rating);
        $this->assertNull($fresh->height_cm);

        // Their own contact details remain theirs to correct, through the portal.
        // Written with spaces, stored in the standard 11-digit form.
        $this->patchJson('/api/v1/portal/profile', ['contact_number' => '0917 555 0101'])->assertOk();
        $this->assertSame('09175550101', $applicant->fresh()->contact_number);
    }

    public function test_the_employee_record_reports_what_can_be_done_with_each_case(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/employees/{$employee->id}/terminations", [
            'reason' => 'Misconduct',
            'termination_date' => now()->toDateString(),
            'status' => 'for_review',
        ]);

        $termination = $this->getJson("/api/v1/employees/{$employee->id}")->assertOk()->json('data.termination');

        $this->assertTrue($termination['is_open']);
        $this->assertSame($hr->full_name, $termination['filed_by']);
        $this->assertFalse($termination['has_documents']);
        // Storage paths stay on the server.
        $this->assertArrayNotHasKey('documents_path', $termination);
    }
}
