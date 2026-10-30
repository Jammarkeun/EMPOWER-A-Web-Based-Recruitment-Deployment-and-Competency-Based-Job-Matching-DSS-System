<?php

namespace Tests\Feature;

use App\Models\CriteriaCatalog;
use App\Models\JobRequest;
use App\Models\RequestCriteria;
use App\Models\User;
use App\Services\Matching\CompetencyScoringService;
use App\Services\Matching\CriterionOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * What the Availability to Start requirement actually does, pinned down so the
 * explanations in the requirements editor can be checked against it.
 *
 * The editor reads the two numbers back as sentences and worked figures
 * ("0 days: 20 points · 15 days: 10 points · 30 days or more: 0 points"), and
 * frontend/src/lib/requirementRange.test.js expects the same figures. If the
 * engine changes, these fail first and the wording has to follow.
 */
class AvailabilityScoringTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    private User $hr;

    private JobRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();

        $this->hr = $this->hrUser();
        $this->request = $this->jobRequest($this->department($this->clientCompany($this->hr), $this->hr), $this->hr);
    }

    private function configure(array $attributes): void
    {
        RequestCriteria::where('job_request_id', $this->request->id)->delete();
        RequestCriteria::create(array_merge([
            'job_request_id' => $this->request->id,
            'criteria_id' => CriteriaCatalog::where('criteria_code', 'availability')->value('id'),
            'weight_score' => 20,
            'created_by' => $this->hr->id,
        ], $attributes));
    }

    /** Scores one applicant who can start in $days days (null: no date recorded). */
    private function outcome(?int $days): CriterionOutcome
    {
        $applicant = $this->applicant($this->hr, [
            'availability_date' => $days === null ? null : now()->addDays($days)->toDateString(),
        ]);

        $score = app(CompetencyScoringService::class)->score(
            $applicant,
            $this->request->criteria()->with('criterion')->get(),
            $this->request
        );

        return $score->outcomes[0];
    }

    public function test_with_both_numbers_points_fall_evenly_from_full_to_none(): void
    {
        $this->configure(['min_value' => 0, 'max_value' => 30]);

        $this->assertEqualsWithDelta(20.0, $this->outcome(0)->awarded, 0.001, 'Immediate start: full points.');
        $this->assertEqualsWithDelta(10.0, $this->outcome(15)->awarded, 0.001, 'Halfway: half the points.');
        $this->assertEqualsWithDelta(0.0, $this->outcome(30)->awarded, 0.001, 'Exactly the upper number: no points.');
        $this->assertEqualsWithDelta(0.0, $this->outcome(45)->awarded, 0.001, 'Beyond it: still no points, never negative.');

        // A scored requirement never decides eligibility, however late.
        $this->assertFalse($this->outcome(45)->isGate);
    }

    public function test_a_start_date_already_passed_counts_as_immediate(): void
    {
        $this->configure(['min_value' => 0, 'max_value' => 30]);

        $this->assertEqualsWithDelta(20.0, $this->outcome(-5)->awarded, 0.001);
    }

    public function test_no_start_date_recorded_earns_nothing(): void
    {
        $this->configure(['min_value' => 0, 'max_value' => 30]);

        $this->assertEqualsWithDelta(0.0, $this->outcome(null)->awarded, 0.001);
    }

    public function test_full_points_up_to_alone_is_all_or_nothing(): void
    {
        $this->configure(['min_value' => 7, 'max_value' => null]);

        $this->assertEqualsWithDelta(20.0, $this->outcome(7)->awarded, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->outcome(8)->awarded, 0.001);
    }

    /**
     * The one inconsistency: with both numbers set, exactly the upper number
     * earns nothing; set alone, it earns everything. Reported, not changed.
     */
    public function test_the_upper_number_alone_still_earns_full_points_at_exactly_that_number(): void
    {
        $this->configure(['min_value' => null, 'max_value' => 30]);

        $this->assertEqualsWithDelta(20.0, $this->outcome(30)->awarded, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->outcome(31)->awarded, 0.001);
    }

    public function test_as_must_have_the_maximum_decides_eligibility_and_adds_no_points(): void
    {
        $this->configure(['mandatory_flag' => true, 'weight_score' => 0, 'min_value' => null, 'max_value' => 14]);

        $onTheLimit = $this->outcome(14);
        $this->assertTrue($onTheLimit->isGate);
        $this->assertTrue($onTheLimit->passed, 'The maximum itself is allowed.');
        $this->assertSame(0.0, $onTheLimit->awarded);

        $this->assertFalse($this->outcome(15)->passed, 'One day over is not eligible.');
        $this->assertFalse($this->outcome(null)->passed, 'No start date recorded is not eligible.');
    }

    public function test_as_must_have_a_minimum_shuts_out_applicants_who_could_start_sooner(): void
    {
        $this->configure(['mandatory_flag' => true, 'weight_score' => 0, 'min_value' => 5, 'max_value' => 14]);

        $this->assertFalse($this->outcome(0)->passed);
        $this->assertTrue($this->outcome(5)->passed);
    }
}
