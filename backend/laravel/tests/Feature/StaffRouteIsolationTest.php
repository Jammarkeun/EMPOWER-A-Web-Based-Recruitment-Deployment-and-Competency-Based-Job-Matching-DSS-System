<?php

namespace Tests\Feature;

use App\Models\ApplicantRequirement;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\RequirementType;
use App\Models\Resignation;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Audit R-04: applicant and employee accounts reach only the portal.
 *
 * The staff routes return the agency's full view of a record. Policies used to
 * let the owner through, so a portal account could read its own HR file -
 * interview ratings, internal remarks, ranking scores, draft terminations and
 * who filed them, who reported each violation - and opening a document that
 * way marked it "under review". Every staff route is now closed to portal
 * accounts, for their own records as much as anybody else's.
 */
class StaffRouteIsolationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Storage::fake('documents');
    }

    private function deployedEmployee(User $hr): Employee
    {
        $department = $this->department($this->clientCompany($hr), $hr);
        $request = $this->jobRequest($department, $hr, ['workers_needed' => 10]);

        $applicant = $this->applicant($hr, [
            'current_status' => 'ready_for_deployment',
            'remarks' => 'Internal: reference check pending',
            'communication_rating' => 4.5,
        ]);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->subMonth()->toDateString(),
        ], $hr)->employee;
    }

    private function applicantAccount($applicant): User
    {
        $user = User::factory()->create([
            'email' => 'applicant'.$applicant->id.'.'.uniqid().'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]);
        $user->assignRole('portal');

        return $user;
    }

    private function employeeAccount(Employee $employee): User
    {
        $user = User::factory()->create([
            'email' => 'employee'.$employee->id.'.'.uniqid().'@example.test',
            'user_type' => 'employee',
            'applicant_id' => $employee->applicant_id,
            'employee_id' => $employee->id,
        ]);
        $user->assignRole('portal');

        return $user;
    }

    public function test_an_applicant_cannot_open_their_own_record_through_staff_routes(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['remarks' => 'Internal: do not share', 'reliability_rating' => 2]);
        $type = RequirementType::active()->where('has_expiry', false)->firstOrFail();
        ApplicantRequirement::updateOrCreate(
            ['applicant_id' => $applicant->id, 'requirement_type_id' => $type->id],
            ['status' => 'submitted', 'file_path' => "requirements/{$applicant->id}/doc.pdf", 'file_name' => 'doc.pdf']
        );

        Sanctum::actingAs($this->applicantAccount($applicant));

        $reads = [
            "/api/v1/applicants/{$applicant->id}",
            "/api/v1/applicants/{$applicant->id}/timeline",
            "/api/v1/applicants/{$applicant->id}/requirements",
            "/api/v1/applicants/{$applicant->id}/requirements/{$type->id}/download",
            '/api/v1/requirement-types',
            '/api/v1/search?q=Test',
            '/api/v1/documents/applicants',
            '/api/v1/dashboard/summary',
            '/api/v1/settings',
            '/api/v1/positions',
        ];

        foreach ($reads as $uri) {
            $response = $this->getJson($uri);
            $response->assertForbidden();
            $this->assertStringNotContainsString('do not share', $response->getContent(), $uri);
        }

        // Staff actions called directly, with the applicant's own id.
        $this->patchJson("/api/v1/applicants/{$applicant->id}", ['first_name' => 'Changed'])->assertForbidden();
        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", ['to_status' => 'initial_screening'])->assertForbidden();
        $this->putJson("/api/v1/applicants/{$applicant->id}/qualifications", ['skills' => []])->assertForbidden();
        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", ['status' => 'verified'])->assertForbidden();

        $this->assertSame('Test', $applicant->fresh()->first_name);
        // Opening the document through a staff route did not start a review.
        $this->assertNull(ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)->value('first_viewed_at'));
    }

    public function test_an_employee_cannot_open_their_own_hr_file_through_staff_routes(): void
    {
        $hr = $this->hrUser();
        $employee = $this->deployedEmployee($hr);
        $deployment = $employee->deployments()->firstOrFail();

        $violation = EmployeeViolation::create([
            'employee_id' => $employee->id,
            'violation_date' => now()->subDays(3)->toDateString(),
            'violation_type' => 'late',
            'description' => 'Late twice this week',
            'evidence_path' => "violations/{$employee->id}/evidence.pdf",
            'status' => 'open',
            'issued_by' => $hr->id,
        ]);
        $resignation = Resignation::create([
            'employee_id' => $employee->id,
            'reason' => 'Moving away',
            'filing_date' => now()->toDateString(),
            'resignation_letter_path' => "resignations/{$employee->id}/letter.pdf",
            'status' => 'filed',
            'clearance_status' => 'pending',
        ]);

        Sanctum::actingAs($this->employeeAccount($employee));

        foreach ([
            "/api/v1/employees/{$employee->id}",
            "/api/v1/employees/{$employee->id}/violations",
            "/api/v1/employees/{$employee->id}/violations/{$violation->id}/evidence",
            "/api/v1/employees/{$employee->id}/resignations/{$resignation->id}/letter",
            "/api/v1/employees/{$employee->id}/attendance",
            "/api/v1/deployments/{$deployment->id}",
            "/api/v1/applicants/{$employee->applicant_id}",
        ] as $uri) {
            $this->getJson($uri)->assertForbidden();
        }

        // Staff separation actions on themselves.
        $this->postJson("/api/v1/employees/{$employee->id}/terminations", [
            'reason' => 'Self-filed', 'termination_date' => now()->toDateString(),
        ])->assertForbidden();
        $this->putJson("/api/v1/employees/{$employee->id}/resignations/{$resignation->id}", [
            'clearance_status' => 'cleared',
        ])->assertForbidden();

        $this->assertSame('pending', $resignation->fresh()->clearance_status);
        $this->assertSame(0, $employee->terminations()->count());
    }

    public function test_portal_accounts_keep_their_own_account_and_portal_routes(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        Sanctum::actingAs($this->applicantAccount($applicant));

        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/auth/notification-preferences')->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk();
        $this->getJson('/api/v1/notifications/unread-count')->assertOk();
        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'not-the-password',
            'password' => 'a-new-long-password-123',
            'password_confirmation' => 'a-new-long-password-123',
        ])->assertStatus(422); // reached the action, refused on the wrong password

        $this->getJson('/api/v1/portal')->assertOk();
        $this->getJson('/api/v1/portal/documents')->assertOk();
        $this->getJson('/api/v1/portal/profile')->assertOk();
    }

    public function test_unauthenticated_requests_are_refused_and_staff_keep_access(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);

        $this->getJson("/api/v1/applicants/{$applicant->id}")->assertUnauthorized();
        $this->getJson('/api/v1/notifications')->assertUnauthorized();

        Sanctum::actingAs($hr);
        $this->getJson("/api/v1/applicants/{$applicant->id}")->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk();
    }

    public function test_a_portal_account_given_a_staff_role_by_mistake_is_still_kept_out(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $account = $this->applicantAccount($applicant);
        // The account type, set on the server, decides - not the role list.
        $account->syncRoles(['hr']);

        Sanctum::actingAs($account);
        $this->getJson('/api/v1/applicants')->assertForbidden();
        $this->getJson("/api/v1/applicants/{$applicant->id}")->assertForbidden();
    }

    public function test_coordinators_pass_the_staff_wall_but_not_the_hr_permissions(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $coordinator = User::factory()->create(['email' => 'coord.'.uniqid().'@cde.test', 'user_type' => 'coordinator']);
        $coordinator->assignRole('coordinator');

        Sanctum::actingAs($coordinator);
        $this->getJson("/api/v1/applicants/{$applicant->id}")->assertForbidden();
        $this->getJson('/api/v1/applicants')->assertForbidden();
        $this->getJson('/api/v1/search?q=Test')->assertOk()->assertJsonPath('data.groups', []);
    }
}
