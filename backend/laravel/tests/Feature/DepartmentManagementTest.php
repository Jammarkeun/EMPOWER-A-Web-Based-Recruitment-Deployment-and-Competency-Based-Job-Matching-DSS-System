<?php

namespace Tests\Feature;

use App\Models\ClientDepartment;
use App\Models\Deployment;
use App\Models\DeploymentHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CoordinatorFixtures;
use Tests\TestCase;

/**
 * Departments of a client, and workers moving between them (CDE meeting,
 * 26 October 2026): "Kunyari sa BTW may bagong department" - a client adds a
 * department, the short code is only a label, and a worker can be moved to
 * another department at any time with the move kept on record.
 */
class DepartmentManagementTest extends TestCase
{
    use CoordinatorFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoClients();
    }

    public function test_hr_adds_a_department_and_the_short_code_is_optional(): void
    {
        $this->actAs($this->hr);
        $url = "/api/v1/clients/{$this->clientA->id}/departments";

        $this->postJson($url, ['department_name' => 'Shake Department'])
            ->assertCreated()->assertJsonPath('data.department_code', 'SD');
        // Unique within the client.
        $this->postJson($url, ['department_name' => 'Sales Desk'])
            ->assertCreated()->assertJsonPath('data.department_code', 'SD2');
        $this->postJson($url, ['department_name' => 'Production', 'department_code' => 'PRD'])
            ->assertCreated()->assertJsonPath('data.department_code', 'PRD');
        $this->postJson($url, ['department_name' => 'Shake Department'])
            ->assertStatus(422)->assertJsonValidationErrors('department_name');

        // Another client may have its own "Shake Department", code and all.
        $this->postJson("/api/v1/clients/{$this->clientB->id}/departments", ['department_name' => 'Shake Department'])
            ->assertCreated()->assertJsonPath('data.department_code', 'SD');

        // Clearing the code on an edit keeps the one it has.
        $shake = ClientDepartment::where('client_company_id', $this->clientA->id)->where('department_name', 'Shake Department')->first();
        $this->putJson("{$url}/{$shake->id}", ['department_name' => 'Shake Line', 'department_code' => ''])
            ->assertOk()->assertJsonPath('data.department_code', 'SD');

        $this->assertDatabaseHas('audit_logs', ['module_key' => 'clients', 'action_type' => 'create', 'record_type' => 'ClientDepartment']);

        // The client's coordinator sees it; they cannot add one.
        $coordinator = $this->coordinatorFor($this->clientA);
        $this->actAs($coordinator);
        $names = array_column($this->getJson('/api/v1/coordinator/company')->json('data.departments'), 'department_name');
        $this->assertContains('Shake Line', $names);
        $this->postJson($url, ['department_name' => 'Not allowed'])->assertForbidden();
    }

    public function test_a_worker_moves_department_with_the_move_kept_and_a_staffed_department_cannot_be_switched_off(): void
    {
        $chocolate = $this->department($this->clientA, $this->hr, ['department_name' => 'Chocolate Department', 'department_code' => 'CHOCO']);
        $employee = $this->placeEmployee($this->deptA, 'Juan', 'Dela Cruz');
        $deployment = Deployment::where('employee_id', $employee->id)->firstOrFail();

        $this->actAs($this->hr);

        // Workers are still placed in Loading, so it stays on.
        $this->putJson("/api/v1/clients/{$this->clientA->id}/departments/{$this->deptA->id}", ['status' => 'inactive'])
            ->assertStatus(409);
        $this->assertSame('active', $this->deptA->fresh()->status);

        // "Nalipat na ako sa chocolate" - moved, any working day.
        $this->postJson("/api/v1/deployments/{$deployment->id}/reassign", [
            'client_department_id' => $chocolate->id,
            'effective_date' => now()->toDateString(),
            'remarks' => 'Chocolate line short of staff.',
        ])->assertOk();

        $this->assertSame($chocolate->id, $employee->fresh()->current_department_id);
        $this->assertSame(1, DeploymentHistory::where('deployment_id', $deployment->id)->count());

        // Both departments tell the story.
        $out = $this->getJson("/api/v1/clients/{$this->clientA->id}/departments/{$this->deptA->id}/employees")->assertOk();
        $this->assertSame('out', $out->json('data.moves.0.direction'));
        $this->assertSame('Chocolate Department', $out->json('data.moves.0.to_department'));
        $in = $this->getJson("/api/v1/clients/{$this->clientA->id}/departments/{$chocolate->id}/employees")->assertOk();
        $this->assertSame('in', $in->json('data.moves.0.direction'));
        $this->assertSame('Juan Dela Cruz', $in->json('data.moves.0.employee.full_name'));
        $this->assertSame($this->hr->full_name, $in->json('data.moves.0.changed_by'));

        // Empty now, Loading can be switched off - and nothing moves into it.
        $this->putJson("/api/v1/clients/{$this->clientA->id}/departments/{$this->deptA->id}", ['status' => 'inactive'])
            ->assertOk();
        $this->postJson("/api/v1/deployments/{$deployment->id}/reassign", [
            'client_department_id' => $this->deptA->id,
            'effective_date' => now()->toDateString(),
        ])->assertStatus(422);

        // A move to another client's department is not a reassignment.
        $this->postJson("/api/v1/deployments/{$deployment->id}/reassign", [
            'client_department_id' => $this->deptB->id,
            'effective_date' => now()->toDateString(),
        ])->assertStatus(422);
        $this->assertSame(1, DeploymentHistory::where('deployment_id', $deployment->id)->count());
    }
}
