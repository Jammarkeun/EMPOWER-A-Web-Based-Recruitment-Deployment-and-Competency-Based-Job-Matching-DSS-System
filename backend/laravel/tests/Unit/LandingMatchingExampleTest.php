<?php

namespace Tests\Unit;

use App\Models\Applicant;
use App\Models\RequestCriteria;
use App\Services\Matching\ApplicantScore;
use App\Services\Matching\CompetencyScoringService;
use App\Services\Matching\CriterionOutcome;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The landing page's worked example is what the engine really produces
 * (audit FN-06).
 *
 * The page used to show 85 % "Highly recommended" over rows that add up to
 * 79.2 %. Its figures are now computed in frontend/src/lib/matchingExample.js;
 * this test runs the same inputs through the real scorer and pins the numbers
 * that file's own test expects, so the two cannot drift apart.
 */
class LandingMatchingExampleTest extends TestCase
{
    private function scale(float $value, float $min, float $max, string $direction, string $code): array
    {
        $criterion = new RequestCriteria(['min_value' => $min, 'max_value' => $max]);
        $method = new ReflectionMethod(CompetencyScoringService::class, 'applyScale');

        return $method->invoke(app(CompetencyScoringService::class), $value, $criterion, $direction, $code);
    }

    public function test_the_example_rows_and_total_are_the_engines_own(): void
    {
        [$experience, $experienceNote] = $this->scale(18, 0, 36, 'higher_better', 'experience');
        [$distance, $distanceNote] = $this->scale(3.4, 0, 40, 'lower_better', 'distance');

        $this->assertSame('18 months against a range of 0 to 36 months, scoring 50%.', $experienceNote);
        $this->assertSame('3.4 km against a range of 0 to 40 km, scoring 92%.', $distanceNote);

        $outcomes = [
            new CriterionOutcome('education', 'Educational attainment', false, true, 15, 15, 'college_graduate', ''),
            new CriterionOutcome('experience', 'Relevant experience', false, true, 20, 20 * $experience, 18, $experienceNote),
            new CriterionOutcome('certifications', 'Certifications', false, true, 10, 10, 'Food Safety NC II', ''),
            new CriterionOutcome('distance', 'Distance from worksite', false, true, 5, 5 * $distance, 3.4, $distanceNote),
        ];

        $score = new ApplicantScore(new Applicant(), $outcomes, array_sum(array_map(fn ($o) => $o->awarded, $outcomes)), 50, true, []);
        $breakdown = $score->breakdown();

        $this->assertSame(4.58, $breakdown['criteria'][3]['score']);
        $this->assertSame(39.58, $breakdown['raw_score']);
        $this->assertSame(79.15, $score->percentage());
        $this->assertSame('recommended', $score->recommendationLevel());
    }

    public function test_the_example_states_the_bands_the_system_ships_with(): void
    {
        // matchingExample.js prints these as "the default bands".
        $this->assertSame(85, config('empower.recommendation_bands.highly_recommended'));
        $this->assertSame(70, config('empower.recommendation_bands.recommended'));
        $this->assertSame(50, config('empower.recommendation_bands.reserve_pool'));
    }
}
