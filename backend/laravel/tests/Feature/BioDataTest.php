<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicantBioDataVersion;
use App\Models\ApplicantRequirement;
use App\Models\RequirementType;
use App\Models\User;
use App\Notifications\BioDataReviewed;
use App\Notifications\BioDataSubmitted;
use App\Services\BioDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The digital Bio-Data: an applicant's draft, their submission, HR's review,
 * the PDF, and how all of it reaches the one `bio_data` checklist row that
 * folders, the review inbox and the Documents module already read.
 */
class BioDataTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->hr = $this->hrUser();
    }

    private function portalUserFor(Applicant $applicant): User
    {
        if ($existing = User::where('applicant_id', $applicant->id)->first()) {
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

    private function bioType(): RequirementType
    {
        return RequirementType::where('requirement_code', 'bio_data')->firstOrFail();
    }

    private function bioRow(Applicant $applicant): ?ApplicantRequirement
    {
        return ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $this->bioType()->id)
            ->first();
    }

    private function validForm(array $overrides = []): array
    {
        return array_merge([
            'sex' => 'female',
            'civil_status' => 'married',
            'nationality' => 'Filipino',
            'height_cm' => 158,
            'weight_kg' => 52.5,
            'provincial_address' => 'Brgy. Calios, Sta. Cruz, Laguna',
            'educations' => [
                ['education_level' => 'senior_high_school', 'school_name' => 'Sta. Cruz Integrated NHS', 'course_program' => 'TVL', 'graduation_year' => 2019, 'honors' => null],
            ],
            'experiences' => [
                ['company_name' => 'Laguna Foods Inc.', 'position_title' => 'Packer', 'start_date' => '2020-01-06', 'end_date' => '2022-03-31', 'responsibilities' => 'Packing line'],
            ],
        ], $overrides);
    }

    /** An applicant who has submitted a valid Bio-Data through the portal. */
    private function submitted(?Applicant $applicant = null): Applicant
    {
        $applicant ??= $this->applicant($this->hr, ['current_status' => 'initial_screening', 'civil_status' => 'single']);
        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->putJson('/api/v1/portal/bio-data/draft', $this->validForm())->assertOk();
        $this->postJson('/api/v1/portal/bio-data/submit', ['confirm' => true])->assertOk();

        return $applicant->fresh();
    }

    // -------------------------------------------------------- applicant side

    public function test_an_incomplete_draft_is_saved_without_touching_the_requirement_or_folder(): void
    {
        $applicant = $this->applicant($this->hr, ['current_status' => 'initial_screening']);
        $this->verifyRequirements($applicant, $this->hr, 'none');
        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->putJson('/api/v1/portal/bio-data/draft', [
            'civil_status' => 'single',
            'educations' => [],
            'experiences' => [],
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'draft')
            ->assertJsonPath('data.form.civil_status', 'single')
            ->assertJsonPath('data.can_edit', true)
            // What still blocks submission is listed, field by field.
            ->assertJsonStructure(['data' => ['missing' => ['nationality', 'educations']]]);

        $this->assertSame('missing', $this->bioRow($applicant)->status);
        $this->assertSame('folder_3', $applicant->fresh()->folder_category);
        // The draft is not the profile.
        $this->assertNull($applicant->fresh()->nationality);
    }

    public function test_a_complete_bio_data_is_submitted_as_a_version_and_never_as_verified(): void
    {
        Notification::fake();

        $applicant = $this->submitted();
        $row = $this->bioRow($applicant);

        $this->assertSame('submitted', $row->status);
        $this->assertSame(ApplicantRequirement::VIA_DIGITAL_FORM, $row->submitted_via);
        $this->assertNull($row->verified_at);
        $this->assertFalse($row->countsAsComplete());

        $version = $applicant->bioDataVersions()->first();
        $this->assertSame(1, $version->version_number);
        $this->assertTrue($version->isPending());
        $this->assertSame('married', $version->data['form']['civil_status']);
        // The profile as it stood is kept with the version.
        $this->assertSame($applicant->applicant_code, $version->data['profile']['applicant_code']);
        // The draft was consumed.
        $this->assertNull($applicant->bioData->draft);

        Notification::assertSentTo($this->hr, BioDataSubmitted::class);

        $this->getJson('/api/v1/portal/bio-data')
            ->assertJsonPath('data.state', 'submitted')
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.submitted.civil_status', 'married');
    }

    public function test_missing_and_invalid_values_are_refused(): void
    {
        $applicant = $this->applicant($this->hr, ['birth_date' => '2000-05-01']);
        Sanctum::actingAs($this->portalUserFor($applicant));

        // Malformed values are refused even in a draft.
        $this->putJson('/api/v1/portal/bio-data/draft', $this->validForm([
            'height_cm' => 900,
            'sex' => 'other',
            'experiences' => [
                ['company_name' => 'A', 'position_title' => 'B', 'start_date' => '2021-05-01', 'end_date' => '2020-01-01'],
                ['company_name' => 'C', 'position_title' => 'D', 'start_date' => now()->addMonth()->toDateString()],
                ['company_name' => 'E', 'position_title' => 'F', 'start_date' => '1999-01-01'],
            ],
            'educations' => [['education_level' => 'kindergarten', 'school_name' => 'X', 'graduation_year' => 1990]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'height_cm', 'sex',
                'experiences.0.end_date', 'experiences.1.start_date', 'experiences.2.start_date',
                'educations.0.education_level', 'educations.0.graduation_year',
            ]);

        // A draft missing required answers saves, but cannot be sent.
        $this->putJson('/api/v1/portal/bio-data/draft', ['educations' => [], 'experiences' => []])->assertOk();
        $this->postJson('/api/v1/portal/bio-data/submit', ['confirm' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sex', 'civil_status', 'nationality', 'educations']);

        // Nor without confirming it is true and correct.
        $this->putJson('/api/v1/portal/bio-data/draft', $this->validForm())->assertOk();
        $this->postJson('/api/v1/portal/bio-data/submit', [])->assertStatus(422)->assertJsonValidationErrors(['confirm']);

        $this->assertSame(0, $applicant->bioDataVersions()->count());
    }

    public function test_an_applicant_cannot_reach_or_change_another_applicants_bio_data(): void
    {
        $theirs = $this->submitted($this->applicant($this->hr, ['first_name' => 'Theirs', 'civil_status' => 'single']));
        $mine = $this->applicant($this->hr, ['first_name' => 'Mine']);

        Sanctum::actingAs($this->portalUserFor($mine));

        // Ids, statuses and flags sent along are dropped by the form.
        $this->putJson('/api/v1/portal/bio-data/draft', $this->validForm([
            'applicant_id' => $theirs->id,
            'status' => 'verified',
            'review_outcome' => 'verified',
            'civil_status' => 'widowed',
        ]))->assertOk()->assertJsonMissingPath('data.form.applicant_id');

        $this->assertSame('married', $theirs->bioDataVersions()->first()->data['form']['civil_status']);
        $this->assertSame('widowed', $mine->fresh()->bioData->draft['civil_status']);
        $this->assertSame('missing', $this->bioRow($mine)?->status ?? 'missing');

        // The staff routes, which take an id, are closed to portal accounts.
        $this->getJson("/api/v1/applicants/{$theirs->id}/bio-data")->assertForbidden();
        $this->getJson("/api/v1/applicants/{$theirs->id}/bio-data/pdf")->assertForbidden();
        $this->postJson("/api/v1/applicants/{$theirs->id}/bio-data/review", ['outcome' => 'verified'])->assertForbidden();

        // The portal PDF is always the signed-in applicant's own.
        $this->get('/api/v1/portal/bio-data/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    // -------------------------------------------------------------- HR side

    public function test_opening_a_submission_puts_it_under_review(): void
    {
        $applicant = $this->submitted();
        Sanctum::actingAs($this->hr);

        $this->getJson("/api/v1/applicants/{$applicant->id}/bio-data")
            ->assertOk()
            ->assertJsonPath('data.state', 'under_review')
            ->assertJsonPath('data.can_review', true)
            ->assertJsonPath('data.versions.0.data.form.civil_status', 'married')
            // What verifying would write onto the profile, shown before it happens.
            ->assertJsonPath('data.versions.0.would_change.civil_status', ['single', 'married']);

        $this->assertSame($this->hr->id, $this->bioRow($applicant)->first_viewed_by);
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'view', 'record_type' => 'ApplicantBioDataVersion']);
    }

    public function test_hr_verifies_and_the_declared_details_reach_the_profile(): void
    {
        Notification::fake();

        $applicant = $this->submitted();
        $versionBefore = (int) $applicant->profile_version;
        Sanctum::actingAs($this->hr);

        $this->postJson("/api/v1/applicants/{$applicant->id}/bio-data/review", ['outcome' => 'verified'])
            ->assertOk()
            ->assertJsonPath('data.requirement.status', 'verified')
            ->assertJsonPath('data.requirement.verification_method', 'online_form')
            ->assertJsonPath('data.requirement.verification_method_label', 'Digital form');

        $fresh = $applicant->fresh();
        $this->assertSame('married', $fresh->civil_status);
        $this->assertSame('Filipino', $fresh->nationality);
        $this->assertEquals(158, (float) $fresh->height_cm);
        // Height is scored, so rankings computed before are marked out of date.
        $this->assertGreaterThan($versionBefore, (int) $fresh->profile_version);

        $version = $fresh->bioDataVersions()->first();
        $this->assertSame('verified', $version->review_outcome);
        $this->assertSame($this->hr->id, $version->reviewed_by);
        $this->assertNotNull($version->reviewed_at);
        $this->assertSame(['single', 'married'], $version->applied_changes['civil_status']);

        $row = $this->bioRow($applicant);
        $this->assertSame($this->hr->id, $row->verified_by);
        $this->assertNotNull($row->verified_at);

        Notification::assertSentTo($fresh, BioDataReviewed::class);
        $this->assertDatabaseHas('audit_logs', ['module_key' => 'applicants', 'action_type' => 'update', 'record_id' => $applicant->id]);

        // Verified closes the form to the applicant.
        Sanctum::actingAs($this->portalUserFor($fresh));
        $this->putJson('/api/v1/portal/bio-data/draft', $this->validForm())->assertStatus(409);
    }

    public function test_hr_returns_it_for_correction_and_every_version_is_kept(): void
    {
        $applicant = $this->submitted();
        Sanctum::actingAs($this->hr);

        $this->postJson("/api/v1/applicants/{$applicant->id}/bio-data/review", ['outcome' => 'needs_correction'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['note']);

        $this->postJson("/api/v1/applicants/{$applicant->id}/bio-data/review", [
            'outcome' => 'needs_correction',
            'note' => 'Civil status does not match your marriage certificate.',
        ])->assertOk()->assertJsonPath('data.requirement.status', 'needs_correction');

        // Returning it writes nothing to the profile.
        $this->assertSame('single', $applicant->fresh()->civil_status);
        $this->assertSame('Civil status does not match your marriage certificate.', $this->bioRow($applicant)->rejection_reason);

        // The applicant sees why, corrects it from the last submission, and sends it again.
        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->getJson('/api/v1/portal/bio-data')
            ->assertJsonPath('data.state', 'needs_correction')
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.form.civil_status', 'married')
            ->assertJsonPath('data.versions.0.review_note', 'Civil status does not match your marriage certificate.');

        $this->putJson('/api/v1/portal/bio-data/draft', $this->validForm(['civil_status' => 'single']))->assertOk();
        $this->postJson('/api/v1/portal/bio-data/submit', ['confirm' => true])->assertOk();

        $versions = $applicant->bioDataVersions()->get();
        $this->assertCount(2, $versions);
        $this->assertSame([2, 1], $versions->pluck('version_number')->all());
        $this->assertSame([null, 'needs_correction'], $versions->pluck('review_outcome')->all());
        $this->assertSame('married', $versions[1]->data['form']['civil_status']);
        $this->assertSame('submitted', $this->bioRow($applicant)->status);
        $this->assertNull($this->bioRow($applicant)->rejection_reason);

        Sanctum::actingAs($this->hr);
        $this->getJson("/api/v1/applicants/{$applicant->id}/bio-data")
            ->assertJsonCount(2, 'data.versions')
            ->assertJsonPath('data.versions.1.review_note', 'Civil status does not match your marriage certificate.');
    }

    public function test_verifying_from_the_checklist_closes_the_submission_too(): void
    {
        $applicant = $this->submitted();
        Sanctum::actingAs($this->hr);

        // Bulk verify, as at the counter. The form is not left "waiting".
        $this->postJson("/api/v1/applicants/{$applicant->id}/requirements/verify-batch", [
            'requirement_type_ids' => [$this->bioType()->id],
        ])->assertOk();

        $this->assertSame('verified', $applicant->bioDataVersions()->first()->review_outcome);
        $this->assertSame('online_form', $this->bioRow($applicant)->verification_method);
        $this->assertSame('married', $applicant->fresh()->civil_status);
    }

    // ------------------------------------------------------------------- PDF

    public function test_the_pdf_is_drawn_from_what_is_saved(): void
    {
        $applicant = $this->submitted();
        $service = app(BioDataService::class);
        $version = $applicant->bioDataVersions()->first();

        $html = $service->pdfHtml($applicant, $version);
        $this->assertStringContainsString('Laguna Foods Inc.', $html);
        $this->assertStringContainsString('Sta. Cruz Integrated NHS', $html);
        $this->assertStringContainsString('Married', $html);
        $this->assertStringContainsString('SUBMITTED - AWAITING VERIFICATION', $html);
        $this->assertStringNotContainsString('Signature of applicant', $html);

        // Returned and resubmitted with a change: the new PDF has it.
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/applicants/{$applicant->id}/bio-data/review", ['outcome' => 'needs_correction', 'note' => 'Check employer'])->assertOk();
        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->putJson('/api/v1/portal/bio-data/draft', $this->validForm([
            'experiences' => [['company_name' => 'Calios Bakery', 'position_title' => 'Baker', 'start_date' => '2021-02-01']],
        ]))->assertOk();
        $this->postJson('/api/v1/portal/bio-data/submit', ['confirm' => true])->assertOk();

        $latest = $applicant->fresh()->latestBioDataVersion;
        $html = $service->pdfHtml($applicant->fresh(), $latest);
        $this->assertStringContainsString('Calios Bakery', $html);
        $this->assertStringNotContainsString('Laguna Foods Inc.', $html);
        // The earlier version still prints as it was, marked as returned.
        $this->assertStringContainsString('RETURNED FOR CORRECTION', $service->pdfHtml($applicant->fresh(), $version->fresh()));

        // A signature line only when CDE asks for a signed copy.
        config(['empower.bio_data.signed_copy_required' => true]);
        $this->assertStringContainsString('Signature of applicant', $service->pdfHtml($applicant->fresh(), $latest));

        Sanctum::actingAs($this->hr);
        $this->get("/api/v1/applicants/{$applicant->id}/bio-data/pdf?version=1")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseHas('audit_logs', ['action_type' => 'export', 'record_type' => 'ApplicantBioDataVersion']);
    }

    public function test_staff_without_document_permissions_cannot_open_the_bio_data_or_its_pdf(): void
    {
        $applicant = $this->submitted();
        $noAccess = User::factory()->create(['email' => 'clerk@cdemanpower.local', 'user_type' => 'hr']);

        Sanctum::actingAs($noAccess);
        $this->getJson("/api/v1/applicants/{$applicant->id}/bio-data")->assertForbidden();
        $this->get("/api/v1/applicants/{$applicant->id}/bio-data/pdf")->assertForbidden();
        $this->postJson("/api/v1/applicants/{$applicant->id}/bio-data/review", ['outcome' => 'verified'])->assertForbidden();
    }

    // ------------------------------------------------ Documents and folders

    public function test_a_submitted_form_appears_in_the_documents_module_and_review_inbox(): void
    {
        $applicant = $this->submitted();
        Sanctum::actingAs($this->hr);

        $rows = collect($this->getJson("/api/v1/applicants/{$applicant->id}/requirements")->assertOk()->json('data.requirements'));
        $bio = $rows->firstWhere('requirement_code', 'bio_data');

        // One Bio-Data requirement, not two.
        $this->assertSame(1, $rows->where('requirement_code', 'bio_data')->count());
        $this->assertTrue($bio['is_digital_form']);
        $this->assertFalse($bio['has_file']);
        $this->assertSame('Submitted', $bio['review_state_label']);

        $this->getJson('/api/v1/documents/applicants?needs_review=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $applicant->id)
            ->assertJsonPath('data.0.documents.awaiting_review', 1)
            ->assertJsonPath('data.0.documents.unopened', 1);

        $this->getJson("/api/v1/documents/{$this->bioType()->id}")
            ->assertOk()
            ->assertJsonPath('data.0.submitted_via', 'digital_form');

        $this->assertSame(1, ApplicantRequirement::awaitingReview()->count());
    }

    public function test_folders_follow_the_verified_bio_data_and_nothing_else_is_marked_complete(): void
    {
        $applicant = $this->applicant($this->hr, ['current_status' => 'initial_screening']);
        $this->verifyRequirements($applicant, $this->hr, 'primary');
        // Everything primary is verified except the Bio-Data.
        $this->bioRow($applicant)->update(['status' => 'missing', 'verified_at' => null, 'verified_by' => null]);
        $birth = RequirementType::where('requirement_code', 'birth_certificate')->first();
        ApplicantRequirement::where('applicant_id', $applicant->id)->where('requirement_type_id', $birth->id)
            ->update(['status' => 'missing', 'verified_at' => null, 'verified_by' => null]);
        app(\App\Services\FolderCategoryService::class)->recalculate($applicant);
        $this->assertSame('folder_3', $applicant->fresh()->folder_category);

        // A draft and then a submission do not satisfy it.
        $this->submitted($applicant);
        $this->assertSame('folder_3', $applicant->fresh()->folder_category);

        // Verified: the Bio-Data counts, but the birth certificate still holds them in folder 3.
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/applicants/{$applicant->id}/bio-data/review", ['outcome' => 'verified'])
            ->assertOk()
            ->assertJsonPath('data.folder.folder_category', 'folder_3')
            ->assertJsonPath('data.folder.missing_primary', ['PSA Birth Certificate']);

        $this->assertSame('missing', ApplicantRequirement::where('applicant_id', $applicant->id)->where('requirement_type_id', $birth->id)->value('status'));

        // The birth certificate checked at the counter, with no file: folder 2,
        // and not folder 1 - the medical requirements are still outstanding.
        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$birth->id}", ['status' => 'verified'])
            ->assertOk()
            ->assertJsonPath('data.requirement.verification_method', 'walk_in')
            ->assertJsonPath('data.folder.folder_category', 'folder_2');
    }

    // ------------------------------------------------ earlier uploads

    public function test_uploaded_bio_data_files_are_detached_but_kept_and_verifications_stand(): void
    {
        $disk = \Illuminate\Support\Facades\Storage::disk(config('empower.uploads.disk'));
        $make = function (string $status, ?string $file, string $method = 'online_upload') {
            $applicant = $this->applicant($this->hr, ['current_status' => 'initial_screening']);
            $this->verifyRequirements($applicant, $this->hr, 'primary');
            $this->bioRow($applicant)->update(array_filter([
                'status' => $status,
                'file_path' => $file ? "requirements/{$applicant->id}/{$file}" : null,
                'file_name' => $file,
                'submitted_at' => now()->subWeek(),
                'verification_method' => $status === 'verified' ? $method : null,
                'verified_at' => $status === 'verified' ? now()->subDays(3) : null,
                'verified_by' => $status === 'verified' ? $this->hr->id : null,
                'rejection_reason' => $status === 'rejected' ? 'Blurred' : null,
            ], fn ($v) => $v !== null) + ['verified_at' => $status === 'verified' ? now()->subDays(3) : null]);
            app(\App\Services\FolderCategoryService::class)->recalculate($applicant);

            return $applicant->fresh();
        };

        $verifiedUpload = $make('verified', 'bio.pdf');
        $waiting = $make('submitted', 'waiting.pdf');
        $rejected = $make('rejected', 'blurred.pdf');
        $paper = $make('verified', null, 'walk_in');
        $disk->put("requirements/{$verifiedUpload->id}/bio.pdf", '%PDF-1.4');
        $this->assertSame('folder_2', $verifiedUpload->folder_category);

        $migration = require database_path('migrations/2026_11_02_000200_retire_uploaded_bio_data_files.php');
        $migration->up();

        // Verified stays verified, folder unchanged; the file is off the checklist.
        $row = $this->bioRow($verifiedUpload);
        $this->assertSame('verified', $row->status);
        $this->assertNull($row->file_path);
        $this->assertSame('folder_2', app(\App\Services\FolderCategoryService::class)->recalculate($verifiedUpload));

        // ...but kept: in the document's history, and in storage.
        $this->assertDatabaseHas('applicant_requirement_history', [
            'applicant_requirement_id' => $row->id,
            'reason' => 'upload_retired',
            'file_path' => "requirements/{$verifiedUpload->id}/bio.pdf",
            'status' => 'verified',
        ]);
        $this->assertTrue($disk->exists("requirements/{$verifiedUpload->id}/bio.pdf"));

        // Nothing left to review in a waiting or rejected upload: not sent.
        foreach ([$waiting, $rejected] as $applicant) {
            $this->assertSame('missing', $this->bioRow($applicant)->status);
            $this->assertNull($this->bioRow($applicant)->file_path);
            $this->assertNull($this->bioRow($applicant)->rejection_reason);
        }
        $this->assertSame('verified', $this->bioRow($paper)->status);
        $this->assertSame(0, ApplicantRequirement::awaitingReview()->count());

        // Nobody can open the old file any more.
        Sanctum::actingAs($this->hr);
        $this->getJson("/api/v1/applicants/{$verifiedUpload->id}/requirements/{$this->bioType()->id}/download")->assertNotFound();
        Sanctum::actingAs($this->portalUserFor($verifiedUpload));
        $this->getJson("/api/v1/portal/documents/{$this->bioType()->id}/download")->assertNotFound();

        // Reversible.
        $migration->down();
        $this->assertSame("requirements/{$verifiedUpload->id}/bio.pdf", $this->bioRow($verifiedUpload)->file_path);
        $this->assertSame('submitted', $this->bioRow($waiting)->status);
    }

    public function test_an_account_verified_earlier_is_asked_for_the_form_and_keeps_its_folder(): void
    {
        Notification::fake();

        $applicant = $this->applicant($this->hr, ['current_status' => 'initial_screening', 'civil_status' => 'single']);
        $this->verifyRequirements($applicant, $this->hr, 'primary');
        $this->bioRow($applicant)->update(['verification_method' => 'walk_in']);
        app(\App\Services\FolderCategoryService::class)->recalculate($applicant);
        $this->assertSame('folder_2', $applicant->fresh()->folder_category);

        // The form shows, open, on an account whose Bio-Data was already accepted.
        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->getJson('/api/v1/portal/bio-data')
            ->assertJsonPath('data.state', 'not_started')
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.earlier_verification.verified_at', fn ($v) => $v !== null);

        // Sending it keeps the requirement complete, so nobody changes folder,
        // and it still reaches HR's review inbox.
        $this->submitted($applicant);
        $row = $this->bioRow($applicant);
        $this->assertSame('verified', $row->status);
        $this->assertSame('walk_in', $row->verification_method);
        $this->assertSame('folder_2', $applicant->fresh()->folder_category);
        $this->assertSame(1, ApplicantRequirement::awaitingReview()->count());
        $this->getJson('/api/v1/portal/bio-data')->assertJsonPath('data.state', 'submitted')->assertJsonPath('data.can_edit', false);

        Sanctum::actingAs($this->hr);
        $bio = collect($this->getJson("/api/v1/applicants/{$applicant->id}/requirements")->json('data.requirements'))
            ->firstWhere('requirement_code', 'bio_data');
        $this->assertTrue($bio['digital_form_pending']);
        $this->getJson('/api/v1/documents/applicants?needs_review=1')->assertJsonPath('data.0.id', $applicant->id);

        // Returned: still complete on the earlier copy; the applicant sees why.
        $this->postJson("/api/v1/applicants/{$applicant->id}/bio-data/review", ['outcome' => 'needs_correction', 'note' => 'Check civil status'])->assertOk();
        $this->assertSame('verified', $this->bioRow($applicant)->status);
        $this->assertSame('folder_2', $applicant->fresh()->folder_category);
        $this->assertSame(0, ApplicantRequirement::awaitingReview()->count());

        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->getJson('/api/v1/portal/bio-data')
            ->assertJsonPath('data.state', 'needs_correction')
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.versions.0.review_note', 'Check civil status');
        $this->postJson('/api/v1/portal/bio-data/submit', ['confirm' => true])->assertOk();

        // Verified: the form becomes the basis, and the paper verification is kept in history.
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/applicants/{$applicant->id}/bio-data/review", ['outcome' => 'verified'])->assertOk();
        $row = $this->bioRow($applicant);
        $this->assertSame('online_form', $row->verification_method);
        $this->assertDatabaseHas('applicant_requirement_history', [
            'applicant_requirement_id' => $row->id,
            'reason' => 'replaced_by_digital_form',
            'verification_method' => 'walk_in',
        ]);

        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->getJson('/api/v1/portal/bio-data')->assertJsonPath('data.state', 'verified')->assertJsonPath('data.can_edit', false);
        $this->post("/api/v1/portal/documents/{$this->bioType()->id}/upload", [
            'file' => UploadedFile::fake()->create('bio2.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'use_bio_data_form');
    }

    // -------------------------------------------------------------- matching

    public function test_declared_education_never_reaches_the_scored_qualifications(): void
    {
        $applicant = $this->applicant($this->hr, ['current_status' => 'initial_screening', 'civil_status' => 'married', 'sex' => 'female', 'height_cm' => 158]);
        \App\Models\ApplicantEducation::create(['applicant_id' => $applicant->id, 'education_level' => 'high_school', 'school_name' => 'Recorded by HR']);
        $profileVersion = (int) $applicant->fresh()->profile_version;

        // The form opens with what HR recorded, for the applicant to complete.
        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->getJson('/api/v1/portal/bio-data')->assertJsonPath('data.form.educations.0.school_name', 'Recorded by HR');

        $this->submitted($applicant);
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/applicants/{$applicant->id}/bio-data/review", ['outcome' => 'verified'])->assertOk();

        $fresh = $applicant->fresh()->load('educations');
        $this->assertSame(['Recorded by HR'], $fresh->educations->pluck('school_name')->all());
        $this->assertSame('high_school', $fresh->highestEducationLevel());
        // Nothing scored changed (sex and height were already on file), so
        // earlier rankings stay current.
        $this->assertSame($profileVersion, (int) $fresh->profile_version);
    }

    public function test_the_bio_data_stays_reachable_after_deployment(): void
    {
        $applicant = $this->submitted();
        $employee = \App\Models\Employee::create([
            'applicant_id' => $applicant->id,
            'employee_number' => 'EMP-T-1',
            'hire_date' => now()->toDateString(),
            'created_by' => $this->hr->id,
        ]);
        // The login re-provisioned as an employee's, as the office does.
        $login = User::where('applicant_id', $applicant->id)->firstOrFail();
        $login->forceFill(['user_type' => 'employee', 'employee_id' => $employee->id])->save();

        Sanctum::actingAs($login->fresh());
        $this->getJson('/api/v1/portal/bio-data')
            ->assertOk()
            ->assertJsonPath('data.profile.applicant_code', $applicant->applicant_code)
            ->assertJsonPath('data.submitted.civil_status', 'married');
    }
}
