<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\Deployment;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Reassigning a deployed worker within their client (audit FN-11, R-14, Q8).
 *
 * Same client only, by the decision of 2026-10-19: a move to another client is
 * a new deployment against that client's request. Every reassignment keeps the
 * previous placement in the history and records each field before and after.
 */
class ReassignmentTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private User $hr;

    private ClientCompany $client;

    private ClientDepartment $production;

    private ClientDepartment $packaging;

    private Deployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        // As in local development, where lazy loading throws.
        Model::preventLazyLoading();

        $this->hr = $this->hrUser();
        $this->client = $this->clientCompany($this->hr);
        $this->production = $this->department($this->client, $this->hr);
        $this->packaging = $this->department($this->client, $this->hr, ['department_code' => 'PACK', 'department_name' => 'Packaging']);

        $applicant = $this->applicant($this->hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $this->hr, 'all');
        $this->completeTraining($applicant, $this->hr);

        $this->deployment = app(DeploymentService::class)->deploy($applicant, $this->jobRequest($this->production, $this->hr), [
            'deployment_date' => now()->subMonth()->toDateString(),
            'supervisor_name' => 'Ms. Reyes',
        ], $this->hr);

        Sanctum::actingAs($this->hr);
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    private function reassign(array $overrides = [])
    {
        return $this->postJson("/api/v1/deployments/{$this->deployment->id}/reassign", array_merge([
            'client_department_id' => $this->packaging->id,
            'position_title' => 'Packer',
            'supervisor_name' => 'Mr. Santos',
            'effective_date' => now()->toDateString(),
            'remarks' => 'Packaging line short of staff.',
        ], $overrides));
    }

    public function test_a_worker_moves_within_their_client_and_the_old_placement_is_kept(): void
    {
        $this->reassign()
            ->assertOk()
            ->assertJsonPath('data.department_name', 'Packaging')
            ->assertJsonPath('data.position_title', 'Packer')
            ->assertJsonPath('data.supervisor_name', 'Mr. Santos')
            ->assertJsonPath('data.history.0.from_department', 'Production')
            ->assertJsonPath('data.history.0.to_department', 'Packaging')
            ->assertJsonPath('data.history.0.reason', 'Packaging line short of staff.')
            ->assertJsonPath('data.history.0.changed_by', $this->hr->full_name);

        $employee = $this->deployment->employee()->first();
        $this->assertSame($this->packaging->id, $employee->current_department_id);
        $this->assertSame('Packer', $employee->current_position_title);
        $this->assertSame('Mr. Santos', $employee->current_supervisor_name);

        // The employee record shows the move in names.
        $this->getJson("/api/v1/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.employee.current_department', 'Packaging')
            ->assertJsonPath('data.employee.deployments.0.history.0.to_department', 'Packaging');

        $entry = AuditLog::where('record_type', 'Deployment')->where('record_id', $this->deployment->id)
            ->where('action_type', 'update')->sole();
        $this->assertSame('Production', $entry->old_values_json['department']);
        $this->assertSame('Packaging', $entry->new_values_json['department']);
        $this->assertSame('Ms. Reyes', $entry->old_values_json['supervisor_name']);
        $this->assertSame($this->client->id, $entry->client_company_id);
    }

    public function test_a_move_to_another_client_is_refused(): void
    {
        $other = $this->clientCompany($this->hr, ['company_name' => 'Another Client']);
        $elsewhere = $this->department($other, $this->hr, ['department_code' => 'X']);

        $this->reassign(['client_company_id' => $other->id, 'client_department_id' => $elsewhere->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // Naming the current client but another client's department fails too.
        $this->reassign(['client_department_id' => $elsewhere->id])->assertStatus(422);

        $this->assertSame($this->production->id, $this->deployment->fresh()->client_department_id);
        $this->assertSame(0, $this->deployment->history()->count());
    }

    public function test_an_inactive_department_cannot_receive_the_worker(): void
    {
        $this->packaging->update(['status' => 'inactive']);

        $this->reassign()->assertStatus(422);
    }

    public function test_the_date_must_fall_within_the_placement_and_not_in_the_future(): void
    {
        $this->reassign(['effective_date' => now()->addDay()->toDateString()])->assertStatus(422);
        $this->reassign(['effective_date' => now()->subYear()->toDateString()])->assertStatus(422);

        $this->reassign(['effective_date' => now()->subDays(3)->toDateString()])->assertOk();

        // Not earlier than the move just recorded, either.
        $this->reassign([
            'client_department_id' => $this->production->id,
            'effective_date' => now()->subDays(10)->toDateString(),
        ])->assertStatus(422);
    }

    public function test_a_reason_is_required_and_a_change_that_changes_nothing_is_refused(): void
    {
        $this->reassign(['remarks' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('remarks');

        $this->reassign([
            'client_department_id' => $this->production->id,
            'position_title' => $this->deployment->position_title,
            'supervisor_name' => 'Ms. Reyes',
        ])->assertStatus(422);
    }

    public function test_only_an_active_placement_can_be_reassigned(): void
    {
        $this->deployment->forceFill(['deployment_status' => 'ended'])->save();

        $this->reassign()->assertStatus(409);
    }

    public function test_a_coordinator_cannot_reassign(): void
    {
        $coordinator = User::factory()->create([
            'email' => 'coord.'.uniqid().'@cdemanpower.local',
            'user_type' => 'coordinator',
        ]);
        $coordinator->assignRole('coordinator');
        Sanctum::actingAs($coordinator);

        $this->reassign()->assertForbidden();
        $this->assertSame($this->production->id, $this->deployment->fresh()->client_department_id);
    }
}
