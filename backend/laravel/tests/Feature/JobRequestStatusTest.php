<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\AuditLog;
use App\Models\JobRequest;
use App\Models\JobRequestStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * A request's status changes only as the transition map allows, with a reason
 * where one is owed, and every change is kept (audit R-16) - whether a person
 * made it, a deployment caused it, or a new headcount did.
 */
class JobRequestStatusTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function request(User $hr, array $overrides = []): JobRequest
    {
        return $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr, $overrides);
    }

    private function change(JobRequest $request, string $status, ?string $reason = null)
    {
        return $this->patchJson("/api/v1/job-requests/{$request->id}/status", array_filter([
            'request_status' => $status,
            'reason' => $reason,
        ]));
    }

    private function trainedApplicant(User $hr): Applicant
    {
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->verifyRequirements($applicant, $hr, 'all');
        $this->completeTraining($applicant, $hr);

        return $applicant;
    }

    public function test_an_allowed_change_is_kept_with_who_made_it_and_from_where(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr);
        Sanctum::actingAs($hr);

        $this->change($request, 'in_progress')
            ->assertOk()
            ->assertJsonPath('data.request_status', 'in_progress')
            ->assertJsonPath('data.status_history.0.to_status', 'in_progress');

        $row = JobRequestStatusHistory::where('job_request_id', $request->id)->sole();
        $this->assertSame('open', $row->from_status);
        $this->assertSame('manual', $row->source);
        $this->assertSame($hr->id, (int) $row->changed_by);
        $this->assertTrue(AuditLog::where('module_key', 'job_requests')->where('record_id', $request->id)->exists());
    }

    public function test_a_move_the_map_does_not_allow_is_refused(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr, ['request_status' => 'closed']);
        Sanctum::actingAs($hr);

        $this->change($request, 'in_progress', 'Trying anyway.')->assertStatus(409);

        $this->assertSame('closed', $request->fresh()->request_status);
        $this->assertSame(0, JobRequestStatusHistory::count());
    }

    public function test_closing_and_cancelling_need_a_reason(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        foreach (['closed', 'cancelled'] as $status) {
            $request = $this->request($hr);

            $this->change($request, $status)->assertStatus(422)->assertJsonValidationErrors('reason');
            $this->assertSame('open', $request->fresh()->request_status);

            $this->change($request, $status, 'Client postponed the hiring.')->assertOk();
            $this->assertSame('Client postponed the hiring.', JobRequestStatusHistory::where('job_request_id', $request->id)->sole()->reason);
        }
    }

    public function test_reopening_lands_on_the_status_the_headcount_supports(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $partly = $this->request($hr, ['request_status' => 'closed', 'workers_needed' => 3]);
        $partly->forceFill(['workers_fulfilled' => 1])->save();

        $this->change($partly, 'open')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->change($partly, 'open', 'Client asked for the remaining two after all.')->assertOk();
        $this->assertSame('partially_fulfilled', $partly->fresh()->request_status);

        $full = $this->request($hr, ['request_status' => 'closed', 'workers_needed' => 2]);
        $full->forceFill(['workers_fulfilled' => 2])->save();

        $this->change($full, 'open', 'Reopen.')->assertStatus(409);
        $this->assertSame('closed', $full->fresh()->request_status);
    }

    public function test_a_headcount_status_must_agree_with_the_headcount(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr, ['workers_needed' => 5]);
        Sanctum::actingAs($hr);

        $this->change($request, 'fulfilled')->assertStatus(409);
        $this->change($request, 'partially_fulfilled')->assertStatus(409);

        $this->assertSame('open', $request->fresh()->request_status);
    }

    public function test_a_deployment_moves_the_status_and_says_so(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr, ['workers_needed' => 1]);
        $applicant = $this->trainedApplicant($hr);

        Sanctum::actingAs($hr);
        $response = $this->postJson('/api/v1/deployments', [
            'applicant_id' => $applicant->id,
            'job_request_id' => $request->id,
            'deployment_date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('fulfilled', $request->fresh()->request_status);

        $row = JobRequestStatusHistory::where('job_request_id', $request->id)->sole();
        $this->assertSame('deployment', $row->source);
        $this->assertStringContainsString($response->json('data.deployment_code'), $row->reason);
    }

    public function test_a_new_headcount_moves_the_status_with_it(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr, ['workers_needed' => 2]);
        $applicant = $this->trainedApplicant($hr);

        Sanctum::actingAs($hr);
        $this->postJson('/api/v1/deployments', [
            'applicant_id' => $applicant->id,
            'job_request_id' => $request->id,
            'deployment_date' => now()->toDateString(),
        ])->assertCreated();
        $this->assertSame('partially_fulfilled', $request->fresh()->request_status);

        $this->patchJson("/api/v1/job-requests/{$request->id}", ['workers_needed' => 1])->assertOk();

        $this->assertSame('fulfilled', $request->fresh()->request_status);
        $this->assertSame('headcount', JobRequestStatusHistory::where('job_request_id', $request->id)->latest('id')->first()->source);
    }

    public function test_the_general_edit_cannot_set_a_status(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr);
        Sanctum::actingAs($hr);

        $this->patchJson("/api/v1/job-requests/{$request->id}", ['request_status' => 'fulfilled', 'remarks' => 'Edit.'])->assertOk();

        $this->assertSame('open', $request->fresh()->request_status);
    }

    public function test_the_screen_is_offered_only_the_changes_the_server_allows(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr);
        Sanctum::actingAs($hr);

        $options = collect($this->getJson("/api/v1/job-requests/{$request->id}")->assertOk()->json('data.status_changes'))
            ->keyBy('status');

        // Headcount statuses are the system's to set.
        $this->assertEqualsCanonicalizing(['in_progress', 'closed', 'cancelled'], $options->keys()->all());
        $this->assertFalse($options['in_progress']['requires_reason']);
        $this->assertTrue($options['closed']['requires_reason']);
    }

    public function test_a_coordinator_cannot_change_a_request_status(): void
    {
        $hr = $this->hrUser();
        $request = $this->request($hr);

        $coordinator = tap(User::factory()->create(['email' => 'coord.'.uniqid().'@cde.test', 'user_type' => 'coordinator']))
            ->assignRole('coordinator');
        Sanctum::actingAs($coordinator);

        $this->change($request, 'cancelled', 'Not mine to cancel.')->assertForbidden();
        $this->assertSame('open', $request->fresh()->request_status);
    }
}
