<?php

namespace App\Services\Matching;

use App\Models\Applicant;
use App\Models\JobRequest;
use App\Models\JobRequestMatch;
use App\Models\RequestCriteria;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The rule-based competency scoring engine.
 *
 * This is deliberately not machine learning. Every point awarded traces back to
 * a weight an HR officer entered and a value recorded on the applicant, so a
 * ranking can be explained line by line and defended to a client company or a
 * rejected applicant. The engine ranks and recommends; it never hires. Creating
 * a deployment stays an explicit, separate act by a human.
 */
class CompetencyScoringService
{
    /**
     * Education levels in ascending order. Attainment is ordinal, so a request
     * asking for a high school graduate should also accept a college graduate -
     * plain equality would wrongly reject the stronger candidate.
     */
    private const EDUCATION_RANK = [
        'elementary' => 1,
        'high_school' => 2,
        'senior_high_school' => 3,
        'vocational' => 4,
        'college_undergraduate' => 5,
        'college_graduate' => 6,
        'postgraduate' => 7,
    ];

    /**
     * The values a criterion's "expected" field will actually accept.
     *
     * Published so the criteria form can offer them rather than asking an
     * officer to guess the spelling. A value the scorer does not recognise is
     * not a validation error - it simply never matches - so the form quietly
     * producing "College Graduate" where the engine looks for
     * "college_graduate" would leave a criterion that scores nobody and says
     * nothing about why.
     *
     * `null` means the criterion takes free text: skills and certifications are
     * whatever this client happens to require, and no fixed list could hold
     * them.
     */
    public static function acceptedValues(string $criteriaCode): ?array
    {
        return match ($criteriaCode) {
            'education' => array_keys(self::EDUCATION_RANK),
            'gender' => ['any', 'male', 'female'],
            default => null,
        };
    }

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * Evaluate the eligible applicant pool against a request, persist a ranked
     * snapshot, and return the results.
     *
     * @return ApplicantScore[]
     */
    public function evaluate(JobRequest $jobRequest, User $actor, array $options = []): array
    {
        $criteria = $jobRequest->criteria()->with('criterion')->get();

        if ($criteria->isEmpty()) {
            throw new RuntimeException('This request has no competency criteria configured yet.');
        }

        if ($criteria->filter($this->isWeighted(...))->sum('weight_score') <= 0) {
            throw new RuntimeException('At least one weighted criterion with a score above zero is required.');
        }

        $applicants = $this->candidatePool($jobRequest, $options);

        $scores = $applicants->map(fn (Applicant $a) => $this->score($a, $criteria, $jobRequest))->all();

        $this->rank($scores);
        $this->persist($jobRequest, $scores, $actor);

        $this->audit->record(
            action: 'evaluate',
            module: 'matching',
            recordType: JobRequest::class,
            recordId: $jobRequest->id,
            newValues: [
                'evaluated_candidates' => count($scores),
                'qualified' => count(array_filter($scores, fn ($s) => $s->passedAllGates)),
            ],
        );

        return $scores;
    }

    /**
     * Applicants far enough along the lifecycle to be worth evaluating, and not
     * already deployed against this same request.
     */
    private function candidatePool(JobRequest $jobRequest, array $options): Collection
    {
        $query = Applicant::query()
            ->evaluable()
            ->with(['educations', 'experiences', 'skills', 'certifications'])
            ->whereDoesntHave('employee.deployments', function ($q) use ($jobRequest) {
                $q->where('job_request_id', $jobRequest->id);
            });

        if (! empty($options['candidate_scope'])) {
            $query->where('current_status', $options['candidate_scope']);
        }

        if (! empty($options['limit'])) {
            $query->limit((int) $options['limit']);
        }

        return $query->get();
    }

    /**
     * Score a single applicant against the request's criteria.
     */
    public function score(Applicant $applicant, Collection $criteria, ?JobRequest $jobRequest = null): ApplicantScore
    {
        $outcomes = [];
        $raw = 0.0;
        $max = 0.0;
        $failedGates = [];

        foreach ($criteria as $requestCriterion) {
            $outcome = $this->evaluateCriterion($applicant, $requestCriterion, $jobRequest);
            $outcomes[] = $outcome;

            if ($outcome->isGate) {
                if (! $outcome->passed) {
                    $failedGates[] = $outcome->criteriaName;
                }

                // Gates are eligibility rules, not merit, so they contribute to
                // neither the numerator nor the denominator.
                continue;
            }

            $raw += $outcome->awarded;
            $max += $outcome->weight;
        }

        return new ApplicantScore(
            applicant: $applicant,
            outcomes: $outcomes,
            rawScore: $raw,
            maxScore: $max,
            passedAllGates: $failedGates === [],
            failedGates: $failedGates,
        );
    }

