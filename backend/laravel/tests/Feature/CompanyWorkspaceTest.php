<?php

namespace Tests\Feature;

use App\Models\CriteriaCatalog;
use App\Models\RequirementType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The client company workspace and its standing requirements.
 *
 * Work starts by picking a client, so the company page has to answer "what is
 * happening with this client" without the reader assembling it from four other
 * screens. Its requirements live here too: previously criteria existed only on
 * an individual request, so every new request for the same client started blank
 * and whoever raised it had to remember what that client cares about.
 */
class CompanyWorkspaceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_the_workspace_summarises_one_company(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);
        $this->jobRequest($department, $hr, ['workers_needed' => 5]);

        Sanctum::actingAs($hr);

        $this->getJson("/api/v1/clients/{$company->id}/overview")
            ->assertOk()
            ->assertJsonPath('data.company.company_name', $company->company_name)
            ->assertJsonPath('data.summary.open_requests', 1)
            ->assertJsonPath('data.summary.positions_to_fill', 5)
            ->assertJsonPath('data.summary.departments', 1)
            ->assertJsonStructure(['data' => ['summary', 'requests', 'deployed']]);
    }

    /**
     * The catalogue comes back whole, with a flag against the ones in use.
     * Returning only the configured entries would mean you had to already know a
     * criterion existed before you could find it and switch it on.
     */
    public function test_the_criteria_screen_lists_every_available_requirement(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);

        Sanctum::actingAs($hr);

        $response = $this->getJson("/api/v1/clients/{$company->id}/criteria")->assertOk();

        $this->assertSame(
            CriteriaCatalog::where('is_active', true)->count(),
            count($response->json('data.criteria'))
        );
        $this->assertSame(
            0,
            collect($response->json('data.criteria'))->where('in_use', true)->count()
        );
    }

    public function test_a_company_can_have_its_own_requirements_saved(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $education = CriteriaCatalog::where('criteria_code', 'education')->firstOrFail();

        Sanctum::actingAs($hr);

        $this->putJson("/api/v1/clients/{$company->id}/criteria", [
            'criteria' => [[
                'criteria_id' => $education->id,
                'weight_score' => 40,
                'mandatory_flag' => true,
                'note' => 'High school minimum for production roles.',
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('company_criteria', [
            'client_company_id' => $company->id,
            'criteria_id' => $education->id,
            'weight_score' => 40,
            'note' => 'High school minimum for production roles.',
        ]);
    }

    /**
     * Different clients want different things — the whole point of making these
     * configurable rather than hard-coding one company's rules.
     */
    public function test_two_companies_keep_separate_requirements(): void
    {
        $hr = $this->hrUser();
        $tiwi = $this->clientCompany($hr, ['company_code' => 'CLI-A-'.uniqid(), 'company_name' => 'Best Tiwi']);
        $other = $this->clientCompany($hr, ['company_code' => 'CLI-B-'.uniqid(), 'company_name' => 'ABC Manufacturing']);

        $education = CriteriaCatalog::where('criteria_code', 'education')->firstOrFail();
        $height = CriteriaCatalog::where('criteria_code', 'height')->firstOrFail();

        Sanctum::actingAs($hr);

        $this->putJson("/api/v1/clients/{$tiwi->id}/criteria", [
            'criteria' => [['criteria_id' => $education->id, 'weight_score' => 50]],
        ])->assertOk();

        $this->putJson("/api/v1/clients/{$other->id}/criteria", [
            'criteria' => [['criteria_id' => $height->id, 'min_value' => 160]],
        ])->assertOk();

        $tiwiUsed = collect($this->getJson("/api/v1/clients/{$tiwi->id}/criteria")->json('data.criteria'))
            ->where('in_use', true)->pluck('code')->all();
        $otherUsed = collect($this->getJson("/api/v1/clients/{$other->id}/criteria")->json('data.criteria'))
            ->where('in_use', true)->pluck('code')->all();

        $this->assertSame(['education'], $tiwiUsed);
        $this->assertSame(['height'], $otherUsed);
    }

    /**
     * A request for that client is offered the client's own requirements as a
     * starting point — but only as a suggestion, and only while it has none of
     * its own, so an existing request is never quietly rewritten.
     */
    public function test_a_new_request_is_offered_the_companys_requirements(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);
        $request = $this->jobRequest($department, $hr);
        $education = CriteriaCatalog::where('criteria_code', 'education')->firstOrFail();

        Sanctum::actingAs($hr);

        $this->putJson("/api/v1/clients/{$company->id}/criteria", [
            'criteria' => [['criteria_id' => $education->id, 'weight_score' => 35, 'note' => 'Client standard']],
        ])->assertOk();

        $response = $this->getJson("/api/v1/job-requests/{$request->id}/criteria")->assertOk();

        $this->assertCount(0, $response->json('data.configured'));
        $this->assertCount(1, $response->json('data.suggested_from_company'));
        $this->assertSame('education', $response->json('data.suggested_from_company.0.criteria_code'));
        // assertEquals, not assertSame: json_encode drops the zero fraction, so
        // a float 35.0 arrives back as the integer 35.
        $this->assertEquals(35, $response->json('data.suggested_from_company.0.weight_score'));
    }

    public function test_a_request_with_its_own_criteria_is_not_offered_suggestions(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);
        $request = $this->jobRequest($department, $hr);
        $education = CriteriaCatalog::where('criteria_code', 'education')->firstOrFail();

        Sanctum::actingAs($hr);

        $this->putJson("/api/v1/clients/{$company->id}/criteria", [
            'criteria' => [['criteria_id' => $education->id, 'weight_score' => 35]],
        ])->assertOk();

        $this->postJson("/api/v1/job-requests/{$request->id}/criteria", [
            'criteria' => [[
                'criteria_code' => 'experience',
                'weight_score' => 20,
                'mandatory_flag' => false,
                'min_value' => 0,
                'max_value' => 36,
            ]],
        ])->assertOk();

        $response = $this->getJson("/api/v1/job-requests/{$request->id}/criteria")->assertOk();

        $this->assertCount(1, $response->json('data.configured'));
        $this->assertCount(
            0,
            $response->json('data.suggested_from_company'),
            'A request that already has criteria must not be offered the company defaults again.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The document library
    |--------------------------------------------------------------------------
    |
    | Documents grouped by kind - "show me the medical certificates" - which is a
    | different question from the Folder 1/2/3 filing, and deliberately a
    | separate screen from it.
    */

    public function test_the_document_library_groups_files_by_kind(): void
    {
        Storage::fake('documents');

        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $resume = RequirementType::where('requirement_code', 'resume')->firstOrFail();

        // Uploaded by the applicant through the portal, which is the only way a
        // file gets into the system now.
        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->postJson("/api/v1/portal/documents/{$resume->id}/upload", [
            'file' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
        ])->assertOk();

        Sanctum::actingAs($hr);

        $folders = collect($this->getJson('/api/v1/documents')->assertOk()->json('data.folders'));

        $this->assertSame(1, $folders->firstWhere('requirement_type_id', $resume->id)['file_count']);
        $this->assertSame(1, $this->getJson('/api/v1/documents')->json('data.total_files'));
    }

    /**
     * A requirement verified at the counter has no file, so it must not appear
     * as an empty row in a folder that is supposed to hold documents.
     */
    public function test_walk_in_verifications_do_not_appear_as_files(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->primary()->first();

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
            'status' => 'verified',
        ])->assertOk();

        $this->assertSame(
            0,
            $this->getJson('/api/v1/documents')->assertOk()->json('data.total_files'),
            'Verified without an upload means there is no file to list.'
        );
    }

    public function test_a_folder_lists_which_applicant_each_file_belongs_to(): void
    {
        Storage::fake('documents');

        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['first_name' => 'Maria', 'last_name' => 'Santos']);
        $resume = RequirementType::where('requirement_code', 'resume')->firstOrFail();

        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->postJson("/api/v1/portal/documents/{$resume->id}/upload", [
            'file' => UploadedFile::fake()->create('maria.pdf', 100, 'application/pdf'),
        ])->assertOk();

        Sanctum::actingAs($hr);

        $this->getJson("/api/v1/documents/{$resume->id}")
            ->assertOk()
            ->assertJsonPath('data.0.applicant.name', 'Maria Santos')
            ->assertJsonPath('data.0.file_name', 'maria.pdf');
    }

    /** @return \App\Models\User */
    private function portalUserFor($applicant)
    {
        $user = \App\Models\User::factory()->create([
            'email' => 'portal'.$applicant->id.'.'.uniqid().'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]);
        $user->assignRole('portal');

        return $user;
    }
}
