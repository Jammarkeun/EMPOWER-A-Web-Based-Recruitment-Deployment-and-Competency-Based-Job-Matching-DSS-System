<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\AuditLog;
use App\Models\Endorsement;
use App\Models\JobRequest;
use App\Models\PlacementDecision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * An applicant's answer belongs to one placement (audit R-05, R-07).
 *
 * A decline used to be stored once on the applicant, so it blocked every
 * later deployment and nothing could clear it. Each answer is now kept against
 * the endorsement it answers, a new endorsement asks again, and the office can
 * correct an answer - with a reason, as a new record beside the original.
 */
class PlacementDecisionTest extends TestCase
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

    private function request(User $hr, array $overrides = []): JobRequest
    {
        return $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr, $overrides);
    }

    private function moveTo(User $hr, Applicant $applicant, string $status, ?JobRequest $request = null): void
    {
        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", array_filter([
            'to_status' => $status,
            'reason' => 'Recorded in test.',
            'job_request_id' => $request?->id,
        ]))->assertOk();
    }

    private function answer(Applicant $applicant, string $response, ?string $note = null): void
    {
        $user = User::where('applicant_id', $applicant->id)->first() ?? tap(User::factory()->create([
            'email' => 'portal'.$applicant->id.'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
            'password' => Hash::make('password'),
        ]))->assignRole('portal');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/portal/placement-response', array_filter(['response' => $response, 'note' => $note]))
            ->assertOk();

        $this->app['auth']->forgetGuards();
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

    public function test_each_answer_belongs_to_the_endorsement_it_answers(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr);
        $applicant = $this->trainedApplicant($hr);

        $this->moveTo($hr, $applicant, 'client_evaluation', $request);

        $endorsement = Endorsement::where('applicant_id', $applicant->id)->sole();
        $this->assertSame($request->id, (int) $endorsement->job_request_id);
        $this->assertSame($hr->id, (int) $endorsement->endorsed_by);
        $this->assertNotNull($endorsement->status_history_id);
        $this->assertNull($endorsement->ended_at);
        $this->assertFalse((bool) $endorsement->recorded_retroactively);

        $this->answer($applicant, 'declined', 'The shift ends too late for the last jeepney.');

        $decision = PlacementDecision::where('applicant_id', $applicant->id)->sole();
        $this->assertSame($endorsement->id, (int) $decision->endorsement_id);
        $this->assertSame('applicant', $decision->source);

        Sanctum::actingAs($hr);
        $this->getJson("/api/v1/applicants/{$applicant->id}")
            ->assertOk()
            ->assertJsonPath('data.placement.current_decision.decision', 'declined')
            ->assertJsonPath('data.placement.endorsement.job_request.id', $request->id)
            ->assertJsonPath('data.placement.decision_due', false);
    }

    public function test_the_portal_names_the_placement_being_answered(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr, ['position_title' => 'Packing Line Operator']);
        $applicant = $this->trainedApplicant($hr);
        $this->moveTo($hr, $applicant, 'client_evaluation', $request);

        $user = tap(User::factory()->create([
            'email' => 'portal'.$applicant->id.'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]))->assignRole('portal');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.application.placement_decision_due', true)
            ->assertJsonPath('data.application.placement_position', 'Packing Line Operator')
            ->assertJsonPath('data.application.placement_company', $request->company->company_name);
    }

    public function test_a_decline_blocks_only_the_placement_it_answers(): void
    {
        $hr = $this->hrUser();
        $declined = $this->request($hr);
        $other = $this->request($hr);
        $applicant = $this->trainedApplicant($hr);

        $this->moveTo($hr, $applicant, 'client_evaluation', $declined);
        $this->moveTo($hr, $applicant, 'approved');
        $this->answer($applicant, 'declined');

        $refused = $this->deploy($hr, $applicant, $declined)->assertStatus(400);
        $this->assertStringContainsString('declined', $refused->json('message'));

        $this->deploy($hr, $applicant, $other)->assertCreated();

        $endorsement = Endorsement::where('applicant_id', $applicant->id)->sole();
        $this->assertSame('deployed', $endorsement->end_reason);
        $this->assertNotNull($endorsement->deployment_id);
    }

    public function test_a_new_endorsement_asks_again_and_the_earlier_answer_is_kept(): void
    {
        $hr = $this->hrUser();
        $first = $this->request($hr);
        $second = $this->request($hr);
        $applicant = $this->trainedApplicant($hr);

        $this->moveTo($hr, $applicant, 'client_evaluation', $first);
        $this->answer($applicant, 'declined');

        // Back to the pool: that endorsement is over.
        $this->moveTo($hr, $applicant, 'ready_for_deployment');
        $this->assertSame('returned', Endorsement::where('job_request_id', $first->id)->sole()->end_reason);

        $this->moveTo($hr, $applicant, 'client_evaluation', $second);
        $this->assertSame(2, Endorsement::where('applicant_id', $applicant->id)->count());

        $this->answer($applicant, 'accepted');
        $this->moveTo($hr, $applicant, 'approved');
        $this->deploy($hr, $applicant, $second)->assertCreated();

        $answers = PlacementDecision::where('applicant_id', $applicant->id)->orderBy('id')->get();
        $this->assertSame(['declined', 'accepted'], $answers->pluck('decision')->all());
        $this->assertNotSame($answers[0]->endorsement_id, $answers[1]->endorsement_id);
    }

    public function test_a_correction_needs_a_reason_and_keeps_the_original_answer(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr);
        $applicant = $this->trainedApplicant($hr);

        $this->moveTo($hr, $applicant, 'client_evaluation', $request);
        $this->moveTo($hr, $applicant, 'approved');
        $this->answer($applicant, 'declined');
        $original = PlacementDecision::where('applicant_id', $applicant->id)->sole();

        Sanctum::actingAs($hr);
        $url = "/api/v1/applicants/{$applicant->id}/placement-decisions";

        $this->postJson($url, ['decision' => 'accepted'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('correction_reason');

        $this->postJson($url, [
            'decision' => 'accepted',
            'correction_reason' => 'Called the office on 16 October to say the transport is now sorted.',
        ])->assertOk()->assertJsonPath('data.current_decision.decision', 'accepted');

        $correction = PlacementDecision::where('applicant_id', $applicant->id)->latest('id')->first();
        $this->assertSame($original->id, (int) $correction->corrects_decision_id);
        $this->assertSame('staff', $correction->source);
        $this->assertSame($hr->id, (int) $correction->recorded_by);
        $this->assertSame('declined', $original->fresh()->decision, 'The original answer is never rewritten.');

        $this->assertTrue(AuditLog::where('module_key', 'placement')->where('action_type', 'correction')
            ->where('record_id', $correction->id)->exists());

        // Repeating the answer already on record is not a correction.
        $this->postJson($url, ['decision' => 'accepted', 'correction_reason' => 'Again.'])->assertStatus(422);

        // The corrected answer is the one that counts.
        $this->deploy($hr, $applicant, $request)->assertCreated();
    }

    public function test_only_staff_who_can_change_status_may_correct_an_answer(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->trainedApplicant($hr);
        $this->moveTo($hr, $applicant, 'client_evaluation', $this->request($hr));
        $this->answer($applicant, 'declined');

        $coordinator = tap(User::factory()->create(['email' => 'coord.'.uniqid().'@cde.test', 'user_type' => 'coordinator']))
            ->assignRole('coordinator');
        Sanctum::actingAs($coordinator);

        $this->postJson("/api/v1/applicants/{$applicant->id}/placement-decisions", [
            'decision' => 'accepted',
            'correction_reason' => 'Not theirs to record.',
        ])->assertForbidden();

        $this->assertSame(1, PlacementDecision::where('applicant_id', $applicant->id)->count());
    }

    public function test_an_endorsement_can_only_name_an_open_request(): void
    {
        $hr = $this->hrUser();
        $closed = $this->request($hr, ['request_status' => 'closed']);
        $open = $this->request($hr);
        $applicant = $this->trainedApplicant($hr);

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", [
            'to_status' => 'client_evaluation',
            'job_request_id' => $closed->id,
        ])->assertStatus(422)->assertJsonValidationErrors('job_request_id');

        // Only an endorsement names a request.
        $this->patchJson("/api/v1/applicants/{$applicant->id}/status", [
            'to_status' => 'archived',
            'reason' => 'Test.',
            'job_request_id' => $open->id,
        ])->assertStatus(422)->assertJsonValidationErrors('job_request_id');

        $this->assertSame('ready_for_deployment', $applicant->fresh()->current_status);
        $this->assertSame(0, Endorsement::count());
    }
}
