<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * The one decision in the recruitment process that belongs to the applicant.
 *
 * From the agency's own account of how it works: after training, the client
 * company assesses the candidate, the agency assesses the candidate - and the
 * candidate assesses the job. Somebody who has now seen the site, the shift, and
 * the journey decides whether they still want it, and people do say no.
 *
 * The system had no way to record that, so an applicant who had turned a
 * placement down was indistinguishable from one still waiting, and the only
 * record of it was whoever took the phone call. These tests cover the answer
 * being recorded, who may give it, and the fact that it changes no status by
 * itself - deciding what a withdrawal means for someone's place in the pool is
 * a judgment the agency makes about a person, not something a form should do
 * to them.
 */
class PlacementResponseTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function portalUserFor($applicant): User
    {
        $user = User::factory()->create([
            'email' => 'portal'.$applicant->id.'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('portal');

        return $user;
    }

    public function test_the_applicant_is_asked_only_once_a_client_is_considering_them(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'client_evaluation']);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.application.placement_decision_due', true)
            ->assertJsonPath('data.application.placement_response', null);
    }

    public function test_there_is_nothing_to_answer_earlier_in_the_process(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'initial_screening']);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->getJson('/api/v1/portal')
            ->assertOk()
            ->assertJsonPath('data.application.placement_decision_due', false);

        $this->postJson('/api/v1/portal/placement-response', ['response' => 'accepted'])
            ->assertStatus(409);
    }

    public function test_an_acceptance_is_recorded_against_the_applicant(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'client_evaluation']);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson('/api/v1/portal/placement-response', ['response' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.placement_response', 'accepted');

        $applicant->refresh();
        $this->assertSame('accepted', $applicant->placement_response);
        $this->assertNotNull($applicant->placement_responded_at);
    }

    /**
     * Accepting is not approval. The client's decision and the agency's are
     * separate acts and both still have to happen.
     */
    public function test_accepting_does_not_move_the_applicant_along(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'client_evaluation']);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson('/api/v1/portal/placement-response', ['response' => 'accepted'])->assertOk();

        $this->assertSame('client_evaluation', $applicant->refresh()->current_status);
    }

    public function test_a_decline_is_recorded_with_the_reason_given(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'approved']);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson('/api/v1/portal/placement-response', [
            'response' => 'declined',
            'note' => 'The travel is too far for the night shift.',
        ])->assertOk();

        $applicant->refresh();
        $this->assertSame('declined', $applicant->placement_response);
        $this->assertSame('The travel is too far for the night shift.', $applicant->placement_response_note);
    }

    /**
     * Declining does not archive anybody either. What a withdrawal means for
     * someone's place in the pool is for HR to decide.
     */
    public function test_declining_does_not_close_the_record(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'approved']);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson('/api/v1/portal/placement-response', ['response' => 'declined'])->assertOk();

        $this->assertSame('approved', $applicant->refresh()->current_status);
    }

    public function test_the_answer_cannot_be_given_twice(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'approved']);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson('/api/v1/portal/placement-response', ['response' => 'accepted'])->assertOk();
        $this->postJson('/api/v1/portal/placement-response', ['response' => 'declined'])->assertStatus(409);

        $this->assertSame('accepted', $applicant->refresh()->placement_response);
    }

    public function test_only_accepted_or_declined_are_understood(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'approved']);

        Sanctum::actingAs($this->portalUserFor($applicant));

        $this->postJson('/api/v1/portal/placement-response', ['response' => 'deployed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('response');
    }

    /**
     * The consequence that gives the answer its weight: a worker who has said no
     * is not sent to a client expecting them.
     */
    public function test_someone_who_declined_cannot_be_deployed(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);
        $request = $this->jobRequest($department, $hr);

        $applicant = $this->applicant($hr, ['current_status' => 'approved']);
        $this->verifyRequirements($applicant, $hr, 'all');

        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->postJson('/api/v1/portal/placement-response', ['response' => 'declined'])->assertOk();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($hr);

        // 400, like every other deployment precondition: the request was
        // well-formed, the business rule refused it.
        $response = $this->postJson('/api/v1/deployments', [
            'applicant_id' => $applicant->id,
            'job_request_id' => $request->id,
            'deployment_date' => now()->toDateString(),
        ])->assertStatus(400);

        $this->assertStringContainsString('declined', $response->json('message'));
    }

    /**
     * And the same applicant deploys normally once they have said yes, so the
     * check is refusing the right thing rather than blocking everybody.
     */
    public function test_someone_who_accepted_deploys_normally(): void
    {
        $hr = $this->hrUser();
        $company = $this->clientCompany($hr);
        $department = $this->department($company, $hr);
        $request = $this->jobRequest($department, $hr);

        $applicant = $this->applicant($hr, ['current_status' => 'approved']);
        $this->verifyRequirements($applicant, $hr, 'all');

        Sanctum::actingAs($this->portalUserFor($applicant));
        $this->postJson('/api/v1/portal/placement-response', ['response' => 'accepted'])->assertOk();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($hr);

        $this->postJson('/api/v1/deployments', [
            'applicant_id' => $applicant->id,
            'job_request_id' => $request->id,
            'deployment_date' => now()->toDateString(),
        ])->assertCreated();
    }

    /**
     * The answer is the applicant's to give. Staff have no route to give it for
     * them, and the field is not writable through the applicant update endpoint.
     */
    public function test_staff_cannot_answer_on_the_applicants_behalf(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr, ['current_status' => 'approved']);

        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/applicants/{$applicant->id}", [
            'placement_response' => 'accepted',
        ])->assertOk();

        $this->assertNull(
            $applicant->refresh()->placement_response,
            'placement_response is not mass assignable, so a staff update cannot set it.'
        );
    }
}
