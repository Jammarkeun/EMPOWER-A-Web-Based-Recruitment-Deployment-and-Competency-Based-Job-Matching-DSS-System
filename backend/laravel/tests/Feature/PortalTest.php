<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\RequirementType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The portal's security model is that no endpoint accepts a record identifier —
 * each resolves the applicant from the signed-in user's own link. These tests
 * exist to keep it that way.
 */
class PortalTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function portalUserFor($applicant, ?int $employeeId = null): User
    {
        $user = User::factory()->create([
            'email' => 'portal'.$applicant->id.'@example.test',
            'user_type' => $employeeId ? 'employee' : 'applicant',
            'applicant_id' => $applicant->id,
            'employee_id' => $employeeId,
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('portal');

        return $user;
    }

    public function test_portal_user_sees_their_own_application(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['first_name' => 'Maria', 'current_status' => 'initial_screening']);
        $this->verifyRequirements($applicant, $hr, 'primary');

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.person.full_name', $applicant->full_name)
            ->assertJsonPath('data.application.status', 'initial_screening')
            // The explanation is written for the applicant, not with HR's
            // internal vocabulary.
            ->assertJsonPath('data.application.explanation', 'Your application is being reviewed and your documents are being checked.');
    }

    /**
     * The central guarantee. A portal account holds no staff permissions, so
     * every staff route must refuse it outright.
     */
    public function test_portal_user_cannot_reach_staff_endpoints(): void
    {
        $hr = $this->hrUser();
        $mine = $this->applicant($hr, ['first_name' => 'Mine']);
        $someoneElse = $this->applicant($hr, ['first_name' => 'Private', 'last_name' => 'Person']);

        Sanctum::actingAs($this->portalUserFor($mine));

        // Cannot list the applicant pool.
        $this->getJson('/api/v1/applicants')->assertStatus(403);

        // Cannot open another applicant's record.
        $this->getJson("/api/v1/applicants/{$someoneElse->id}")->assertStatus(403);

        // Cannot read anyone's documents.
        $this->getJson("/api/v1/applicants/{$someoneElse->id}/requirements")->assertStatus(403);

        // Cannot reach administration or reporting.
        $this->getJson('/api/v1/users')->assertStatus(403);
        $this->getJson('/api/v1/reports')->assertStatus(403);
        $this->getJson('/api/v1/dashboard/summary')->assertStatus(403);
        $this->getJson('/api/v1/audit-logs')->assertStatus(403);
    }

    /**
     * The portal takes no ID from the client, so a portal user cannot download
     * another applicant's birth certificate by changing a number in the URL.
     */
    public function test_portal_document_download_is_scoped_to_the_signed_in_person(): void
    {
        $hr = $this->hrUser();
        $mine = $this->applicant($hr, ['first_name' => 'Mine']);
        $theirs = $this->applicant($hr, ['first_name' => 'Theirs']);

        $this->verifyRequirements($mine, $hr, 'all');
        $this->verifyRequirements($theirs, $hr, 'all');

        // Give the other applicant's document a file, so the only thing standing
        // between the two accounts is the scoping.
        $theirDocument = $theirs->requirements()->first();
        $theirDocument->update(['file_path' => 'requirements/999/secret.pdf', 'file_name' => 'secret.pdf']);

        Sanctum::actingAs($this->portalUserFor($mine));

        // The requirement type id is shared between applicants, but the lookup
        // is constrained to the signed-in applicant, so this resolves to their
        // own row - which has no file - rather than the other person's.
        $response = $this->getJson("/api/v1/portal/documents/{$theirDocument->requirement_type_id}/download");

        $this->assertContains($response->status(), [404], 'Must not return another applicant\'s document.');
        $response->assertJsonMissing(['file_name' => 'secret.pdf']);
    }

    public function test_staff_cannot_use_the_portal_routes(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->getJson('/api/v1/portal')
            ->assertStatus(403)
            ->assertJsonPath('message', 'The portal is for applicants and employees. Staff should use the main system.');
    }

    public function test_portal_user_may_update_contact_details_only(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['first_name' => 'Original', 'last_name' => 'Name']);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->patchJson('/api/v1/portal/profile', [
            'contact_number' => '09171234567',
            'email' => 'new@example.test',
            // Verified against documents at the office; must be ignored.
            'first_name' => 'Tampered',
            'last_name' => 'Tampered',
        ])->assertOk();

        $applicant->refresh();
        $this->assertSame('09171234567', $applicant->contact_number);
        $this->assertSame('Original', $applicant->first_name, 'Name must not be editable from the portal.');
        $this->assertSame('Name', $applicant->last_name);
    }

    public function test_applicant_without_employment_cannot_file_a_resignation(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson('/api/v1/portal/resignation', [
            'reason' => 'Testing',
            'filing_date' => now()->toDateString(),
        ])->assertStatus(403);
    }

    public function test_status_change_notifies_the_applicant(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'applied']);

        app(\App\Services\ApplicantLifecycleService::class)
            ->transition($applicant, 'initial_screening', $hr, 'Documents received.');

        Sanctum::actingAs($this->portalUserFor($applicant));

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertGreaterThan(0, $response->json('data.unread_count'));
        $this->assertSame(
            'Your application is being screened',
            $response->json('data.notifications.0.title')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Sending documents ahead of the office visit  (portal_documents_upload_marker)
    |--------------------------------------------------------------------------
    |
    | The agency requires applicants to submit documents in person. Uploading
    | here does not replace that - it lets a scan arrive early so a problem is
    | found before the applicant travels. The tests below exist to keep the two
    | apart: an upload must never be able to verify anything.
    */

    public function test_an_applicant_can_send_a_document_before_visiting(): void
    {
        Storage::fake('documents');

        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->primary()->where('has_expiry', false)->first();

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => UploadedFile::fake()->create('birth-certificate.pdf', 200, 'application/pdf'),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('applicant_requirements', [
            'applicant_id' => $applicant->id,
            'requirement_type_id' => $type->id,
            'status' => 'submitted',
        ]);
    }

    /**
     * The central guarantee of this feature. If an upload could reach "verified"
     * the office visit would be optional in practice, and an applicant could
     * make themselves deployable from their sofa.
     */
    public function test_an_upload_is_never_verified_and_never_makes_an_applicant_deployable(): void
    {
        Storage::fake('documents');

        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);

        Sanctum::actingAs($this->portalUserFor($applicant));

        foreach (RequirementType::active()->where('is_required', true)->get() as $type) {
            $this->postJson("/api/v1/portal/documents/{$type->id}/upload", [
                'file' => UploadedFile::fake()->create('doc.pdf', 120, 'application/pdf'),
                'expiry_date' => $type->has_expiry ? now()->addYear()->toDateString() : null,
            ])->assertOk();
        }

        $applicant->refresh()->unsetRelation('requirements');

        $this->assertSame(
            0,
            $applicant->requirements()->where('status', 'verified')->count(),
            'Nothing an applicant uploads may count as verified.'
        );
        $this->assertSame('folder_3', $applicant->folder_category);
    }

    public function test_a_document_already_accepted_by_the_office_cannot_be_replaced(): void
    {
        Storage::fake('documents');

        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $this->verifyRequirements($applicant, $hr, 'primary');

        $type = RequirementType::active()->primary()->where('is_required', true)->first();

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => UploadedFile::fake()->create('replacement.pdf', 100, 'application/pdf'),
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'already been accepted'));

        // The accepted document is untouched, so the applicant keeps the
        // standing they had already earned.
        $this->assertSame('verified', $applicant->requirements()
            ->where('requirement_type_id', $type->id)->first()->status);
    }

    public function test_a_document_that_expires_must_carry_its_expiry_date(): void
    {
        Storage::fake('documents');

        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->where('has_expiry', true)->first();

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => UploadedFile::fake()->create('clearance.pdf', 100, 'application/pdf'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expiry_date');
    }

    public function test_an_already_expired_document_is_refused(): void
    {
        Storage::fake('documents');

        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->where('has_expiry', true)->first();

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => UploadedFile::fake()->create('clearance.pdf', 100, 'application/pdf'),
            'expiry_date' => now()->subDay()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expiry_date');
    }

    /**
     * The URL carries a requirement type but never an applicant, so there is no
     * identifier for a curious user to change. This confirms the upload lands on
     * the signed-in applicant's own record and nobody else's.
     */
    public function test_an_upload_lands_on_the_signed_in_applicants_own_record(): void
    {
        Storage::fake('documents');

        $hr = $this->hrUser();
        $mine = $this->applicant($hr, ['first_name' => 'Mine']);
        $theirs = $this->applicant($hr, ['first_name' => 'Theirs']);
        $type = RequirementType::active()->primary()->where('has_expiry', false)->first();

        Sanctum::actingAs($this->portalUserFor($mine));

        $this->postJson("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertOk();

        $this->assertSame(1, $mine->requirements()->where('status', 'submitted')->count());
        $this->assertSame(0, $theirs->requirements()->where('status', 'submitted')->count());
    }

    public function test_staff_cannot_reach_the_portal_upload(): void
    {
        $hr = $this->hrUser();
        $this->applicant($hr);
        $type = RequirementType::active()->primary()->first();

        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/portal/documents/{$type->id}/upload", [
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertForbidden();
    }

    /**
     * The upload form has to know which documents expire.
     *
     * Without has_expiry on the payload the client cannot render the date field,
     * and every upload of an expiring document fails validation with nothing on
     * screen for the applicant to correct.
     */
    public function test_the_document_list_says_which_documents_expire(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $this->verifyRequirements($applicant, $hr, 'none');

        Sanctum::actingAs($this->portalUserFor($applicant));

        $rows = collect($this->getJson('/api/v1/portal/documents')->assertOk()->json('data.requirements'));

        $this->assertNotEmpty($rows);
        $rows->each(fn ($row) => $this->assertArrayHasKey('has_expiry', $row));
        $this->assertTrue(
            $rows->contains(fn ($row) => $row['has_expiry'] === true),
            'At least one requirement expires, so the flag must be able to be true.'
        );
    }
}
