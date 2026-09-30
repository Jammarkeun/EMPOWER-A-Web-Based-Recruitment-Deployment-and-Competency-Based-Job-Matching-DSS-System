<?php

namespace Tests\Feature;

use App\Models\RequirementType;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    // ----------------------------------------------------------- permissions

    public function test_hr_can_read_settings_but_not_change_them(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->getJson('/api/v1/settings')
            ->assertOk()
            // Drives whether the screen renders editable controls at all.
            ->assertJsonPath('data.can_edit', false);

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'empower.expiry_warning_days', 'value' => 60]],
        ])->assertStatus(403);
    }

    public function test_administrator_can_change_settings(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.can_edit', true);

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'empower.expiry_warning_days', 'value' => 45]],
        ])->assertOk();

        $this->assertDatabaseHas('system_settings', ['key' => 'empower.expiry_warning_days']);
    }

    public function test_portal_user_cannot_reach_settings(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);

        $portal = \App\Models\User::factory()->create([
            'email' => 'portal.settings@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]);
        $portal->assignRole('portal');

        Sanctum::actingAs($portal);

        $this->getJson('/api/v1/settings')->assertStatus(403);
    }

    // ------------------------------------------------------------- behaviour

    /**
     * The point of the settings screen: a saved value must actually change what
     * the system does, not merely sit in a table.
     */
    public function test_changing_a_recommendation_band_changes_the_wording(): void
    {
        $settings = app(SettingsService::class);

        // A score of 80 sits in "recommended" under the shipped bands.
        $this->assertSame(85, config('empower.recommendation_bands.highly_recommended'));

        $settings->set('empower.recommendation_bands.highly_recommended', 75);

        // Applied to the live config immediately, so the same request sees it.
        $this->assertSame(75, config('empower.recommendation_bands.highly_recommended'));

        // And it survives into a fresh read through the service.
        $this->assertSame(75, $settings->get('empower.recommendation_bands.highly_recommended'));
    }

    public function test_settings_fall_back_to_config_when_not_overridden(): void
    {
        $settings = app(SettingsService::class);

        // Nothing stored, so the shipped default is returned.
        $this->assertDatabaseCount('system_settings', 0);
        $this->assertSame(
            config('empower.uploads.max_size_kb'),
            $settings->get('empower.uploads.max_size_kb')
        );
    }

    public function test_resetting_returns_a_setting_to_its_default(): void
    {
        $settings = app(SettingsService::class);
        $original = config('empower.expiry_warning_days');

        $settings->set('empower.expiry_warning_days', 90);
        $this->assertSame(90, $settings->get('empower.expiry_warning_days'));

        $settings->reset('empower.expiry_warning_days');
        $this->assertDatabaseCount('system_settings', 0);
        $this->assertSame($original, $settings->get('empower.expiry_warning_days'));
    }

    // -------------------------------------------------------------- validation

    /**
     * Bands saved out of order would make one unreachable, so the whole set is
     * checked together rather than each value on its own.
     */
    public function test_recommendation_bands_must_descend(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->putJson('/api/v1/settings', [
            'settings' => [
                ['key' => 'empower.recommendation_bands.highly_recommended', 'value' => 50],
                ['key' => 'empower.recommendation_bands.recommended', 'value' => 70],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The recommendation bands must descend: highly recommended above recommended, and recommended above reserve pool.');
    }

    public function test_equal_recommendation_bands_are_rejected(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->putJson('/api/v1/settings', [
            'settings' => [
                ['key' => 'empower.recommendation_bands.highly_recommended', 'value' => 70],
                ['key' => 'empower.recommendation_bands.recommended', 'value' => 70],
            ],
        ])->assertStatus(422);
    }

    public function test_recommendation_bands_cannot_be_negative_or_zero(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'empower.recommendation_bands.reserve_pool', 'value' => 0]],
        ])->assertStatus(422);
    }

    public function test_partial_band_update_is_checked_against_saved_overrides(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->putJson('/api/v1/settings', [
            'settings' => [
                ['key' => 'empower.recommendation_bands.highly_recommended', 'value' => 80],
                ['key' => 'empower.recommendation_bands.recommended', 'value' => 60],
                ['key' => 'empower.recommendation_bands.reserve_pool', 'value' => 40],
            ],
        ])->assertOk();

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'empower.recommendation_bands.recommended', 'value' => 90]],
        ])->assertStatus(422);
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'empower.recommendation_bands.recommended', 'value' => 400]],
        ])->assertStatus(422);
    }

    /**
     * The editable list is an allow-list, so a crafted request cannot reach an
     * arbitrary config path such as the database password.
     */
    public function test_settings_outside_the_allow_list_are_rejected(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->putJson('/api/v1/settings', [
            'settings' => [['key' => 'database.connections.pgsql.password', 'value' => 'hijacked']],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------ reference records

    public function test_administrator_can_add_a_requirement_type(): void
    {
        Sanctum::actingAs($this->adminUser());

        $this->postJson('/api/v1/settings/requirement-types', [
            'requirement_code' => 'nbi_clearance',
            'requirement_name' => 'NBI Clearance',
            'requirement_group' => 'primary',
            'is_required' => true,
            'has_expiry' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('requirement_types', [
            'requirement_code' => 'nbi_clearance',
            'active_flag' => true,
        ]);
    }

    public function test_hr_cannot_add_a_requirement_type(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->postJson('/api/v1/settings/requirement-types', [
            'requirement_code' => 'unauthorised',
            'requirement_name' => 'Unauthorised',
            'requirement_group' => 'primary',
            'is_required' => true,
            'has_expiry' => false,
        ])->assertStatus(403);
    }

    /**
     * Making a document required must affect the applicants already on file, or
     * the folder categories quietly stop reflecting the agency's own rules.
     */
    public function test_making_a_document_required_affects_existing_applicants(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $this->verifyRequirements($applicant, $hr, 'all');

        $folders = app(\App\Services\FolderCategoryService::class);
        $this->assertSame('folder_1', $folders->determine($applicant));

        // Introduce a new required document that nobody has yet.
        Sanctum::actingAs($this->adminUser());
        $this->postJson('/api/v1/settings/requirement-types', [
            'requirement_code' => 'nbi_clearance',
            'requirement_name' => 'NBI Clearance',
            'requirement_group' => 'primary',
            'is_required' => true,
            'has_expiry' => false,
        ])->assertCreated();

        $applicant->unsetRelation('requirements');

        // The applicant is no longer deployable, which is correct: the agency
        // now requires a document they have not submitted.
        $this->assertSame('folder_3', $folders->determine($applicant->fresh()));
        $this->assertContains('NBI Clearance', $folders->explain($applicant->fresh())['missing_primary']);
    }

    public function test_deactivating_a_requirement_stops_it_blocking_deployment(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);
        $this->verifyRequirements($applicant, $hr, 'primary');

        $folders = app(\App\Services\FolderCategoryService::class);
        $this->assertSame('folder_2', $folders->determine($applicant));

        // Turn off every final requirement, as an agency might if a client stops
        // asking for medicals.
        Sanctum::actingAs($this->adminUser());
        foreach (RequirementType::where('requirement_group', 'final')->get() as $type) {
            $this->patchJson("/api/v1/settings/requirement-types/{$type->id}", ['active_flag' => false])
                ->assertOk();
        }

        $applicant->unsetRelation('requirements');

        // With no active final requirements the applicant is deployable.
        $this->assertSame('folder_1', $folders->determine($applicant->fresh()));
    }
}