    private function evaluateCriterion(
        Applicant $applicant,
        RequestCriteria $requestCriterion,
        ?JobRequest $jobRequest
    ): CriterionOutcome {
        $criterion = $requestCriterion->criterion;
        $code = $criterion->criteria_code;
        $weight = (float) $requestCriterion->weight_score;
        $isGate = $requestCriterion->mandatory_flag || $criterion->criteria_type === 'hard_filter';

        $value = $this->extractValue($applicant, $code, $jobRequest);

        if ($isGate) {
            [$passed, $explanation] = $this->measureGate($value, $requestCriterion, $code);

            return new CriterionOutcome(
                criteriaCode: $code,
                criteriaName: $criterion->criteria_name,
                isGate: true,
                passed: $passed,
                weight: 0.0,
                awarded: 0.0,
                applicantValue: $value,
                explanation: ($passed ? 'Requirement met. ' : 'Requirement not met. ').$explanation,
            );
        }

        [$multiplier, $explanation] = $this->measure($value, $requestCriterion, $criterion->score_direction, $code);

        return new CriterionOutcome(
            criteriaCode: $code,
            criteriaName: $criterion->criteria_name,
            isGate: false,
            passed: $multiplier > 0.0,
            weight: $weight,
            awarded: $weight * $multiplier,
            applicantValue: $value,
            explanation: $explanation,
        );
    }

    /**
     * Decide whether an applicant clears a mandatory gate.
     *
     * A gate is a containment test, not a graded one. Scoring it on a scale
     * would mean an applicant sitting exactly on a boundary - aged 18 against an
     * 18-to-35 bracket - scored zero and was disqualified despite meeting the
     * client's requirement precisely.
     *
     * @return array{0: bool, 1: string}
     */
    private function measureGate(mixed $value, RequestCriteria $rc, string $code): array
    {
        if ($value === null || $value === [] || $value === '') {
            return [false, 'No information recorded, so this requirement cannot be confirmed.'];
        }

        $min = is_null($rc->min_value) ? null : (float) $rc->min_value;
        $max = is_null($rc->max_value) ? null : (float) $rc->max_value;

        if (! is_null($min) || ! is_null($max)) {
            $numeric = (float) $this->numeric($value);
            $withinLower = is_null($min) || $numeric >= $min;
            $withinUpper = is_null($max) || $numeric <= $max;

            return [
                $withinLower && $withinUpper,
                sprintf(
                    '%s%s against a required %s.',
                    $this->trim($numeric),
                    $this->unitFor($code),
                    $this->describeBounds($min, $max)
                ),
            ];
        }

        if (filled($rc->expected_value)) {
            [$multiplier, $explanation] = $this->applyExpected($value, $rc->expected_value, $code);

            return [$multiplier > 0.0, $explanation];
        }

        $present = is_array($value) ? count($value) > 0 : (bool) $value;

        return [$present, $present ? 'Recorded.' : 'Not recorded.'];
    }

    private function describeBounds(?float $min, ?float $max): string
    {
        return match (true) {
            ! is_null($min) && ! is_null($max) => sprintf('range of %s to %s', $this->trim($min), $this->trim($max)),
            ! is_null($min) => sprintf('minimum of %s', $this->trim($min)),
            default => sprintf('maximum of %s', $this->trim($max)),
        };
    }

    /**
     * Turn an applicant's value into a 0..1 multiplier plus a plain-language
     * reason.
     *
     * @return array{0: float, 1: string}
     */
    private function measure(
        mixed $value,
        RequestCriteria $rc,
        string $direction,
        string $code
    ): array {
        if ($value === null || $value === [] || $value === '') {
            return [0.0, 'No information recorded for this criterion.'];
        }

        // A rubric is the most explicit form: HR states exactly what each
        // response is worth, so it is checked first.
        if (filled($rc->rubric_json)) {
            return $this->applyRubric($value, $rc->rubric_json);
        }

        if (! is_null($rc->min_value) || ! is_null($rc->max_value)) {
            return $this->applyScale((float) $this->numeric($value), $rc, $direction, $code);
        }

        if (filled($rc->expected_value)) {
            return $this->applyExpected($value, $rc->expected_value, $code);
        }

        // No parameters configured: treat the criterion as "has the applicant
        // got this at all".
        $present = is_array($value) ? count($value) > 0 : (bool) $value;

        return [$present ? 1.0 : 0.0, $present ? 'Recorded.' : 'Not recorded.'];
    }

