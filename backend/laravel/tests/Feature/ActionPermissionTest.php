<?php

namespace Tests\Feature;

use App\Models\ApplicantRequirement;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\RequirementType;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Audit R-26: each sensitive action checks its own permission.
 *
 * The permission list promised finer control than the code gave: anyone with
 * users.view could create accounts, reset passwords and assign roles; viewing
 * an employee was enough to read their violations and evidence; and several
 * declared permissions were never checked at all. These tests use roles that
 * hold some permissions and not others, so a missing check shows up. HR and
 * the administrator hold every permission involved, so their access is
 * unchanged - which the last test confirms.
 */
class ActionPermissionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Storage::fake('documents');
    }

    /** A staff account holding exactly these permissions. */
    private function staffWith(array $permissions): User
    {
        $role = Role::create(['name' => 'limited-'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['email' => 'limited.'.uniqid().'@cde.test', 'user_type' => 'hr']);
        $user->assignRole($role);

        return $user;
    }

    private function deployedEmployee(User $hr): Employee
    {
        $department = $this->department($this->clientCompany($hr), $hr);
        $request = $this->jobRequest($department, $hr, ['workers_needed' => 10]);
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->subMonth()->toDateString(),
        ], $hr)->employee;
    }

    public function test_viewing_users_does_not_allow_changing_them(): void
    {
        $target = $this->hrUser();
        $viewer = $this->staffWith(['users.view']);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/users')->assertOk();
        $this->getJson("/api/v1/users/{$target->id}")->assertOk();

        $this->postJson('/api/v1/users', [
            'first_name' => 'New', 'last_name' => 'Staff', 'email' => 'new.'.uniqid().'@cde.test',
            'user_type' => 'hr', 'role' => 'hr',
            'password' => 'a-long-password-123', 'password_confirmation' => 'a-long-password-123',
        ])->assertForbidden();
        $this->patchJson("/api/v1/users/{$target->id}", ['first_name' => 'Renamed'])->assertForbidden();
        $this->patchJson("/api/v1/users/{$target->id}/status", ['is_active' => false])->assertForbidden();
        $this->postJson("/api/v1/users/{$target->id}/reset-password", [
            'password' => 'a-long-password-123', 'password_confirmation' => 'a-long-password-123',
        ])->assertForbidden();
        $this->postJson('/api/v1/users/portal-access', [
            'applicant_id' => $this->applicant($target)->id, 'email' => 'x.'.uniqid().'@x.test',
        ])->assertForbidden();

        $this->assertSame($target->first_name, $target->fresh()->first_name);
        $this->assertTrue((bool) $target->fresh()->is_active);
    }

    public function test_editing_users_does_not_allow_assigning_roles_and_a_refusal_changes_nothing(): void
    {
        $target = $this->hrUser();
        Sanctum::actingAs($this->staffWith(['users.view', 'users.update']));

        $this->patchJson("/api/v1/users/{$target->id}", ['first_name' => 'Renamed'])->assertOk();

        $this->patchJson("/api/v1/users/{$target->id}", ['first_name' => 'Again', 'role' => 'admin'])->assertForbidden();

        $this->assertSame('Renamed', $target->fresh()->first_name);
        $this->assertTrue($target->fresh()->hasRole('hr'));
        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    public function test_a_portal_account_cannot_be_given_a_staff_role(): void
    {
        $admin = $this->adminUser();
        $applicant = $this->applicant($admin);
        $portal = User::factory()->create(['email' => 'p.'.uniqid().'@x.test', 'user_type' => 'applicant', 'applicant_id' => $applicant->id]);
        $portal->assignRole('portal');

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/users/{$portal->id}", ['role' => 'admin'])->assertStatus(422);

        $this->assertTrue($portal->fresh()->hasRole('portal'));
        $this->assertFalse($portal->fresh()->hasRole('admin'));
    }

    public function test_seeing_an_employee_does_not_include_their_violations_without_that_permission(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        $violation = EmployeeViolation::create([
            'employee_id' => $employee->id, 'violation_date' => now()->toDateString(),
            'violation_type' => 'late', 'description' => 'Late', 'status' => 'open',
            'evidence_path' => "violations/{$employee->id}/e.pdf", 'issued_by' => $hr->id,
        ]);

        Sanctum::actingAs($this->staffWith(['employees.view']));
        $this->getJson("/api/v1/employees/{$employee->id}")->assertOk();
        $this->getJson("/api/v1/employees/{$employee->id}/violations")->assertForbidden();
        $this->getJson("/api/v1/employees/{$employee->id}/violations/{$violation->id}/evidence")->assertForbidden();
    }

    public function test_recording_a_violation_does_not_allow_editing_one(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        $violation = EmployeeViolation::create([
            'employee_id' => $employee->id, 'violation_date' => now()->toDateString(),
            'violation_type' => 'late', 'description' => 'Late', 'status' => 'open', 'issued_by' => $hr->id,
        ]);

        Sanctum::actingAs($this->staffWith(['employees.view', 'violations.view', 'violations.create']));
        $this->putJson("/api/v1/employees/{$employee->id}/violations/{$violation->id}", ['status' => 'resolved'])
            ->assertForbidden();
        $this->assertSame('open', $violation->fresh()->status);

        $this->postJson("/api/v1/employees/{$employee->id}/violations", [
            'violation_date' => now()->toDateString(), 'violation_type' => 'absences', 'description' => 'Absent',
        ])->assertCreated();
    }

    public function test_seeing_a_request_does_not_include_its_rankings_without_matching_view(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        Sanctum::actingAs($this->staffWith(['job_requests.view']));
        $this->getJson("/api/v1/job-requests/{$request->id}")->assertOk();
        $this->getJson("/api/v1/job-requests/{$request->id}/rankings")->assertForbidden();
    }

    public function test_seeing_an_applicant_does_not_include_their_documents_without_requirements_view(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->firstOrFail();
        $requirement = ApplicantRequirement::updateOrCreate(
            ['applicant_id' => $applicant->id, 'requirement_type_id' => $type->id],
            ['status' => 'submitted', 'file_path' => "requirements/{$applicant->id}/d.pdf", 'file_name' => 'd.pdf']
        );

        Sanctum::actingAs($this->staffWith(['applicants.view']));
        $this->getJson("/api/v1/applicants/{$applicant->id}")->assertOk();
        $this->getJson("/api/v1/applicants/{$applicant->id}/requirements")->assertForbidden();
        $this->getJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}/download")->assertForbidden();
        $this->getJson('/api/v1/documents/applicants')->assertForbidden();
        $this->getJson("/api/v1/documents/file/{$requirement->id}")->assertForbidden();
    }

    public function test_moving_applicants_does_not_include_archiving_them(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);

        Sanctum::actingAs($this->staffWith(['applicants.view', 'applicants.change_status']));
        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", ['to_status' => 'archived'])->assertForbidden();
        $this->assertSame('initial_screening', $applicant->fresh()->current_status);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", ['to_status' => 'incomplete_requirements'])->assertOk();
    }

    public function test_hr_and_administrators_keep_every_action_they_had(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);

        Sanctum::actingAs($hr);
        $this->getJson("/api/v1/employees/{$employee->id}/violations")->assertOk();
        $this->getJson("/api/v1/job-requests/{$request->id}/rankings")->assertOk();
        $this->getJson("/api/v1/applicants/{$applicant->id}/requirements")->assertOk();
        $this->getJson('/api/v1/documents/applicants')->assertOk();
        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", ['to_status' => 'archived'])->assertOk();
        $this->getJson('/api/v1/users')->assertForbidden(); // never had user administration

        $admin = $this->adminUser();
        $target = $this->hrUser();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/users')->assertOk();
        $this->patchJson("/api/v1/users/{$target->id}", ['first_name' => 'Renamed', 'role' => 'admin'])->assertOk();
        $this->assertTrue($target->fresh()->hasRole('admin'));
    }
}
