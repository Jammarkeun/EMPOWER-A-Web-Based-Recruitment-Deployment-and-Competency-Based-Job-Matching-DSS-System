<?php

namespace Tests\Feature;

use App\Models\ApplicantRequirement;
use App\Models\AuditLog;
use App\Models\RequirementType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The difference between a document that has arrived and one that is being read.
 *
 * The portal used to tell applicants their document was "being checked" the
 * moment the upload finished. Nothing was being checked: the file had been
 * stored, and it might sit untouched for a week. The claim was a guess dressed
 * as a fact, and an applicant had no way to tell a document nobody had looked at
 * from one genuinely under review.
 *
 * The honest distinction needs a recorded event rather than a cleverer label, so
 * these tests are about what does and does not count as one. Storing a file does
 * not. An officer opening it does. The applicant opening their own copy does
 * not, which is the case most easily got wrong.
 */
class DocumentReviewTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Storage::fake('documents');
    }

    /**
     * The applicant's portal account, created once and reused.
     *
     * Several tests here sign in as the applicant, then as an officer, then as
     * the applicant again - which is the sequence being tested. Making a fresh
     * account each time would collide on the unique email, and more importantly
     * would not be the same person.
     */
    private function portalUserFor($applicant): User
    {
        $existing = User::where('applicant_id', $applicant->id)->first();

        if ($existing) {
            return $existing;
        }

        $user = User::factory()->create([
            'email' => 'portal'.$applicant->id.'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('portal');

        return $user;
    }

    private function resume(): RequirementType
    {
        return RequirementType::active()->primary()->orderBy('display_order')->firstOrFail();
    }

    /**
     * Uploads a document as the applicant and returns the requirement row.
     */
    private function upload($applicant, RequirementType $type): ApplicantRequirement
    {
        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => UploadedFile::fake()->create('document.pdf', 120, 'application/pdf'),
        ])->assertOk();

        // Guards cache the resolved user, so the next actingAs in a test would
        // otherwise keep answering as the applicant.
        $this->app['auth']->forgetGuards();

        return ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)
            ->firstOrFail();
    }

    // ---------------------------------------------------------- the states

    public function test_a_successful_upload_says_so_and_claims_nothing_more(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->resume();

        Sanctum::actingAs($this->portalUserFor($applicant));

        $response = $this->postJson("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'),
        ])->assertOk();

        $this->assertSame('uploaded', $response->json('data.review_state'));
        $this->assertSame('Upload successful', $response->json('data.review_state_label'));

        // The underlying status is unchanged - this is a second reading of the
        // same row, not a new value in the status column.
        $this->assertSame('submitted', $response->json('data.status'));
    }

    public function test_the_document_stays_merely_uploaded_until_somebody_opens_it(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $requirement = $this->upload($applicant, $this->resume());

        $this->assertNull($requirement->first_viewed_at);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $state = collect($this->getJson('/api/v1/portal/documents')->json('data.requirements'))
            ->firstWhere('requirement_type_id', $requirement->requirement_type_id);

        $this->assertSame('uploaded', $state['review_state']);
        $this->assertSame('Upload successful', $state['review_state_label']);
    }

    public function test_an_officer_opening_the_document_puts_it_into_review(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->resume();
        $this->upload($applicant, $type);

        Sanctum::actingAs($hr);

        $this->getJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}/download")
            ->assertOk()
            ->assertJsonPath('data.requirement.review_state', 'under_review')
            ->assertJsonPath('data.requirement.review_state_label', 'Being checked');

        $requirement = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)
            ->firstOrFail();

        $this->assertNotNull($requirement->first_viewed_at);
        $this->assertSame($hr->id, $requirement->first_viewed_by);
    }

    /**
     * And the applicant is then told the same thing, which is the entire point.
     */
    public function test_the_applicant_sees_that_review_has_begun(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->resume();
        $this->upload($applicant, $type);

        Sanctum::actingAs($hr);
        $this->getJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}/download")->assertOk();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->portalUserFor($applicant));

        $state = collect($this->getJson('/api/v1/portal/documents')->json('data.requirements'))
            ->firstWhere('requirement_type_id', $type->id);

        $this->assertSame('under_review', $state['review_state']);
    }

    /**
     * The case most easily got wrong. An applicant checking what they sent must
     * not make it look as though the office has read it.
     */
    public function test_the_applicant_opening_their_own_document_does_not_start_review(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->resume();
        $this->upload($applicant, $type);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->getJson("/api/v1/portal/documents/{$type->id}/download")->assertOk();

        $requirement = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)
            ->firstOrFail();

        $this->assertNull(
            $requirement->first_viewed_at,
            'An applicant reading their own document is not the office reviewing it.'
        );
    }

    /**
     * "Has review begun?" is a question with one answer. A timestamp that moved
     * every time somebody reopened the file would answer a different one.
     */
    public function test_reopening_does_not_move_the_first_view(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->resume();
        $this->upload($applicant, $type);

        Sanctum::actingAs($hr);
        $url = "/api/v1/applicants/{$applicant->id}/requirements/{$type->id}/download";

        $this->getJson($url)->assertOk();
        $first = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)->value('first_viewed_at');

        $this->travel(5)->minutes();

        $this->getJson($url)->assertOk();
        $second = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)->value('first_viewed_at');

        $this->assertEquals($first, $second);
    }

    /**
     * A second officer opening the same document is still worth recording,
     * because for a file covered by RA 10173 who looked at it is the question
     * that matters most.
     */
    public function test_every_opening_is_audited_even_after_the_first(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->resume();
        $this->upload($applicant, $type);

        Sanctum::actingAs($hr);
        $url = "/api/v1/applicants/{$applicant->id}/requirements/{$type->id}/download";
        $this->getJson($url)->assertOk();
        $this->getJson($url)->assertOk();

        $this->assertSame(2, AuditLog::where('module_key', 'requirements')
            ->where('action_type', 'view')->count());
    }

    /**
     * The document library asks the same question of the same row, so opening a
     * file there has to count exactly as it does on the applicant's record.
     */
    public function test_opening_from_the_document_library_also_starts_review(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $requirement = $this->upload($applicant, $this->resume());

        Sanctum::actingAs($hr);

        $this->getJson("/api/v1/documents/file/{$requirement->id}")
            ->assertOk()
            ->assertJsonPath('data.review_state', 'under_review');
    }

    // ------------------------------------------------ the later states hold

    public function test_verifying_still_reads_as_accepted(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->resume();
        $this->upload($applicant, $type);

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
            'status' => 'verified',
        ])
            ->assertOk()
            ->assertJsonPath('data.requirement.review_state', 'verified')
            ->assertJsonPath('data.requirement.review_state_label', 'Accepted');
    }

    public function test_a_rejection_reads_as_needing_resubmission(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->resume();
        $this->upload($applicant, $type);

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
            'status' => 'rejected',
            'rejection_reason' => 'The photo is too dark to read.',
        ])
            ->assertOk()
            ->assertJsonPath('data.requirement.review_state', 'rejected');
    }

    /**
     * A walk-in document was checked across the counter with no file involved,
     * so it goes straight to accepted without ever being "uploaded".
     */
    public function test_a_walk_in_verification_needs_no_upload_to_be_accepted(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->resume();

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
            'status' => 'verified',
            'verification_method' => 'walk_in',
        ])
            ->assertOk()
            ->assertJsonPath('data.requirement.review_state', 'verified')
            ->assertJsonPath('data.requirement.upload_status', 'not_uploaded');
    }

    // -------------------------------------------------------- authorization

    /**
     * The review state is derived from what staff have done, so there is no
     * field for an applicant to send. This checks there is no back door either.
     */
    public function test_an_applicant_cannot_move_their_own_document_into_review(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->resume();
        $this->upload($applicant, $type);

        Sanctum::actingAs($this->portalUserFor($applicant));

        // The staff route refuses them outright.
        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
            'status' => 'verified',
        ])->assertForbidden();

        // And their own upload route ignores anything they try to send with it.
        $this->postJson("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => UploadedFile::fake()->create('again.pdf', 100, 'application/pdf'),
            'status' => 'verified',
            'first_viewed_at' => now()->toDateTimeString(),
        ])->assertOk();

        $requirement = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)
            ->firstOrFail();

        $this->assertSame('submitted', $requirement->status);
        $this->assertNull($requirement->first_viewed_at);
    }

    /**
     * A replacement is a new document, so the review it may already have had
     * does not carry over to it.
     */
    public function test_replacing_a_document_starts_its_review_again(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = $this->resume();
        $this->upload($applicant, $type);

        Sanctum::actingAs($hr);
        $this->getJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}/download")->assertOk();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => UploadedFile::fake()->create('better-scan.pdf', 150, 'application/pdf'),
        ])
            ->assertOk()
            ->assertJsonPath('data.review_state', 'uploaded');
    }
}
