<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\AuditLog;
use App\Models\FolderRecalculation;
use App\Models\RequirementType;
use App\Models\User;
use App\Services\FolderCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Stored folders follow the rules when the rules change (audit R-17).
 *
 * Making a document required, relaxing or retiring one, or a clearance
 * lapsing overnight used to leave every stored folder - and every count built
 * on them - as it was until somebody touched that applicant's documents. Each
 * of those now recalculates folders, writes nothing but folders, records the
 * run, and leaves a failure visible and repeatable.
 */
class FolderRecalculationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    /** Every required document verified, and the folder stored as it stands. */
    private function deployable(User $hr, string $status = 'ready_for_deployment'): Applicant
    {
        $applicant = $this->applicant($hr, ['current_status' => $status]);
        $this->verifyRequirements($applicant, $hr, 'all');
        app(FolderCategoryService::class)->recalculate($applicant);

        $this->assertSame('folder_1', $applicant->fresh()->folder_category);

        return $applicant;
    }

    private function type(string $code): RequirementType
    {
        return RequirementType::where('requirement_code', $code)->sole();
    }

    public function test_making_a_document_required_moves_folders_and_nothing_else(): void
    {
        $hr = $this->hrUser();
        $admin = $this->adminUser();
        $applicant = $this->deployable($hr);

        Sanctum::actingAs($admin);
        $response = $this->patchJson('/api/v1/settings/requirement-types/'.$this->type('coe')->id, ['is_required' => true])
            ->assertOk()
            ->assertJsonPath('data.folder_recalculation.state', 'completed')
            ->assertJsonPath('data.folder_recalculation.applicants_changed', 1);
        $this->assertStringContainsString('were updated to match', $response->json('message'));

        $applicant->refresh();
        $this->assertSame('folder_3', $applicant->folder_category);
        // The folder moved; the applicant's place in the pipeline did not.
        $this->assertSame('ready_for_deployment', $applicant->current_status);

        $run = FolderRecalculation::sole();
        $this->assertSame('requirement_change', $run->trigger);
        $this->assertSame($this->type('coe')->id, (int) $run->requirement_type_id);
        $this->assertSame($admin->id, (int) $run->requested_by);
        $this->assertSame(['folder_1>folder_3' => 1], $run->summary);

        $audit = AuditLog::where('record_type', 'FolderRecalculation')->where('record_id', $run->id)->sole();
        $this->assertSame($applicant->id, $audit->new_values_json['changes'][0]['applicant_id']);
    }

    public function test_relaxing_a_requirement_moves_folders_up(): void
    {
        $hr = $this->hrUser();
        $admin = $this->adminUser();

        $applicant = $this->applicant($hr, ['current_status' => 'pending_final_requirements']);
        $this->verifyRequirements($applicant, $hr, 'primary');
        app(FolderCategoryService::class)->recalculate($applicant);
        $this->assertSame('folder_2', $applicant->fresh()->folder_category);

        Sanctum::actingAs($admin);

        // A client stops asking for medicals: every final requirement retired.
        foreach (RequirementType::where('requirement_group', 'final')->where('is_required', true)->get() as $type) {
            $this->patchJson("/api/v1/settings/requirement-types/{$type->id}", ['active_flag' => false])->assertOk();
        }

        $this->assertSame('folder_1', $applicant->fresh()->folder_category);
        $this->assertSame('pending_final_requirements', $applicant->fresh()->current_status);
    }

    public function test_a_change_that_cannot_move_a_folder_does_not_run_one(): void
    {
        $admin = $this->adminUser();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/settings/requirement-types/'.$this->type('resume')->id, ['requirement_name' => 'Resume / CV'])
            ->assertOk()
            ->assertJsonPath('data.folder_recalculation', null);

        $this->postJson('/api/v1/settings/requirement-types', [
            'requirement_code' => 'nbi_clearance',
            'requirement_name' => 'NBI Clearance',
            'requirement_group' => 'primary',
            'is_required' => false,
            'has_expiry' => true,
        ])->assertCreated();

        $this->assertSame(0, FolderRecalculation::count());
    }

    public function test_a_failed_run_is_recorded_reported_and_can_be_finished(): void
    {
        $hr = $this->hrUser();
        $admin = $this->adminUser();
        $applicant = $this->deployable($hr);

        $this->app->instance(FolderCategoryService::class, new class extends FolderCategoryService
        {
            public function categoryFor(Collection $required, Collection $verified): string
            {
                throw new RuntimeException('simulated failure: SQLSTATE[HY000] secret detail');
            }
        });

        Sanctum::actingAs($admin);
        $response = $this->patchJson('/api/v1/settings/requirement-types/'.$this->type('coe')->id, ['is_required' => true])
            ->assertOk()
            ->assertJsonPath('data.folder_recalculation.state', 'failed')
            ->assertJsonPath('data.folder_recalculation.needs_retry', true);

        // The change itself stands, and the failure is said out loud - without
        // the underlying error.
        $this->assertTrue($this->type('coe')->is_required);
        $this->assertStringContainsString('did not finish', $response->json('message'));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('secret detail', (string) FolderRecalculation::sole()->error);
        $this->assertSame('folder_1', $applicant->fresh()->folder_category);

        $this->app->forgetInstance(FolderCategoryService::class);

        $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.folder_recalculation.needs_retry', true);

        $this->postJson('/api/v1/settings/folder-recalculations')
            ->assertOk()
            ->assertJsonPath('data.folder_recalculation.state', 'completed')
            ->assertJsonPath('data.folder_recalculation.applicants_changed', 1);

        $this->assertSame('folder_3', $applicant->fresh()->folder_category);
        $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.folder_recalculation.needs_retry', false);
    }

    public function test_a_run_cut_off_part_way_shows_as_interrupted(): void
    {
        FolderRecalculation::create([
            'trigger' => 'manual',
            'status' => 'running',
            'applicants_checked' => 250,
            'started_at' => now()->subMinutes(FolderRecalculation::INTERRUPTED_AFTER_MINUTES + 5),
        ]);

        Sanctum::actingAs($this->hrUser());

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.folder_recalculation.state', 'interrupted')
            ->assertJsonPath('data.folder_recalculation.needs_retry', true);
    }

    public function test_only_an_administrator_can_start_a_run(): void
    {
        Sanctum::actingAs($this->hrUser());

        $this->postJson('/api/v1/settings/folder-recalculations')->assertForbidden();
        $this->assertSame(0, FolderRecalculation::count());
    }

    public function test_the_daily_expiry_check_brings_folders_up_to_date(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->deployable($hr);

        ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $this->type('drug_test')->id)
            ->update(['expiry_date' => now()->subDays(2)->toDateString()]);

        // Stored as it was before the clearance lapsed.
        $this->assertSame('folder_1', $applicant->fresh()->folder_category);

        $this->artisan('empower:check-expiring-documents')->assertSuccessful();

        $applicant->refresh();
        $this->assertSame('folder_2', $applicant->folder_category);
        $this->assertSame('ready_for_deployment', $applicant->current_status);
        $this->assertSame('document_expiry', FolderRecalculation::sole()->trigger);
    }

    public function test_the_command_repairs_folders_left_stale_before_the_fix(): void
    {
        $hr = $this->hrUser();
        $stale = $this->applicant($hr, ['current_status' => 'initial_screening']);
        $stale->forceFill(['folder_category' => 'folder_1'])->save();

        $this->artisan('empower:recalculate-folders')
            ->expectsOutputToContain('1 folder(s) changed')
            ->assertSuccessful();

        $this->assertSame('folder_3', $stale->fresh()->folder_category);
        $this->assertSame('initial_screening', $stale->fresh()->current_status);
    }

    /**
     * A folder is written from what is stored, not from a copy loaded before
     * somebody else changed it - which is what lets the bulk run and a
     * document verification interleave safely.
     */
    public function test_a_folder_write_compares_against_the_stored_value(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);
        $stale = Applicant::find($applicant->id);
        $this->assertSame('folder_3', $stale->folder_category);

        Applicant::whereKey($applicant->id)->update(['folder_category' => 'folder_1']);

        $this->assertSame('folder_3', app(FolderCategoryService::class)->recalculate($stale));
        $this->assertSame('folder_3', $applicant->fresh()->folder_category);
    }
}
