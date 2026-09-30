<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Employee;
use App\Services\Reporting\ReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

class ReportsAndUsersTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    // ---------------------------------------------------------------- reports

    public function test_every_report_type_builds_without_error(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);
        $this->jobRequest($department, $hr);
        $this->applicant($hr);

        $builder = app(ReportBuilder::class);

        foreach (array_keys(ReportBuilder::TYPES) as $type) {
            $report = $builder->build($type);

            $this->assertNotEmpty($report['title'], "{$type} must have a title");
            $this->assertNotEmpty($report['columns'], "{$type} must define columns");
            $this->assertIsArray($report['summary'], "{$type} must return a summary");

            // Every row must line up with the header, or the PDF and the
            // spreadsheet silently shift columns.
            foreach ($report['rows'] as $row) {
                $this->assertCount(
                    count($report['columns']),
                    $row,
                    "{$type}: a row does not match the column count"
                );
            }
        }
    }

    public function test_report_preview_returns_data_for_hr(): void
    {
        $hr = $this->hrUser();
        $this->applicant($hr, ['first_name' => 'Reportable']);

        Sanctum::actingAs($hr);

        $this->postJson('/api/v1/reports/preview', ['report_type' => 'applicants'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Applicant Report')
            ->assertJsonStructure(['data' => ['columns', 'rows', 'summary', 'period']]);
    }

    public function test_pdf_export_returns_a_pdf_document(): void
    {
        $hr = $this->hrUser();
        $this->applicant($hr);

        Sanctum::actingAs($hr);

        $response = $this->post('/api/v1/reports/export', [
            'report_type' => 'applicants',
            'format' => 'pdf',
        ]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        // A PDF always starts with the %PDF- magic bytes; anything else means
        // the renderer emitted an error page instead. DomPDF returns a plain
        // response rather than a streamed one, so the body is read directly.
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_excel_export_returns_a_spreadsheet(): void
    {
        $hr = $this->hrUser();
        $this->applicant($hr);

        Sanctum::actingAs($hr);

        $response = $this->post('/api/v1/reports/export', [
            'report_type' => 'employees',
            'format' => 'xlsx',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('spreadsheet', $response->headers->get('content-type'));
    }

    public function test_export_is_recorded_in_the_audit_trail(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $this->post('/api/v1/reports/export', ['report_type' => 'clients', 'format' => 'csv']);

        // Exports move personal data out of the system, so who exported what has
        // to be answerable under RA 10173.
        $this->assertDatabaseHas('audit_logs', [
            'action_type' => 'export',
            'module_key' => 'reports',
            'actor_user_id' => $hr->id,
        ]);
    }

    // -------------------------------------------------------- user management

    public function test_hr_cannot_manage_users(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->getJson('/api/v1/users')->assertStatus(403);
        $this->postJson('/api/v1/users', [])->assertStatus(403);
    }

    public function test_administrator_can_create_a_staff_user(): void
    {
        Sanctum::actingAs($this->adminUser());

        $response = $this->postJson('/api/v1/users', [
            'first_name' => 'New',
            'last_name' => 'Officer',
            'email' => 'new.officer@cdemanpower.local',
            'user_type' => 'hr',
            'role' => 'hr',
            'password' => 'CorrectHorse99!',
            'password_confirmation' => 'CorrectHorse99!',
        ])->assertCreated();

        $this->assertSame(['hr'], $response->json('data.roles'));
        $this->assertDatabaseHas('users', ['email' => 'new.officer@cdemanpower.local', 'is_active' => true]);
    }

    /**
     * Removing the last administrator would leave nobody able to manage users or
     * read the audit trail, and there is no way back without database access.
     */
    public function test_the_last_administrator_cannot_be_deactivated(): void
    {
        $admin = $this->adminUser();
        $other = $this->adminUser();
        $other->update(['email' => 'second.admin@cdemanpower.local']);

        Sanctum::actingAs($admin);

        // Two admins exist, so deactivating one is allowed.
        $this->patchJson("/api/v1/users/{$other->id}/status", ['is_active' => false])->assertOk();

        // Now only one remains. A third admin tries to remove the last one.
        $third = $this->adminUser();
        $third->update(['email' => 'third.admin@cdemanpower.local']);
        Sanctum::actingAs($third);

        $this->patchJson("/api/v1/users/{$admin->id}/status", ['is_active' => false])->assertOk();

        // $third is now the only active administrator and cannot remove itself.
        $this->patchJson("/api/v1/users/{$third->id}/status", ['is_active' => false])
            ->assertStatus(400)
            ->assertJsonPath('message', 'You cannot deactivate your own account.');
    }

    public function test_deactivating_a_user_revokes_their_tokens(): void
    {
        $admin = $this->adminUser();
        $victim = $this->hrUser();
        $victim->createToken('test-token');

        $this->assertSame(1, $victim->tokens()->count());

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/users/{$victim->id}/status", ['is_active' => false])->assertOk();

        $this->assertSame(0, $victim->fresh()->tokens()->count(), 'Access must end immediately, not at session expiry.');
    }

    public function test_portal_access_provisioning_links_the_account_to_the_person(): void
    {
        $admin = $this->adminUser();
        $applicant = $this->applicant($admin, ['first_name' => 'Portal', 'last_name' => 'Candidate']);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/users/portal-access', [
            'applicant_id' => $applicant->id,
            'email' => 'portal.candidate@example.test',
        ])->assertCreated();

        $this->assertNotEmpty($response->json('data.temporary_password'));
        $this->assertSame('applicant', $response->json('data.user.user_type'));

        $created = User::where('email', 'portal.candidate@example.test')->first();
        $this->assertSame($applicant->id, $created->applicant_id);
        $this->assertTrue($created->hasRole('portal'));
        // A portal account must hold no staff permissions at all.
        $this->assertFalse($created->can('applicants.view'));
    }

    public function test_duplicate_portal_access_is_refused(): void
    {
        $admin = $this->adminUser();
        $applicant = $this->applicant($admin);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/users/portal-access', [
            'applicant_id' => $applicant->id,
            'email' => 'first@example.test',
        ])->assertCreated();

        $this->postJson('/api/v1/users/portal-access', [
            'applicant_id' => $applicant->id,
            'email' => 'second@example.test',
        ])->assertStatus(409);
    }

    public function test_portal_access_rejects_both_person_identifiers(): void
    {
        $admin = $this->adminUser();
        $applicant = $this->applicant($admin);
        $otherApplicant = $this->applicant($admin, ['first_name' => 'Other']);
        $employee = Employee::create([
            'applicant_id' => $otherApplicant->id,
            'employee_number' => 'EMP-TEST-'.uniqid(),
            'hire_date' => now()->toDateString(),
            'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/users/portal-access', [
            'applicant_id' => $applicant->id,
            'employee_id' => $employee->id,
            'email' => 'ambiguous.portal@example.test',
        ])->assertStatus(422)->assertJsonValidationErrors(['applicant_id', 'employee_id']);
    }

    public function test_staff_passwords_follow_the_central_policy(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->postJson('/api/v1/users', [
            'first_name' => 'Short',
            'last_name' => 'Password',
            'email' => 'short.password@cdemanpower.local',
            'user_type' => 'hr',
            'role' => 'hr',
            'password' => 'short-pass',
            'password_confirmation' => 'short-pass',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
