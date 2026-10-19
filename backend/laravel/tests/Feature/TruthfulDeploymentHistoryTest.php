<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicationStatusHistory;
use App\Models\JobRequest;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;
use App\Notifications\DeploymentRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * A deployment records only what happened (audit R-11).
 *
 * Deploying straight from "ready for deployment" used to walk the applicant
 * through client evaluation and approval on its own, writing history that
 * said the client had been consulted and had approved when nobody had
 * recorded either. Those rows are no longer written; the old ones are kept
 * but labelled; and whether CDE requires an approval first is a setting that
 * stays off until CDE says otherwise.
 */
class TruthfulDeploymentHistoryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function trainedApplicant(User $hr): Applicant
    {
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return $applicant;
    }

    private function request(User $hr): JobRequest
    {
        return $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);
    }

    private function deploy(User $hr, Applicant $applicant, JobRequest $request)
    {
        Sanctum::actingAs($hr);

        return $this->postJson('/api/v1/deployments', [
            'applicant_id' => $applicant->id,
            'job_request_id' => $request->id,
            'deployment_date' => now()->toDateString(),
        ]);
    }

    public function test_deploying_from_ready_for_deployment_invents_no_endorsement_or_approval(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->trainedApplicant($hr);

        $this->deploy($hr, $applicant, $this->request($hr))->assertCreated();

        $history = $applicant->statusHistory()->reorder()->orderBy('id')->get();

        $this->assertFalse($history->contains('to_status', 'client_evaluation'));
        $this->assertFalse($history->contains('to_status', 'approved'));

        $deployed = $history->firstWhere('to_status', 'deployed');
        $this->assertSame('ready_for_deployment', $deployed->from_status);
        $this->assertStringContainsString('no client approval was recorded', $deployed->reason);
        $this->assertSame('active', $history->last()->to_status);
    }

    public function test_an_approved_applicant_is_deployed_from_the_approval_that_was_recorded(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->trainedApplicant($hr);

        Sanctum::actingAs($hr);
        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", ['to_status' => 'client_evaluation'])->assertOk();
        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", ['to_status' => 'approved', 'reason' => 'Client confirmed by phone.'])->assertOk();

        $this->deploy($hr, $applicant, $this->request($hr))->assertCreated();

        $history = $applicant->statusHistory()->reorder()->orderBy('id')->get();
        $this->assertSame(1, $history->where('to_status', 'approved')->count());
        $this->assertSame('approved', $history->firstWhere('to_status', 'deployed')->from_status);
    }

    public function test_requiring_a_recorded_approval_is_a_setting_left_off_by_default(): void
    {
        $hr = $this->hrUser();
        $this->assertFalse(config('empower.require_client_approval_before_deployment'));

        config(['empower.require_client_approval_before_deployment' => true]);

        $ready = $this->trainedApplicant($hr);
        $response = $this->deploy($hr, $ready, $this->request($hr))->assertStatus(400);
        $this->assertStringContainsString('approval must be recorded', $response->json('message'));
        $this->assertSame('ready_for_deployment', $ready->fresh()->current_status);

        $approved = $this->trainedApplicant($hr);
        Sanctum::actingAs($hr);
        $this->patchJson("/api/v1/applicants/{$approved->id}/status", ['to_status' => 'client_evaluation'])->assertOk();
        $this->patchJson("/api/v1/applicants/{$approved->id}/status", ['to_status' => 'approved'])->assertOk();

        $this->deploy($hr, $approved, $this->request($hr))->assertCreated();
    }

    /**
     * Rows the old code wrote are left exactly as they are, but are never
     * shown as a genuine endorsement or approval.
     */
    public function test_rows_the_old_code_wrote_by_itself_are_labelled(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'active']);

        foreach (ApplicationStatusHistory::AUTO_WRITTEN_AT_DEPLOYMENT as $index => $reason) {
            ApplicationStatusHistory::create([
                'applicant_id' => $applicant->id,
                'from_status' => $index === 0 ? 'ready_for_deployment' : 'client_evaluation',
                'to_status' => $index === 0 ? 'client_evaluation' : 'approved',
                'reason' => $reason,
                'changed_by' => $hr->id,
                'changed_at' => now()->subDays(10 - $index),
            ]);
        }

        $genuine = ApplicationStatusHistory::create([
            'applicant_id' => $applicant->id,
            'from_status' => 'client_evaluation',
            'to_status' => 'approved',
            'reason' => 'Client confirmed by phone.',
            'changed_by' => $hr->id,
            'changed_at' => now()->subDays(5),
        ]);

        Sanctum::actingAs($hr);
        $events = collect($this->getJson("/api/v1/applicants/{$applicant->id}/timeline")->assertOk()->json('data'))
            ->where('type', 'status_change');

        $automatic = $events->where('recorded_automatically', true);
        $this->assertCount(2, $automatic);
        foreach ($automatic as $event) {
            $this->assertStringContainsString(ApplicationStatusHistory::AUTO_WRITTEN_NOTE, $event['detail']);
        }

        $this->assertFalse($genuine->wasWrittenAutomaticallyAtDeployment());
        $this->assertSame(1, $events->where('recorded_automatically', false)->where('detail', 'Client confirmed by phone.')->count());

        // Nothing was rewritten.
        $this->assertSame(
            ApplicationStatusHistory::AUTO_WRITTEN_AT_DEPLOYMENT,
            ApplicationStatusHistory::where('applicant_id', $applicant->id)
                ->whereIn('reason', ApplicationStatusHistory::AUTO_WRITTEN_AT_DEPLOYMENT)
                ->orderBy('changed_at')->pluck('reason')->all()
        );
    }

    public function test_the_applicant_hears_about_a_deployment_once(): void
    {
        Notification::fake();

        $hr = $this->hrUser();
        $applicant = $this->trainedApplicant($hr);

        $this->deploy($hr, $applicant, $this->request($hr))->assertCreated();

        Notification::assertSentToTimes($applicant, DeploymentRecorded::class, 1);
        Notification::assertSentToTimes($applicant, ApplicationStatusChanged::class, 1);
    }
}
