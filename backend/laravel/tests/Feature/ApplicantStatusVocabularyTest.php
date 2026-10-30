<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ApplicantStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * One applicant-status vocabulary for every screen (audit CQ-02, UX-05,
 * UX-06, UX-08, WF-06), and a list search that finds people by their name.
 */
class ApplicantStatusVocabularyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    public function test_the_stages_list_every_open_status_in_lifecycle_order(): void
    {
        $onStages = array_merge(...array_column(ApplicantStatuses::STAGES, 'statuses'));
        $lifecycle = array_keys(config('empower.applicant_transitions'));

        // The path, then the statuses that close a record - nothing missing,
        // nothing twice, nothing out of order.
        $this->assertSame($lifecycle, array_merge($onStages, ApplicantStatuses::CLOSED));
    }

    public function test_ready_for_deployment_comes_before_training_and_the_client(): void
    {
        // The staff progress path used to draw "ready for deployment" after
        // training and client evaluation, showing both as done for someone
        // who had only finished their documents.
        $this->assertLessThan(
            ApplicantStatuses::stageNumber('client_evaluation'),
            ApplicantStatuses::stageNumber('ready_for_deployment')
        );
        $this->assertSame(
            ApplicantStatuses::stageNumber('ready_for_deployment'),
            ApplicantStatuses::stageNumber('training_completed')
        );
        $this->assertNull(ApplicantStatuses::stageNumber('archived'));
    }

    public function test_staff_read_the_vocabulary_and_portal_accounts_do_not(): void
    {
        Sanctum::actingAs($this->hrUser());

        $response = $this->getJson('/api/v1/applicant-statuses')->assertOk();

        $this->assertSame(
            array_keys(config('empower.applicant_transitions')),
            array_column($response->json('data.statuses'), 'value')
        );
        $response->assertJsonPath('data.statuses.0.label', 'Applied')
            ->assertJsonPath('data.stages.3.key', 'training');

        $applicant = $this->applicant($this->hrUser());
        $portal = User::factory()->create([
            'email' => 'portal.'.uniqid().'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]);
        $portal->assignRole('portal');
        Sanctum::actingAs($portal);

        $this->getJson('/api/v1/applicant-statuses')->assertForbidden();
    }

    public function test_a_status_that_outruns_the_folder_is_flagged_and_can_be_listed(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        // The folder is derived and never mass-assigned, so it is set directly.
        $inFolder = fn ($applicant, string $folder) => tap($applicant, fn ($a) => $a->forceFill(['folder_category' => $folder])->save());

        $lapsed = $inFolder($this->applicant($hr, ['first_name' => 'Lapsed', 'current_status' => 'ready_for_deployment']), 'folder_2');
        $fine = $inFolder($this->applicant($hr, ['first_name' => 'Fine', 'current_status' => 'ready_for_deployment']), 'folder_1');
        $early = $inFolder($this->applicant($hr, ['first_name' => 'Early', 'current_status' => 'applied']), 'folder_3');

        $rows = collect($this->getJson('/api/v1/applicants?per_page=50')->json('data'))->keyBy('id');
        $this->assertTrue($rows[$lapsed->id]['documents_lapsed']);
        $this->assertFalse($rows[$fine->id]['documents_lapsed']);
        $this->assertFalse($rows[$early->id]['documents_lapsed']);

        $this->assertSame(
            [$lapsed->id],
            array_column($this->getJson('/api/v1/applicants?documents_lapsed=1')->json('data'), 'id')
        );
    }

    public function test_a_full_name_finds_the_person_word_by_word(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $juan = $this->applicant($hr, ['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'contact_number' => '09171234567']);
        $other = $this->applicant($hr, ['first_name' => 'Juan', 'last_name' => 'Reyes', 'contact_number' => '09189999999']);

        $this->assertSame([$juan->id], array_column($this->getJson('/api/v1/applicants?search=Juan Cruz')->json('data'), 'id'));
        $this->assertSame([$juan->id], array_column($this->getJson('/api/v1/applicants?search=cruz 0917')->json('data'), 'id'));
        $this->assertEqualsCanonicalizing(
            [$juan->id, $other->id],
            array_column($this->getJson('/api/v1/applicants?search=juan')->json('data'), 'id')
        );
        $this->assertSame([], $this->getJson('/api/v1/applicants?search=Juan Garcia')->json('data'));
    }

    public function test_filters_combine_with_the_search_and_reject_unknown_statuses(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $client = $this->clientCompany($hr);
        $elsewhere = $this->clientCompany($hr, ['company_name' => 'Another Client']);
        $position = $this->jobPosition($client, $hr);
        $otherPosition = $this->jobPosition($elsewhere, $hr, ['position_title' => 'Packer']);

        $match = $this->applicant($hr, ['first_name' => 'Ana', 'preferred_position_id' => $position->id, 'current_status' => 'initial_screening']);
        $this->applicant($hr, ['first_name' => 'Ana', 'preferred_position_id' => $otherPosition->id, 'current_status' => 'initial_screening']);
        $this->applicant($hr, ['first_name' => 'Ana', 'preferred_position_id' => $position->id, 'current_status' => 'applied']);

        $ids = array_column(
            $this->getJson("/api/v1/applicants?search=ana&client_company_id={$client->id}&current_status=initial_screening")->json('data'),
            'id'
        );
        $this->assertSame([$match->id], $ids);

        $this->getJson('/api/v1/applicants?current_status=hired')
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_status');
    }

    public function test_a_closed_record_is_not_shown_as_the_last_stage_in_the_portal(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'archived']);
        $portal = User::factory()->create([
            'email' => 'portal.'.uniqid().'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
            'password' => Hash::make('password'),
        ]);
        $portal->assignRole('portal');
        Sanctum::actingAs($portal);

        $this->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.application.stage_number', null)
            ->assertJsonPath('data.application.record_closed', true);
    }
}