    private function applyRubric(mixed $value, array $rubric): array
    {
        $key = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        $normalised = strtolower(str_replace([' ', '-'], '_', $key));

        foreach ($rubric as $rubricKey => $multiplier) {
            if (strtolower(str_replace([' ', '-'], '_', (string) $rubricKey)) === $normalised) {
                $m = max(0.0, min(1.0, (float) $multiplier));

                return [$m, sprintf('Rated "%s", worth %d%% of this criterion.', $key, (int) round($m * 100))];
            }
        }

        // A numeric rating such as 4 out of 5 can be graded against the rubric's
        // own scale even when it is not one of the listed keys.
        if (is_numeric($key)) {
            $ceiling = max(array_map('floatval', array_keys($rubric))) ?: 1.0;
            $m = max(0.0, min(1.0, (float) $key / $ceiling));

            return [$m, sprintf('Rated %s, worth %d%% of this criterion.', $key, (int) round($m * 100))];
        }

        return [0.0, sprintf('Recorded value "%s" is not in the scoring rubric.', $key)];
    }

    private function applyScale(float $value, RequestCriteria $rc, string $direction, string $code = ''): array
    {
        $min = is_null($rc->min_value) ? null : (float) $rc->min_value;
        $max = is_null($rc->max_value) ? null : (float) $rc->max_value;
        $lowerIsBetter = $direction === 'lower_better';
        $unit = $this->unitFor($code);

        if (! is_null($min) && ! is_null($max) && $max != $min) {
            $ratio = ($value - $min) / ($max - $min);
            $ratio = max(0.0, min(1.0, $ratio));
            $multiplier = $lowerIsBetter ? 1.0 - $ratio : $ratio;

            return [
                $multiplier,
                sprintf(
                    '%s%s against a range of %s to %s%s, scoring %d%%.',
                    $this->trim($value),
                    $unit,
                    $this->trim($min),
                    $this->trim($max),
                    $unit,
                    (int) round($multiplier * 100)
                ),
            ];
        }

        // Only one bound given, so the criterion is a simple threshold.
        if (! is_null($min)) {
            $met = $lowerIsBetter ? $value <= $min : $value >= $min;

            return [
                $met ? 1.0 : 0.0,
                sprintf(
                    '%s%s against a required %s of %s%s.',
                    $this->trim($value),
                    $unit,
                    $lowerIsBetter ? 'maximum' : 'minimum',
                    $this->trim($min),
                    $unit
                ),
            ];
        }

        // Only an upper bound was given, so it reads as a ceiling in both
        // directions: a maximum age, or a maximum travel distance.
        return [
            $value <= $max ? 1.0 : 0.0,
            sprintf('%s%s against a limit of %s%s.', $this->trim($value), $unit, $this->trim($max), $unit),
        ];
    }

    /**
     * Units make the breakdown readable. "18 months against a range of 0 to 36
     * months" tells HR something; a bare "18" does not.
     */
    private function unitFor(string $code): string
    {
        return match ($code) {
            'experience' => ' months',
            'availability' => ' days',
            'distance' => ' km',
            'height' => ' cm',
            'age' => ' years old',
            default => '',
        };
    }

