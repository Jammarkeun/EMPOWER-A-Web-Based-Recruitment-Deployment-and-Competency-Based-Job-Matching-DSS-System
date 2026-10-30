<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\ApplicantRequirementHistory;
use App\Models\Employee;
use App\Models\RequirementType;
use App\Models\User;
use App\Notifications\DocumentsExpiringSoon;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * A document's expiry date alone does not decide whether somebody is
 * non-compliant: it depends on where they are in their employment (CDE,
 * 26 October 2026; App\Support\DocumentCompliance).
 *
 *  - NBI clearance: must be valid upon employment; once deployed, its lapsing
 *    "no longer matters".
 *  - Annual medical and health card: renewed while employed.
 *  - Anything CDE has not ruled on: counted before deployment as always, and
 *    after deployment shown as "rule not confirmed" - never as a lapse.
 *
 * Synthetic people and placeholder PDFs only.
 */
class DocumentExpiryLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private User $hr;
    private User $admin;
    private RequirementType $nbi;
    private RequirementType $medical;
    private RequirementType $healthCard;
    private RequirementType $police;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));
        $this->seedReferenceData();

        $this->hr = $this->hrUser();
        $this->admin = $this->adminUser();

        // Not in the seeded list; added the way an administrator would, with
        // the rule CDE stated for it.
        $this->nbi = RequirementType::create([
            'requirement_code' => 'nbi_clearance',
            'requirement_name' => 'NBI Clearance',
            'requirement_group' => 'primary',
            'is_required' => true,
            'has_expiry' => true,
            'post_deployment_rule' => 'pre_deployment_only',
            'active_flag' => true,
            'display_order' => 50,
        ]);

        $this->medical = RequirementType::where('requirement_code', 'medical_result')->firstOrFail();
        $this->healthCard = RequirementType::where('requirement_code', 'health_card')->firstOrFail();
        $this->police = RequirementType::where('requirement_code', 'police_clearance')->firstOrFail();
    }

    // ------------------------------------------------------------ fixtures

    /** Every required document verified, with the given expiry dates. */
    private function readyApplicant(array $expiries = [], string $first = 'Test'): Applicant
    {
        $applicant = $this->applicant($this->hr, ['first_name' => $first, 'current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $this->hr, 'all');
        $this->completeTraining($applicant, $this->hr);

        $defaults = [
            'nbi_clearance' => '2026-12-31',
            'medical_result' => '2027-06-30',
            'health_card' => '2027-06-30',
            'police_clearance' => '2027-03-31',
        ];

        foreach (array_merge($defaults, $expiries) as $code => $date) {
            $type = RequirementType::where('requirement_code', $code)->first();
            ApplicantRequirement::where('applicant_id', $applicant->id)
                ->where('requirement_type_id', $type->id)
                ->update(['expiry_date' => $date]);
        }

        $applicant->unsetRelation('requirements');
        app(\App\Services\FolderCategoryService::class)->recalculate($applicant);

        return $applicant->fresh();
    }

    private function deploy(Applicant $applicant): Employee
    {
        $department = $this->department($this->clientCompany($this->hr), $this->hr);
        $request = $this->jobRequest($department, $this->hr, ['workers_needed' => 5]);

        return app(DeploymentService::class)->deploy($applicant, $request, [
            'deployment_date' => now()->toDateString(),
            'supervisor_name' => 'Line Supervisor',
        ], $this->hr)->employee;
    }

    private function requirement(Applicant $applicant, RequirementType $type): ApplicantRequirement
    {
        return ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $type->id)->firstOrFail();
    }

    /** The staff view of one document, as HR's screen receives it. */
    private function staffView(Applicant $applicant, RequirementType $type): array
    {
        Sanctum::actingAs($this->hr);
        $rows = $this->getJson("/api/v1/applicants/{$applicant->id}/requirements")->assertOk()->json('data.requirements');

        return collect($rows)->firstWhere('requirement_type_id', $type->id);
    }

    private function dashboard(): array
    {
        Cache::flush();
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.attention');
    }

    private function portalUser(Applicant $applicant): User
    {
        $user = User::factory()->create([
            'email' => 'portal.'.$applicant->id.'.'.uniqid().'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]);
        $user->assignRole('portal');

        return $user;
    }

    private function pdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return new UploadedFile($path, 'renewal.pdf', null, null, true);
    }

    // ------------------------------------------------------ Scenario 1

    public function test_scenario_1_an_applicant_awaiting_deployment_needs_a_valid_nbi_clearance(): void
    {
        $valid = $this->readyApplicant(['nbi_clearance' => '2026-12-31'], 'Valid');
        $this->assertSame('folder_1', $valid->folder_category);
        $this->assertSame('valid', $this->staffView($valid, $this->nbi)['expiry_assessment']['state']);
        $this->deploy($valid);
        $this->assertTrue(in_array($valid->fresh()->current_status, ['deployed', 'active'], true));

        // Lapsed before placement: not deployable, and counted as needing attention.
        $lapsed = $this->readyApplicant(['nbi_clearance' => '2026-10-15'], 'Lapsed');
        $this->assertNotSame('folder_1', $lapsed->folder_category);
        $view = $this->staffView($lapsed, $this->nbi);
        $this->assertSame('expired', $view['expiry_assessment']['state']);
        $this->assertTrue($view['expiry_assessment']['needs_action']);
        $this->assertFalse($view['counts_as_complete']);
        $this->assertSame(1, $this->dashboard()['expired_documents']);

        try {
            $this->deploy($lapsed);
            $this->fail('An applicant with a lapsed NBI clearance was deployed.');
        } catch (RuntimeException $e) {
            // The existing documentary gate names what is outstanding.
            $this->assertStringContainsString('NBI Clearance', $e->getMessage());
        }

        // Missing altogether: the same.
        $missing = $this->readyApplicant([], 'Missing');
        $this->requirement($missing, $this->nbi)->update(['status' => 'missing', 'verified_at' => null, 'verified_by' => null]);
        app(\App\Services\FolderCategoryService::class)->recalculate($missing->fresh());
        $this->assertNotSame('folder_1', $missing->fresh()->folder_category);
        $this->expectExceptionMessage('NBI Clearance');
        $this->deploy($missing->fresh());
    }

    // ------------------------------------------------------ Scenario 2

    public function test_scenario_2_a_deployed_workers_lapsed_nbi_clearance_does_not_flag_them(): void
    {
        $applicant = $this->readyApplicant(['nbi_clearance' => '2026-12-31']);
        $employee = $this->deploy($applicant);
        $statusAtDeployment = $applicant->fresh()->current_status;
        // From here on: deploying them sent its own notices.
        Notification::fake();

        // Months later the clearance lapses.
        $this->travelTo(Carbon::parse('2027-02-15 09:00:00'));
        $this->artisan('empower:check-expiring-documents')->assertSuccessful();

        $nbi = $this->requirement($applicant, $this->nbi);
        // Its record and date are kept exactly as they were...
        $this->assertSame('verified', $nbi->status);
        $this->assertSame('2026-12-31', $nbi->expiry_date->toDateString());
        $this->assertNotNull($nbi->verified_at);

        // ...it is not presented as a lapse to act on...
        $view = $this->staffView($applicant, $this->nbi);
        $this->assertSame('lapsed_after_deployment', $view['expiry_assessment']['state']);
        $this->assertFalse($view['expiry_assessment']['needs_action']);
        $this->assertTrue($view['counts_as_complete']);
        // Shown as the accepted document it was, not as "Expired".
        $this->assertSame('verified', $view['review_state']);
        $this->assertSame(0, $this->dashboard()['expired_documents']);

        // ...and nothing about the employment moved.
        $this->assertSame($statusAtDeployment, $applicant->fresh()->current_status);
        $this->assertSame('folder_1', $applicant->fresh()->folder_category);
        $this->assertSame('active', $employee->fresh()->employment_status);
        $this->assertSame('active', $employee->deployments()->first()->deployment_status);
        Notification::assertNotSentTo($this->hr, DocumentsExpiringSoon::class);
    }

    // ------------------------------------------------------ Scenario 3

    public function test_scenario_3_the_annual_medical_is_tracked_and_renewed_while_employed_and_its_history_kept(): void
    {
        Storage::fake('documents');
        Notification::fake();
        $applicant = $this->readyApplicant(['medical_result' => '2026-11-20']);
        $employee = $this->deploy($applicant);

        // Within the warning window: due, and the worker may renew it online.
        $this->assertSame('renewal_due', $this->staffView($applicant, $this->medical)['expiry_assessment']['state']);

        // Lapsed: the daily check marks it, HR is told, and it stays on file.
        $this->travelTo(Carbon::parse('2026-11-25 09:00:00'));
        $this->artisan('empower:check-expiring-documents')->assertSuccessful();

        $medical = $this->requirement($applicant, $this->medical);
        $this->assertSame('expired', $medical->status);
        $this->assertSame('2026-11-20', $medical->expiry_date->toDateString());
        $view = $this->staffView($applicant, $this->medical);
        $this->assertSame('renewal_overdue', $view['expiry_assessment']['state']);
        $this->assertTrue($view['expiry_assessment']['needs_action']);
        $this->assertSame(1, $this->dashboard()['employee_renewals_overdue']);
        $this->assertSame(0, $this->dashboard()['expired_documents']);
        Notification::assertSentTo($this->hr, DocumentsExpiringSoon::class,
            fn (DocumentsExpiringSoon $n) => str_contains($n->toArray($this->hr)['body'], '1 have already lapsed'));

        // No validity period is invented and no employment consequence follows.
        $this->assertSame('active', $employee->fresh()->employment_status);
        $this->assertTrue(in_array($applicant->fresh()->current_status, ['deployed', 'active'], true));

        // The worker sends the renewed medical through the portal.
        $oldFile = 'requirements/'.$applicant->id.'/medical-2025.pdf';
        Storage::disk('documents')->put($oldFile, 'old');
        $medical->forceFill(['file_path' => $oldFile, 'file_name' => 'medical-2025.pdf'])->saveQuietly();

        Sanctum::actingAs($this->portalUser($applicant));
        $this->post("/api/v1/portal/documents/{$this->medical->id}/upload", [
            'file' => $this->pdf(),
            'expiry_date' => '2027-11-20',
        ], ['Accept' => 'application/json'])->assertOk();

        // The lapsed version is in the history, file and all.
        $history = ApplicantRequirementHistory::where('applicant_requirement_id', $medical->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame('2026-11-20', $history[0]->expiry_date->toDateString());
        $this->assertSame('renewal', $history[0]->reason);
        $this->assertSame($this->hr->id, $history[0]->verified_by);
        Storage::disk('documents')->assertExists($oldFile);

        // HR verifies the new one; the old one is still there to read.
        Sanctum::actingAs($this->hr);
        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$this->medical->id}", [
            'status' => 'verified',
            'expiry_date' => '2027-11-20',
        ])->assertOk();

        $view = $this->staffView($applicant, $this->medical);
        $this->assertSame('valid', $view['expiry_assessment']['state']);
        $this->assertSame('2026-11-20', $view['history'][0]['expiry_date']);
        $this->assertSame(0, $this->dashboard()['employee_renewals_overdue']);
    }

    // ------------------------------------------------------ Scenario 4

    public function test_scenario_4_a_lapsed_health_card_stays_visible_to_hr_after_deployment(): void
    {
        $applicant = $this->readyApplicant(['health_card' => '2026-11-10']);
        $this->deploy($applicant);

        $this->travelTo(Carbon::parse('2026-12-01 09:00:00'));
        $this->artisan('empower:check-expiring-documents')->assertSuccessful();

        $view = $this->staffView($applicant, $this->healthCard);
        $this->assertNotNull($view, 'The lapsed health card disappeared from the record.');
        $this->assertSame('2026-11-10', $view['expiry_date']);
        $this->assertSame('renewal_overdue', $view['expiry_assessment']['state']);
        $this->assertSame('Renewed while employed', $view['expiry_assessment']['rule_label']);
        $this->assertSame(1, $this->dashboard()['employee_renewals_overdue']);

        // A renewal verified at the counter keeps the old card in the history.
        Sanctum::actingAs($this->hr);
        $this->patchJson("/api/v1/applicants/{$applicant->id}/requirements/{$this->healthCard->id}", [
            'status' => 'verified',
            'expiry_date' => '2027-11-10',
            'verification_method' => 'walk_in',
        ])->assertOk();

        $view = $this->staffView($applicant, $this->healthCard);
        $this->assertSame('valid', $view['expiry_assessment']['state']);
        $this->assertCount(1, $view['history']);
        $this->assertSame('2026-11-10', $view['history'][0]['expiry_date']);
        $this->assertSame('expiry_corrected', $view['history'][0]['reason']);
    }

    // ------------------------------------------------------ Scenario 5

    public function test_scenario_5_an_unconfirmed_rule_is_not_mistaken_for_non_compliance(): void
    {
        $this->assertNull($this->police->post_deployment_rule, 'The police clearance must not be given an invented rule.');

        $worker = $this->readyApplicant(['police_clearance' => '2026-12-31'], 'Worker');
        $this->deploy($worker);
        $waiting = $this->readyApplicant(['police_clearance' => '2027-03-31'], 'Waiting');

        $this->travelTo(Carbon::parse('2027-04-15 09:00:00'));
        $this->artisan('empower:check-expiring-documents')->assertSuccessful();

        // The deployed worker: lapsed, rule unconfirmed, not acted on.
        $view = $this->staffView($worker, $this->police);
        $this->assertSame('lapsed_rule_unconfirmed', $view['expiry_assessment']['state']);
        $this->assertFalse($view['expiry_assessment']['needs_action']);
        $this->assertSame('After deployment: not yet confirmed by CDE', $view['expiry_assessment']['rule_label']);
        $this->assertSame('verified', $this->requirement($worker, $this->police)->status);
        $this->assertSame('folder_1', $worker->fresh()->folder_category);

        // The applicant still waiting: a genuine lapse, counted and marked.
        $view = $this->staffView($waiting, $this->police);
        $this->assertSame('expired', $view['expiry_assessment']['state']);
        $this->assertTrue($view['expiry_assessment']['needs_action']);
        $this->assertSame('expired', $this->requirement($waiting, $this->police)->status);
        $this->assertNotSame('folder_1', $waiting->fresh()->folder_category);

        $attention = $this->dashboard();
        // Waiting's police clearance and NBI both lapsed; the worker's count for nothing.
        $this->assertSame(2, $attention['expired_documents']);
        $this->assertSame(0, $attention['employee_renewals_overdue']);
    }

    // ------------------------------------------------------ Scenario 6

    public function test_scenario_6_a_requirement_change_in_settings_recalculates_only_who_it_affects(): void
    {
        $worker = $this->readyApplicant(['police_clearance' => '2026-11-15'], 'Worker');
        $employee = $this->deploy($worker);
        $applicantStatus = $worker->fresh()->current_status;

        $waiting = $this->readyApplicant(['police_clearance' => '2026-11-15'], 'Waiting');
        $unrelated = $this->readyApplicant([], 'Unrelated');

        $this->travelTo(Carbon::parse('2026-11-20 09:00:00'));
        $this->artisan('empower:check-expiring-documents')->assertSuccessful();
        // A primary document lapsed, so the waiting applicant is back in Folder 3.
        $this->assertSame('folder_3', $waiting->fresh()->folder_category);
        $this->assertSame('folder_1', $unrelated->fresh()->folder_category);

        // The administrator stops requiring the police clearance.
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/settings/requirement-types/{$this->police->id}", ['is_required' => false])
            ->assertOk()
            ->assertJsonPath('data.folder_recalculation.state', 'completed');

        $this->assertSame('folder_1', $waiting->fresh()->folder_category);
        $this->assertSame('folder_1', $unrelated->fresh()->folder_category);
        $this->assertSame('folder_1', $worker->fresh()->folder_category);
        // Folders only: nobody's place in the pipeline or employment moved.
        $this->assertSame('ready_for_deployment', $waiting->fresh()->current_status);
        $this->assertSame($applicantStatus, $worker->fresh()->current_status);
        $this->assertSame('active', $employee->fresh()->employment_status);

        // Giving a type a post-deployment rule is a rule change too.
        $this->patchJson("/api/v1/settings/requirement-types/{$this->police->id}", [
            'is_required' => true,
            'post_deployment_rule' => 'renew_while_employed',
        ])->assertOk()->assertJsonPath('data.folder_recalculation.state', 'completed');
        $this->assertSame('folder_3', $worker->fresh()->folder_category);
        $this->assertSame('active', $employee->fresh()->employment_status);

        $this->patchJson("/api/v1/settings/requirement-types/{$this->police->id}", ['post_deployment_rule' => 'not_a_rule'])
            ->assertStatus(422);

        // The dashboard agrees with the records it summarises.
        $attention = $this->dashboard();
        $lapsedWaiting = ApplicantRequirement::whereHas('applicant', fn ($q) => $q->where('current_status', 'ready_for_deployment'))
            ->where('status', 'expired')->count();
        $this->assertSame($lapsedWaiting, $attention['expired_documents']);
        $this->assertSame(1, $attention['employee_renewals_overdue']);
    }
}
