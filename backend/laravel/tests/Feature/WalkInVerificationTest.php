<?php

namespace Tests\Feature;

use App\Models\ApplicantRequirement;
use App\Models\RequirementType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Verifying documents that were never uploaded.
 *
 * Most of the agency's applicants walk in and hand their papers across the
 * counter. The system used to refuse to record that - verification required a
 * stored file - so those applicants stayed permanently incomplete and staff
 * were scanning documents purely to satisfy the software.
 *
 * These tests pin the rule that replaced it: whether a file exists and whether
 * a person has checked the document are two separate facts.
 */
class WalkInVerificationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_a_requirement_can_be_verified_with_no_file_uploaded(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->primary()->first();

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
            'status' => 'verified',
        ])
            ->assertOk()
            ->assertJsonPath('data.requirement.status', 'verified')
            ->assertJsonPath('data.requirement.upload_status', 'not_uploaded')
            ->assertJsonPath('data.requirement.has_file', false);
    }

    /**
     * The record has to say who checked it and how, or it is worth nothing at
     * an audit six months later.
     */
    public function test_a_walk_in_verification_records_who_checked_it_and_how(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->primary()->first();

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
            'status' => 'verified',
            'verification_note' => 'Original PhilSys ID sighted at the counter.',
        ])->assertOk();

        $row = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)->first();

        $this->assertSame('verified', $row->status);
        $this->assertSame('walk_in', $row->verification_method);
        $this->assertSame($hr->id, $row->verified_by);
        $this->assertNotNull($row->verified_at);
        $this->assertSame('Original PhilSys ID sighted at the counter.', $row->verification_note);
    }

    /**
     * The whole point of the change: a walk-in applicant whose papers were all
     * checked in person must reach the deployable folder, even though not one
     * file was ever stored.
     */
    public function test_an_applicant_with_no_uploads_at_all_can_still_become_deployable(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);

        Sanctum::actingAs($hr);

        foreach (RequirementType::active()->where('is_required', true)->get() as $type) {
            $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
                'status' => 'verified',
                'expiry_date' => $type->has_expiry ? now()->addYear()->toDateString() : null,
            ])->assertOk();
        }

        $applicant->refresh()->unsetRelation('requirements');

        $this->assertSame('folder_1', $applicant->folder_category);
        $this->assertSame(
            0,
            $applicant->requirements()->whereNotNull('file_path')->count(),
            'Not a single document was uploaded, and the applicant is still deployable.'
        );
    }

    public function test_several_requirements_can_be_verified_in_one_action(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $types = RequirementType::active()->primary()->take(4)->pluck('id');

        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/applicants/{$applicant->id}/requirements/verify-batch", [
            'requirement_type_ids' => $types->all(),
            'verification_method' => 'walk_in',
        ])
            ->assertOk()
            ->assertJsonPath('data.verified_count', 4);

        $this->assertSame(4, $applicant->requirements()
            ->whereIn('requirement_type_id', $types)
            ->where('status', 'verified')->count());
    }

    public function test_bulk_verify_ignores_requirements_belonging_to_someone_else(): void
    {
        $hr = $this->hrUser();
        $mine = $this->applicant($hr, ['first_name' => 'Mine']);
        $theirs = $this->applicant($hr, ['first_name' => 'Theirs']);
        $types = RequirementType::active()->primary()->take(3)->pluck('id');

        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/applicants/{$mine->id}/requirements/verify-batch", [
            'requirement_type_ids' => $types->all(),
        ])->assertOk();

        $this->assertSame(0, $theirs->requirements()->where('status', 'verified')->count());
    }

    public function test_a_document_can_be_marked_as_needing_correction(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->primary()->first();

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
            'status' => 'needs_correction',
        ])
            ->assertOk()
            ->assertJsonPath('data.requirement.status', 'needs_correction');
    }

    public function test_expiring_requirement_needs_a_current_expiry_to_be_verified(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->where('has_expiry', true)->first();

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
            'status' => 'verified',
        ])->assertStatus(422)->assertJsonValidationErrors('expiry_date');
    }

    public function test_past_expiry_requires_an_explicit_override_reason(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->where('has_expiry', true)->first();

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
            'status' => 'verified',
            'expiry_date' => now()->subDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('expiry_override_reason');
    }

    public function test_inactive_requirement_cannot_create_a_new_verification_row(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->first();
        $type->update(['active_flag' => false]);

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}", [
            'status' => 'verified',
        ])->assertStatus(422)->assertJsonValidationErrors('requirement_type');
    }

    /**
     * Staff must not be able to upload on an applicant's behalf. The route is
     * gone, and this stops it quietly reappearing.
     */
    public function test_there_is_no_staff_upload_route(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $type = RequirementType::active()->primary()->first();

        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/applicants/{$applicant->id}/requirements/{$type->id}/upload", [])
            ->assertNotFound();
    }
}
