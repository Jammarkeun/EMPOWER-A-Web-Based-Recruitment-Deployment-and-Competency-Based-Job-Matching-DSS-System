<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\RequirementType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Walks the agency's whole process through the HTTP layer: client, request,
 * criteria, applicant, documents, evaluation, deployment.
 *
 * The service-level tests already cover the rules; this checks that routing,
 * validation, authorisation, and the response envelope hold them together.
 */
class ApiWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/applicants')
            ->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_login_returns_a_token_and_permission_list(): void
    {
        $hr = $this->hrUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $hr->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['token', 'token_type', 'user' => ['id', 'full_name', 'roles', 'permissions']],
            ]);

        $this->assertContains('applicants.create', $response->json('data.user.permissions'));
    }

    public function test_login_does_not_reveal_whether_an_email_exists(): void
    {
        $hr = $this->hrUser();

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $hr->email,
            'password' => 'not-the-password',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@cdemanpower.local',
            'password' => 'not-the-password',
        ]);

        $wrongPassword->assertStatus(422);
        $unknownEmail->assertStatus(422);
        $this->assertSame(
            $wrongPassword->json('errors.email'),
            $unknownEmail->json('errors.email'),
            'Both failures must return an identical message.'
        );
    }

    public function test_login_does_not_reveal_that_a_valid_account_is_inactive(): void
    {
        $hr = $this->hrUser();
        $hr->forceFill(['is_active' => false])->save();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $hr->email,
            'password' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    public function test_dashboard_months_must_be_between_one_and_thirty_six(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->getJson('/api/v1/dashboard/summary?months=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('months');

        $this->getJson('/api/v1/dashboard/summary?months=37')
            ->assertStatus(422)
            ->assertJsonValidationErrors('months');
    }

    public function test_deactivated_account_cannot_use_an_existing_token(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $hr->forceFill(['is_active' => false])->save();

        $this->getJson('/api/v1/applicants')
            ->assertStatus(403)
            ->assertJsonPath('message', 'This account has been deactivated.');
    }

    /**
     * HR runs recruitment but must not reach user management or the audit trail:
     * the person whose actions are recorded should not control the record.
     */
    public function test_hr_cannot_read_the_audit_trail(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->getJson('/api/v1/audit-logs')->assertStatus(403);
    }

    public function test_administrator_can_read_the_audit_trail(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->getJson('/api/v1/audit-logs')->assertOk();
    }

    /**
     * Separation of duties: HR may file a dismissal, but finalising one is
     * reserved to an administrator so the same person cannot both raise and
     * conclude it.
     */
    public function test_hr_can_file_but_not_finalise_a_termination(): void
    {
        $hr = $this->hrUser();
        $department = $this->department($this->clientCompany($hr), $hr);
        $jobRequest = $this->jobRequest($department, $hr);
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        $deployment = app(\App\Services\DeploymentService::class)->deploy($applicant, $jobRequest, [
            'deployment_date' => now()->toDateString(),
        ], $hr);
        $employeeId = $deployment->employee_id;

        Sanctum::actingAs($hr);

        $termination = $this->postJson("/api/v1/employees/{$employeeId}/terminations", [
            'reason' => 'Repeated AWOL',
            'termination_date' => now()->toDateString(),
            'status' => 'for_review',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/employees/{$employeeId}/terminations/{$termination}/finalise")
            ->assertStatus(403);

        // The employee is untouched by the refused attempt.
        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'employment_status' => 'active']);

        Sanctum::actingAs($this->adminUser());

        $this->postJson("/api/v1/employees/{$employeeId}/terminations/{$termination}/finalise")
            ->assertOk();

        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'employment_status' => 'terminated']);
        $this->assertDatabaseHas('archives', ['entity_type' => 'employee', 'entity_id' => $employeeId]);
    }

    public function test_full_recruitment_workflow(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        // 1. Register the client company and its department.
        $client = $this->postJson('/api/v1/clients', [
            'company_name' => 'Best Tiwi Food Products Corporation',
            'business_type' => 'Food Manufacturing',
            'office_address' => 'Sta. Cruz, Laguna',
            'contact_person' => 'Ma. Elena Bautista',
        ])->assertCreated()->json('data.id');

        $department = $this->postJson("/api/v1/clients/{$client}/departments", [
            'department_code' => 'PROD',
            'department_name' => 'Production',
        ])->assertCreated()->json('data.id');

        // 2. Record the manpower request.
        $jobRequest = $this->postJson('/api/v1/job-requests', [
            'client_company_id' => $client,
            'client_department_id' => $department,
            'position_title' => 'Production Helper',
            'workers_needed' => 2,
            'date_requested' => now()->toDateString(),
            'age_min' => 18,
            'age_max' => 35,
        ])->assertCreated()->json('data.id');

        // 3. Configure the competency criteria and weights.
        $this->postJson("/api/v1/job-requests/{$jobRequest}/criteria", [
            'criteria' => [
                ['criteria_code' => 'age', 'mandatory_flag' => true, 'min_value' => 18, 'max_value' => 35],
                ['criteria_code' => 'experience', 'weight_score' => 60, 'min_value' => 0, 'max_value' => 24],
                ['criteria_code' => 'education', 'weight_score' => 40, 'expected_value' => 'high_school'],
            ],
        ])->assertOk();

        // 4. Register an applicant. The requirement checklist is created for them.
        $applicantId = $this->postJson('/api/v1/applicants', [
            'source_channel' => 'walk_in',
            'first_name' => 'Maria Cristina',
            'last_name' => 'Santos',
            'sex' => 'female',
            'birth_date' => now()->subYears(24)->toDateString(),
            'present_address' => 'Brgy. Bubukal, Sta. Cruz, Laguna',
            'application_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $applicant = Applicant::find($applicantId);
        $this->assertSame('initial_screening', $applicant->current_status);
        $this->assertGreaterThan(0, $applicant->requirements()->count());
        $this->assertStringStartsWith('APP-', $applicant->applicant_code);

        // 5. Folder logic starts at folder_3 with nothing verified.
        $this->getJson("/api/v1/applicants/{$applicantId}/requirements")
            ->assertOk()
            ->assertJsonPath('data.folder.folder_category', 'folder_3');

        // 6. A walk-in applicant hands their papers over the counter, so the
        //    officer verifies them with no file ever stored. This is the common
        //    case at the agency, and the system must record it as a genuine
        //    verification rather than treating the applicant as incomplete.
        Storage::fake('documents');
        $resume = RequirementType::where('requirement_code', 'resume')->firstOrFail();
        $diploma = RequirementType::where('requirement_code', 'diploma')->firstOrFail();

        $this->patchJson("/api/v1/applicants/{$applicantId}/requirements/{$diploma->id}", [
            'status' => 'verified',
        ])
            ->assertOk()
            ->assertJsonPath('data.requirement.status', 'verified')
            ->assertJsonPath('data.requirement.upload_status', 'not_uploaded')
            // Inferred from the absence of a file: the officer was looking at
            // the original, not at an upload.
            ->assertJsonPath('data.requirement.verification_method', 'walk_in');

        // 7. Verifying the remaining documents promotes the applicant
        //    automatically, without anyone having to remember to move them.
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        $this->patchJson("/api/v1/applicants/{$applicantId}/requirements/{$resume->id}", [
            'status' => 'verified',
        ])->assertOk()->assertJsonPath('data.folder.folder_category', 'folder_1');

        $this->assertSame('ready_for_deployment', $applicant->fresh()->current_status);

        // 7. Run the competency evaluation.
        $evaluation = $this->postJson("/api/v1/job-requests/{$jobRequest}/evaluate")
            ->assertOk()
            ->assertJsonPath('data.evaluated_candidates', 1);

        $ranked = $evaluation->json('data.ranked.0');
        $this->assertSame(1, $ranked['rank_order']);
        $this->assertTrue($ranked['hard_filter_pass']);
        $this->assertNotEmpty($ranked['breakdown']['criteria']);

        // Evaluation is advisory: nobody has been deployed.
        $this->assertDatabaseCount('deployments', 0);

        // 8. Shortlist, then deploy explicitly.
        $this->postJson("/api/v1/job-requests/{$jobRequest}/shortlist", [
            'applicant_ids' => [$applicantId],
        ])->assertOk();

        $deployment = $this->postJson('/api/v1/deployments', [
            'applicant_id' => $applicantId,
            'job_request_id' => $jobRequest,
            'deployment_date' => now()->toDateString(),
            'supervisor_name' => 'Mark Reyes',
        ])->assertCreated();

        $employeeId = $deployment->json('data.employee_id');
        $this->assertSame('active', $applicant->fresh()->current_status);

        // 9. The employee record carries the recruitment history forward.
        $this->getJson("/api/v1/employees/{$employeeId}")
            ->assertOk()
            ->assertJsonPath('data.employee.applicant_id', $applicantId)
            ->assertJsonPath('data.employee.employment_status', 'active');

        // 10. Record a violation, then process the resignation through to archive.
        $this->postJson("/api/v1/employees/{$employeeId}/violations", [
            'violation_date' => now()->toDateString(),
            'violation_type' => 'late',
            'description' => 'Reported late three consecutive days.',
            'penalty' => 'Written warning',
        ])->assertCreated();

        $resignation = $this->postJson("/api/v1/employees/{$employeeId}/resignations", [
            'reason' => 'Personal reasons',
            'filing_date' => now()->toDateString(),
            'rendering_days' => 30,
            'exit_date' => now()->addDays(30)->toDateString(),
        ])->assertCreated()->json('data.id');

        // Clearance must be granted before the resignation can complete.
        $this->postJson("/api/v1/employees/{$employeeId}/resignations/{$resignation}/complete")
            ->assertStatus(400);

        $this->putJson("/api/v1/employees/{$employeeId}/resignations/{$resignation}", [
            'clearance_status' => 'cleared',
        ])->assertOk();

        $this->postJson("/api/v1/employees/{$employeeId}/resignations/{$resignation}/complete")
            ->assertOk();

        $this->assertDatabaseHas('employees', [
            'id' => $employeeId,
            'employment_status' => 'resigned',
        ]);

        // 11. The archive keeps the record searchable after separation.
        $this->assertDatabaseHas('archives', ['entity_type' => 'employee', 'entity_id' => $employeeId]);
    }

    public function test_deployment_is_refused_when_documents_are_incomplete(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $department = $this->department($this->clientCompany($hr), $hr);
        $jobRequest = $this->jobRequest($department, $hr);
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'primary');

        $this->postJson('/api/v1/deployments', [
            'applicant_id' => $applicant->id,
            'job_request_id' => $jobRequest->id,
            'deployment_date' => now()->toDateString(),
        ])
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_invalid_lifecycle_transition_returns_conflict(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $applicant = $this->applicant($hr, ['current_status' => 'applied']);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", [
            'to_status' => 'deployed',
        ])->assertStatus(409);
    }

    public function test_criteria_without_any_weight_are_rejected(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $jobRequest = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $this->postJson("/api/v1/job-requests/{$jobRequest->id}/criteria", [
            'criteria' => [
                ['criteria_code' => 'age', 'mandatory_flag' => true, 'min_value' => 18, 'max_value' => 35],
            ],
        ])->assertStatus(422);
    }

    public function test_validation_errors_use_the_contract_envelope(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->postJson('/api/v1/applicants', ['first_name' => 'Incomplete'])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors'])
            ->assertJsonPath('success', false);
    }
}
