<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\CriteriaCatalog;
use App\Models\JobRequest;
use App\Models\JobRequestMatch;
use App\Models\RequestCriteria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * Ranks stay consistent however the evaluation is narrowed (audit S-01), and a
 * result scored against details that have since changed says so (S-02).
 *
 * A run over one status, or the first N applicants, used to rank its slice
 * 1, 2, 3 beside results from earlier runs that kept their own ranks, so a list
 * could show two applicants at number 1. Staleness was judged by comparing
 * timestamps kept to the second, so an edit in the same second as the
 * evaluation - or during it - went unnoticed.
 */
class RankingConsistencyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    /** Nearer is better: 10 km scores 90%, 20 km 80%, and so on. */
    private function requestScoringDistance(User $hr): JobRequest
    {
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        RequestCriteria::create([
            'job_request_id' => $request->id,
            'criteria_id' => CriteriaCatalog::where('criteria_code', 'distance')->value('id'),
            'weight_score' => 100,
            'min_value' => 0,
            'max_value' => 100,
            'created_by' => $hr->id,
        ]);

        return $request->fresh();
    }

    private function candidate(User $hr, float $km, string $status = 'ready_for_deployment', int $daysWaiting = 0): Applicant
    {
        return $this->applicant($hr, [
            'current_status' => $status,
            'distance_km' => $km,
            'application_date' => now()->subDays($daysWaiting)->toDateString(),
        ]);
    }

    private function evaluate(JobRequest $request, array $options = [])
    {
        return $this->postJson("/api/v1/job-requests/{$request->id}/evaluate", $options)->assertOk();
    }

    /** @return array<int, int> applicant id => rank, ranked results only */
    private function ranks(JobRequest $request): array
    {
        return JobRequestMatch::where('job_request_id', $request->id)
            ->whereNotNull('rank_order')
            ->pluck('rank_order', 'applicant_id')
            ->map(fn ($rank) => (int) $rank)
            ->all();
    }

    private function state(JobRequest $request): array
    {
        return $this->getJson("/api/v1/job-requests/{$request->id}")->assertOk()->json('data.evaluation');
    }

    private function rankings(JobRequest $request, string $query = ''): \Illuminate\Support\Collection
    {
        return collect($this->getJson("/api/v1/job-requests/{$request->id}/rankings{$query}")->assertOk()->json('data'))
            ->keyBy('applicant_id');
    }

    public function test_a_narrowed_run_never_leaves_two_applicants_at_the_same_rank(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestScoringDistance($hr);
        $a = $this->candidate($hr, 10);
        $b = $this->candidate($hr, 20);
        $c = $this->candidate($hr, 30, 'training_completed');
        $d = $this->candidate($hr, 5, 'training_completed');

        Sanctum::actingAs($hr);
        $this->evaluate($request);
        $this->assertSame([$d->id => 1, $a->id => 2, $b->id => 3, $c->id => 4], $this->sorted($this->ranks($request)));

        // C moves next door, and only the trained applicants are rescored.
        $this->patchJson("/api/v1/applicants/{$c->id}", ['distance_km' => 1])->assertOk();
        $response = $this->evaluate($request, ['candidate_scope' => 'training_completed']);

        $this->assertSame([$c->id => 1, $d->id => 2, $a->id => 3, $b->id => 4], $this->sorted($this->ranks($request)));
        $this->assertSame([1, 2, 3, 4], collect($response->json('data.ranked'))->pluck('rank_order')->all());

        $state = $this->state($request);
        $this->assertTrue($state['is_provisional']);
        $this->assertSame(2, $state['from_earlier_runs']);
        $this->assertSame(0, $state['not_yet_evaluated']);

        $rows = $this->rankings($request);
        $this->assertTrue($rows[$a->id]['from_earlier_run']);
        $this->assertTrue($rows[$b->id]['from_earlier_run']);
        $this->assertFalse($rows[$c->id]['from_earlier_run']);
        $this->assertFalse($rows[$d->id]['from_earlier_run']);

        // A run over the whole pool settles it.
        $this->evaluate($request);
        $this->assertFalse($this->state($request)['is_provisional']);
    }

    public function test_the_first_n_are_the_longest_waiting_not_whoever_the_database_returns(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestScoringDistance($hr);
        $newest = $this->candidate($hr, 1, 'ready_for_deployment', 0);
        $oldest = $this->candidate($hr, 50, 'ready_for_deployment', 10);
        $middle = $this->candidate($hr, 40, 'ready_for_deployment', 5);

        Sanctum::actingAs($hr);
        $this->evaluate($request, ['limit' => 2]);

        $this->assertEqualsCanonicalizing([$oldest->id, $middle->id], array_keys($this->ranks($request)));

        $state = $this->state($request);
        $this->assertSame(1, $state['not_yet_evaluated']);
        $this->assertTrue($state['is_provisional']);
        $this->assertNull(JobRequestMatch::where('applicant_id', $newest->id)->first());
    }

    public function test_equal_results_come_out_in_queue_order_then_record_order(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestScoringDistance($hr);
        $later = $this->candidate($hr, 20, 'ready_for_deployment', 1);
        $earlier = $this->candidate($hr, 20, 'ready_for_deployment', 3);
        $sameDayFirst = $this->candidate($hr, 30, 'ready_for_deployment', 2);
        $sameDaySecond = $this->candidate($hr, 30, 'ready_for_deployment', 2);

        Sanctum::actingAs($hr);
        $this->evaluate($request);

        $this->assertSame(
            [$earlier->id => 1, $later->id => 2, $sameDayFirst->id => 3, $sameDaySecond->id => 4],
            $this->sorted($this->ranks($request))
        );
    }

    public function test_a_filtered_list_keeps_overall_ranks(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestScoringDistance($hr);
        $this->candidate($hr, 10);
        $second = $this->candidate($hr, 20);
        $this->candidate($hr, 30);

        Sanctum::actingAs($hr);
        $this->evaluate($request);
        $this->postJson("/api/v1/job-requests/{$request->id}/shortlist", ['applicant_ids' => [$second->id]])->assertOk();

        $filtered = $this->rankings($request, '?only_shortlisted=1');
        $this->assertCount(1, $filtered);
        $this->assertSame(2, $filtered[$second->id]['rank_order']);
    }

    public function test_someone_who_left_the_pool_loses_their_rank_even_in_a_narrowed_run(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestScoringDistance($hr);
        $a = $this->candidate($hr, 10);
        $leaving = $this->candidate($hr, 20);
        $c = $this->candidate($hr, 30, 'training_completed');

        Sanctum::actingAs($hr);
        $this->evaluate($request);

        $leaving->forceFill(['current_status' => 'archived'])->save();
        $this->assertSame(1, $this->state($request)['left_pool']);

        $this->evaluate($request, ['candidate_scope' => 'ready_for_deployment']);

        $this->assertSame([$a->id => 1, $c->id => 2], $this->sorted($this->ranks($request)));
        $this->assertNotNull(JobRequestMatch::where('applicant_id', $leaving->id)->first(), 'The result is kept as history.');
    }

    public function test_a_scope_the_pool_cannot_contain_is_refused(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestScoringDistance($hr);
        Sanctum::actingAs($hr);

        $this->postJson("/api/v1/job-requests/{$request->id}/evaluate", ['candidate_scope' => 'archived'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('candidate_scope');
    }

    // ------------------------------------------------------------- staleness

    public function test_editing_a_scored_detail_marks_only_that_result_out_of_date(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestScoringDistance($hr);
        $edited = $this->candidate($hr, 10);
        $untouched = $this->candidate($hr, 20);

        Sanctum::actingAs($hr);
        $this->evaluate($request);

        $this->patchJson("/api/v1/applicants/{$edited->id}", ['distance_km' => 60])->assertOk();
        // Not something the engine scores.
        $this->patchJson("/api/v1/applicants/{$untouched->id}", ['contact_number' => '09170000000'])->assertOk();

        $rows = $this->rankings($request);
        $this->assertTrue($rows[$edited->id]['is_stale']);
        $this->assertSame(['profile_changed'], $rows[$edited->id]['stale_reasons']);
        $this->assertFalse($rows[$untouched->id]['is_stale']);

        $state = $this->state($request);
        $this->assertSame(1, $state['profiles_changed']);
        $this->assertTrue($state['is_stale']);
    }

    public function test_an_edit_in_the_same_second_as_the_evaluation_is_still_caught(): void
    {
        Carbon::setTestNow(now()->startOfSecond());

        $hr = $this->hrUser();
        $request = $this->requestScoringDistance($hr);
        $applicant = $this->candidate($hr, 10);

        Sanctum::actingAs($hr);
        $this->evaluate($request);
        $this->patchJson("/api/v1/applicants/{$applicant->id}", ['distance_km' => 80])->assertOk();

        // Same timestamp on both; the version still differs.
        $this->assertTrue($this->rankings($request)[$applicant->id]['is_stale']);

        Carbon::setTestNow();
    }

    /**
     * An edit that lands after the evaluation read the applicant but before it
     * saved: the result was computed from the old details and must say so.
     * A timestamp stamped when the evaluation finished would have called it
     * current.
     */
    public function test_an_edit_landing_while_the_evaluation_runs_is_caught(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestScoringDistance($hr);
        $applicant = $this->candidate($hr, 10);

        $edited = false;
        Applicant::retrieved(function (Applicant $read) use ($applicant, &$edited) {
            if (! $edited && $read->id === $applicant->id) {
                $edited = true;
                Applicant::whereKey($applicant->id)->update([
                    'distance_km' => 90,
                    'profile_version' => \Illuminate\Support\Facades\DB::raw('profile_version + 1'),
                ]);
            }
        });

        app(\App\Services\Matching\CompetencyScoringService::class)->evaluate($request, $hr);
        Applicant::flushEventListeners();

        $this->assertTrue($edited, 'The simulated edit did not run during the evaluation.');

        Sanctum::actingAs($hr);
        $row = $this->rankings($request)[$applicant->id];
        $this->assertTrue($row['is_stale']);
        $this->assertSame(['profile_changed'], $row['stale_reasons']);
    }

    public function test_an_applicant_changing_their_availability_on_the_portal_marks_their_result_out_of_date(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);
        RequestCriteria::create([
            'job_request_id' => $request->id,
            'criteria_id' => CriteriaCatalog::where('criteria_code', 'availability')->value('id'),
            'weight_score' => 100,
            'min_value' => 0,
            'max_value' => 30,
            'created_by' => $hr->id,
        ]);
        $applicant = $this->applicant($hr, [
            'current_status' => 'ready_for_deployment',
            'availability_date' => now()->addDays(10)->toDateString(),
        ]);

        Sanctum::actingAs($hr);
        $this->evaluate($request);

        $portal = tap(User::factory()->create([
            'email' => 'portal'.$applicant->id.'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]))->assignRole('portal');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($portal);
        $this->patchJson('/api/v1/portal/profile', ['availability_date' => now()->addDays(2)->toDateString()])->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($hr);
        $this->assertTrue($this->rankings($request)[$applicant->id]['is_stale']);
    }

    public function test_new_qualifications_mark_results_out_of_date(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestScoringDistance($hr);
        $applicant = $this->candidate($hr, 10);

        Sanctum::actingAs($hr);
        $this->evaluate($request);

        $this->putJson("/api/v1/applicants/{$applicant->id}/qualifications", [
            'educations' => [],
            'experiences' => [],
            'skills' => ['Forklift operation'],
            'certifications' => [],
        ])->assertOk();

        $this->assertTrue($this->rankings($request)[$applicant->id]['is_stale']);
    }

    public function test_saving_criteria_marks_every_result_out_of_date_until_the_next_run(): void
    {
        $hr = $this->hrUser();
        $request = $this->requestScoringDistance($hr);
        $this->candidate($hr, 10);
        $this->candidate($hr, 20);

        Sanctum::actingAs($hr);
        $this->evaluate($request);

        $this->postJson("/api/v1/job-requests/{$request->id}/criteria", ['criteria' => [
            ['criteria_code' => 'distance', 'weight_score' => 100, 'min_value' => 0, 'max_value' => 50],
        ]])->assertOk();

        $state = $this->state($request);
        $this->assertTrue($state['criteria_changed']);
        $this->assertTrue($this->rankings($request)->every(fn ($row) => in_array('criteria_changed', $row['stale_reasons'], true)));

        $this->evaluate($request);

        $this->assertFalse($this->state($request)['is_stale']);
        $this->assertTrue($this->rankings($request)->every(fn ($row) => $row['is_stale'] === false));
    }

    /** @param array<int, int> $ranks */
    private function sorted(array $ranks): array
    {
        asort($ranks);

        return $ranks;
    }
}