    private function applyExpected(mixed $value, string $expected, string $code): array
    {
        // Education is ranked, not matched: asking for a high school graduate
        // must also accept anyone above that level.
        if ($code === 'education') {
            $applicantRank = self::EDUCATION_RANK[$this->slug((string) $value)] ?? 0;
            $requiredRank = self::EDUCATION_RANK[$this->slug($expected)] ?? 0;

            if ($applicantRank === 0) {
                return [0.0, 'Educational attainment not recognised.'];
            }

            $met = $applicantRank >= $requiredRank;

            return [
                $met ? 1.0 : 0.0,
                sprintf(
                    '%s recorded against a required %s.',
                    $this->humanise((string) $value),
                    $this->humanise($expected)
                ),
            ];
        }

        $expectedValues = array_map(
            fn ($e) => $this->slug(trim((string) $e)),
            explode(',', $expected)
        );

        // Skills and certifications are lists: holding any one of the accepted
        // certificates satisfies the criterion.
        if (is_array($value)) {
            // Matching is done on slugs so spacing and case do not matter, but
            // the message quotes the applicant's own wording. Re-humanising a
            // slug would turn "NC II" into "Nc Ii" on screen.
            $hits = array_values(array_filter(
                $value,
                fn ($held) => in_array($this->slug((string) $held), $expectedValues, true)
            ));

            $expectedLabels = array_map('trim', explode(',', $expected));

            return [
                $hits !== [] ? 1.0 : 0.0,
                $hits !== []
                    ? 'Holds '.implode(', ', $hits).'.'
                    : 'Does not hold '.implode(' or ', $expectedLabels).'.',
            ];
        }

        // "any" is how a request records no gender preference.
        if (in_array('any', $expectedValues, true)) {
            return [1.0, 'No preference set for this criterion.'];
        }

        $met = in_array($this->slug((string) $value), $expectedValues, true);

        return [
            $met ? 1.0 : 0.0,
            sprintf(
                '%s recorded against an expected %s.',
                $this->humanise((string) $value),
                implode(' or ', array_map($this->humanise(...), $expectedValues))
            ),
        ];
    }

    /**
     * Pull the applicant attribute a criterion refers to.
     */
    private function extractValue(Applicant $applicant, string $code, ?JobRequest $jobRequest): mixed
    {
        return match ($code) {
            'education' => $applicant->highestEducationLevel(),
            'experience' => $applicant->totalMonthsExperience(),
            'skills' => $applicant->skills->pluck('skill_name')->all(),
            'certifications' => $applicant->certifications
                ->filter(fn ($c) => $c->isValid())
                ->pluck('certification_name')
                ->values()
                ->all(),
            'availability' => $this->availabilityInDays($applicant),
            'distance' => $applicant->distance_km,
            'height' => $applicant->height_cm,
            'gender', 'sex' => $applicant->sex,
            'age' => $applicant->age,
            'communication' => $applicant->communication_rating,
            'reliability' => $applicant->reliability_rating,
            default => $applicant->getAttribute($code),
        };
    }

    /**
     * Days until the applicant can start. Someone available today scores 0,
     * which pairs with a lower_better direction so immediate availability wins.
     */
    private function availabilityInDays(Applicant $applicant): ?int
    {
        if (is_null($applicant->availability_date)) {
            return null;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($applicant->availability_date, false));
    }

    /**
     * Assign ranks. Ties break toward the earlier application date so that a
     * candidate who has been waiting longer is not passed over, matching how the
     * agency handles the physical queue.
     */
    private function rank(array &$scores): void
    {
        usort($scores, function (ApplicantScore $a, ApplicantScore $b) {
            if ($a->passedAllGates !== $b->passedAllGates) {
                return $a->passedAllGates ? -1 : 1;
            }

            $byScore = $b->percentage() <=> $a->percentage();
            if ($byScore !== 0) {
                return $byScore;
            }

            return $a->applicant->application_date <=> $b->applicant->application_date;
        });

        foreach ($scores as $index => $score) {
            $score->rank = $index + 1;
        }
    }

    /**
     * @param ApplicantScore[] $scores
     */
    private function persist(JobRequest $jobRequest, array $scores, User $actor): void
    {
        DB::transaction(function () use ($jobRequest, $scores, $actor) {
            foreach ($scores as $score) {
                JobRequestMatch::updateOrCreate(
                    [
                        'job_request_id' => $jobRequest->id,
                        'applicant_id' => $score->applicant->id,
                    ],
                    [
                        'raw_score' => round($score->rawScore, 2),
                        'max_score' => round($score->maxScore, 2),
                        'percentage_score' => $score->percentage(),
                        'rank_order' => $score->rank,
                        'recommendation_level' => $score->recommendationLevel(),
                        'hard_filter_pass' => $score->passedAllGates,
                        'breakdown_json' => $score->breakdown(),
                        'evaluated_by' => $actor->id,
                        'evaluated_at' => now(),
                    ]
                );
            }
        });
    }

    private function isWeighted(RequestCriteria $rc): bool
    {
        return ! $rc->mandatory_flag && $rc->criterion?->criteria_type !== 'hard_filter';
    }

    private function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function slug(string $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($value)));
    }

    private function humanise(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
