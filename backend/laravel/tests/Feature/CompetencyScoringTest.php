<?php

namespace Tests\Feature;

use App\Models\ApplicantCertification;
use App\Models\ApplicantEducation;
use App\Models\ApplicantExperience;
use App\Models\CriteriaCatalog;
use App\Models\JobRequestMatch;
use App\Models\RequestCriteria;
use App\Services\Matching\CompetencyScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

class CompetencyScoringTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    /**
     * The bug this guards against: gates were once scored on a sliding scale, so
     * an applicant sitting exactly on the lower bound scored zero and was
     * disqualified despite meeting the client's requirement precisely.
     */
    public function test_applicant_on_the_lower_age_bound_passes_the_gate(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $this->attachCriterion($request->id, 'age', $hr, [
            'mandatory_flag' => true,
            'weight_score' => 0,
            'min_value' => 18,
            'max_value' => 35,
        ]);
        $this->attachCriterion($request->id, 'experience', $hr, [
            'weight_score' => 100,
            'min_value' => 0,
            'max_value' => 36,
        ]);

        $exactlyEighteen = $this->applicant($hr, [
            'birth_date' => now()->subYears(18)->toDateString(),
            'current_status' => 'ready_for_deployment',
        ]);

        $scores = app(CompetencyScoringService::class)->evaluate($request->fresh(), $hr);
        $score = collect($scores)->firstWhere(fn ($s) => $s->applicant->id === $exactlyEighteen->id);

        $this->assertTrue($score->passedAllGates, 'An applicant aged exactly the minimum must clear the age gate.');
        $this->assertSame([], $score->failedGates);
    }

    public function test_applicant_outside_the_age_bracket_is_disqualified(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $this->attachCriterion($request->id, 'age', $hr, [
            'mandatory_flag' => true,
            'min_value' => 18,
            'max_value' => 35,
        ]);
        $this->attachCriterion($request->id, 'experience', $hr, [
            'weight_score' => 100,
            'min_value' => 0,
            'max_value' => 36,
        ]);

        $tooOld = $this->applicant($hr, [
            'birth_date' => now()->subYears(41)->toDateString(),
            'current_status' => 'ready_for_deployment',
        ]);
        ApplicantExperience::create([
            'applicant_id' => $tooOld->id,
            'company_name' => 'Previous Employer',
            'position_title' => 'Operator',
            'months_experience' => 36,
        ]);

        $scores = app(CompetencyScoringService::class)->evaluate($request->fresh(), $hr);
        $score = collect($scores)->firstWhere(fn ($s) => $s->applicant->id === $tooOld->id);

        $this->assertFalse($score->passedAllGates);
        $this->assertSame('not_recommended', $score->recommendationLevel());
        // Even a perfect score on every weighted criterion cannot override a gate.
        $this->assertSame(100.0, $score->percentage());
    }

    /**
     * Education is ordinal. A request asking for a high school graduate must
     * also accept a college graduate, which plain equality would reject.
     */
    public function test_higher_education_satisfies_a_lower_requirement(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $this->attachCriterion($request->id, 'education', $hr, [
            'weight_score' => 100,
            'expected_value' => 'high_school',
        ]);

        $graduate = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        ApplicantEducation::create([
            'applicant_id' => $graduate->id,
            'education_level' => 'college_graduate',
            'school_name' => 'Laguna State Polytechnic University',
        ]);

        $scores = app(CompetencyScoringService::class)->evaluate($request->fresh(), $hr);

        $this->assertSame(100.0, $scores[0]->percentage());
    }

    public function test_lower_education_does_not_satisfy_the_requirement(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $this->attachCriterion($request->id, 'education', $hr, [
            'weight_score' => 100,
            'expected_value' => 'college_graduate',
        ]);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        ApplicantEducation::create([
            'applicant_id' => $applicant->id,
            'education_level' => 'high_school',
            'school_name' => 'Sta. Cruz National High School',
        ]);

        $scores = app(CompetencyScoringService::class)->evaluate($request->fresh(), $hr);

        $this->assertSame(0.0, $scores[0]->percentage());
    }

    /**
     * Distance is graded in reverse: a nearer applicant is the better one.
     */
    public function test_nearer_applicant_outscores_a_distant_one(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $this->attachCriterion($request->id, 'distance', $hr, [
            'weight_score' => 100,
            'min_value' => 0,
            'max_value' => 40,
        ]);

        $near = $this->applicant($hr, [
            'first_name' => 'Near', 'distance_km' => 4, 'current_status' => 'ready_for_deployment',
        ]);
        $far = $this->applicant($hr, [
            'first_name' => 'Far', 'distance_km' => 36, 'current_status' => 'ready_for_deployment',
        ]);

        $scores = app(CompetencyScoringService::class)->evaluate($request->fresh(), $hr);
        $byId = collect($scores)->keyBy(fn ($s) => $s->applicant->id);

        $this->assertSame(90.0, $byId[$near->id]->percentage());
        $this->assertSame(10.0, $byId[$far->id]->percentage());
        $this->assertSame(1, $byId[$near->id]->rank);
    }

    /**
     * An expired certificate proves nothing and must not earn points.
     */
    public function test_expired_certification_earns_no_points(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $this->attachCriterion($request->id, 'certifications', $hr, [
            'weight_score' => 100,
            'expected_value' => 'Food Safety NC II',
        ]);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        ApplicantCertification::create([
            'applicant_id' => $applicant->id,
            'certification_name' => 'Food Safety NC II',
            'issued_at' => now()->subYears(5),
            'expires_at' => now()->subMonth(),
        ]);

        $scores = app(CompetencyScoringService::class)->evaluate($request->fresh(), $hr);

        $this->assertSame(0.0, $scores[0]->percentage());
    }

    public function test_evaluation_persists_an_explainable_snapshot(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);
        $this->attachCriterion($request->id, 'experience', $hr, [
            'weight_score' => 100, 'min_value' => 0, 'max_value' => 36,
        ]);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        ApplicantExperience::create([
            'applicant_id' => $applicant->id,
            'company_name' => 'Golden Harvest Foods',
            'position_title' => 'Operator',
            'months_experience' => 18,
        ]);

        app(CompetencyScoringService::class)->evaluate($request->fresh(), $hr);

        $match = JobRequestMatch::where('job_request_id', $request->id)->first();

        $this->assertNotNull($match);
        $this->assertSame(1, $match->rank_order);
        $this->assertEqualsWithDelta(50.0, (float) $match->percentage_score, 0.01);
        $this->assertNotEmpty($match->breakdown_json['criteria']);
        $this->assertArrayHasKey('explanation', $match->breakdown_json['criteria'][0]);
        $this->assertNotEmpty($match->breakdown_json['summary']);
    }

    /**
     * Evaluation is advisory. It must never create a deployment on its own.
     */
    public function test_evaluation_does_not_deploy_anyone(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);
        $this->attachCriterion($request->id, 'experience', $hr, [
            'weight_score' => 100, 'min_value' => 0, 'max_value' => 12,
        ]);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);

        app(CompetencyScoringService::class)->evaluate($request->fresh(), $hr);

        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('employees', 0);
        $this->assertSame('ready_for_deployment', $applicant->fresh()->current_status);
        $this->assertSame(0, $request->fresh()->workers_fulfilled);
    }

    public function test_request_without_criteria_cannot_be_evaluated(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $this->expectExceptionMessage('no competency criteria');

        app(CompetencyScoringService::class)->evaluate($request, $hr);
    }

    private function attachCriterion(int $requestId, string $code, $actor, array $attributes): RequestCriteria
    {
        $criterion = CriteriaCatalog::where('criteria_code', $code)->firstOrFail();

        return RequestCriteria::create(array_merge([
            'job_request_id' => $requestId,
            'criteria_id' => $criterion->id,
            'weight_score' => 0,
            'created_by' => $actor->id,
        ], $attributes));
    }
}
