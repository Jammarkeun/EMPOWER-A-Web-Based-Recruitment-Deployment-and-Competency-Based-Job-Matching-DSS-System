<?php

namespace Tests\Feature;

use App\Models\ApplicantRequirement;
use App\Models\RequirementType;
use App\Models\User;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * "Fix the flow of Documents. The view should be based on the name, where all
 * documents for that applicant will be seen."
 *
 * The review panel's recommendation. The library now opens on applicants, each
 * with a summary of their documents, and a name opens everything that person
 * has on file - read through the applicant relationship on the server, never
 * by the screen filtering somebody else's files.
 */
class DocumentsByApplicantTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function upload($applicant, string $code, string $status = 'submitted'): ApplicantRequirement
    {
        $type = RequirementType::where('requirement_code', $code)->firstOrFail();

        return ApplicantRequirement::updateOrCreate(
            ['applicant_id' => $applicant->id, 'requirement_type_id' => $type->id],
            [
                'status' => $status,
                'file_path' => "applicants/{$applicant->id}/{$code}.pdf",
                'file_name' => "{$code}.pdf",
                'submitted_at' => now(),
            ]
        );
    }

    public function test_the_library_lists_applicants_with_a_summary_of_their_documents(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $andrea = $this->applicant($hr, ['first_name' => 'Andrea', 'last_name' => 'Santos']);
        $this->upload($andrea, 'resume', 'verified');
        $this->upload($andrea, 'birth_certificate');
        $this->upload($andrea, 'police_clearance', 'rejected');

        $ben = $this->applicant($hr, ['first_name' => 'Ben', 'last_name' => 'Abad']);
        $this->upload($ben, 'resume', 'verified');

        // Registered, nothing on file: an empty folder is left out by default.
        $this->applicant($hr, ['first_name' => 'Nobody', 'last_name' => 'Yet']);

        $rows = $this->getJson('/api/v1/documents/applicants')->assertOk()->json('data');

        $this->assertCount(2, $rows);

        // The one with something waiting for review comes first.
        $this->assertSame('Andrea Santos', $rows[0]['full_name']);
        $this->assertSame(3, $rows[0]['documents']['uploaded']);
        $this->assertSame(1, $rows[0]['documents']['verified']);
        $this->assertSame(1, $rows[0]['documents']['awaiting_review']);
        $this->assertSame(1, $rows[0]['documents']['needs_attention']);

        $this->assertCount(3, $this->getJson('/api/v1/documents/applicants?include_empty=1')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/documents/applicants?search=santos')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/documents/applicants?needs_review=1')->json('data'));
    }

    public function test_a_name_opens_every_document_that_person_has(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $andrea = $this->applicant($hr, ['first_name' => 'Andrea', 'last_name' => 'Santos']);
        $this->createChecklist($andrea);
        $this->upload($andrea, 'resume');
        $this->upload($andrea, 'birth_certificate');

        $other = $this->applicant($hr, ['first_name' => 'Other']);
        $this->upload($other, 'resume');

        $requirements = collect(
            $this->getJson("/api/v1/applicants/{$andrea->id}/requirements")->assertOk()->json('data.requirements')
        );

        // Her whole checklist, uploaded or not, and nobody else's files.
        $this->assertSame(RequirementType::active()->count(), $requirements->count());
        $this->assertSame(2, $requirements->where('has_file', true)->count());
    }

    public function test_the_company_filter_means_people_placed_with_that_company(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $company = $this->clientCompany($hr);
        $request = $this->jobRequest($this->department($company, $hr), $hr);
        $placed = $this->applicant($hr, ['first_name' => 'Placed', 'current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($placed, $hr, 'all');
        $this->completeTraining($placed, $hr);
        app(DeploymentService::class)->deploy($placed, $request, ['deployment_date' => now()->toDateString()], $hr);
        $this->upload($placed, 'resume', 'verified');

        $unplaced = $this->applicant($hr, ['first_name' => 'Unplaced']);
        $this->upload($unplaced, 'resume');

        $rows = $this->getJson("/api/v1/documents/applicants?client_company_id={$company->id}")->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($placed->id, $rows[0]['id']);
        $this->assertNotNull($rows[0]['employee_number']);
    }

    public function test_applicants_cannot_browse_the_library_or_each_others_documents(): void
    {
        $hr = $this->hrUser();
        $mine = $this->applicant($hr);
        $theirs = $this->applicant($hr);
        $this->upload($theirs, 'resume');

        $user = User::factory()->create([
            'email' => 'applicant'.$mine->id.'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $mine->id,
        ]);
        $user->assignRole('portal');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/documents/applicants')->assertForbidden();
        $this->getJson("/api/v1/applicants/{$theirs->id}/requirements")->assertForbidden();
        // A document they really uploaded, so the refusal comes from the policy
        // and not from a requirement type that happens not to exist.
        $document = ApplicantRequirement::where('applicant_id', $theirs->id)->whereNotNull('file_path')->firstOrFail();
        $this->getJson("/api/v1/applicants/{$theirs->id}/requirements/{$document->requirement_type_id}/download")->assertForbidden();
    }

    private function createChecklist($applicant): void
    {
        foreach (RequirementType::active()->get() as $type) {
            ApplicantRequirement::firstOrCreate(
                ['applicant_id' => $applicant->id, 'requirement_type_id' => $type->id],
                ['status' => 'missing']
            );
        }
    }
}
