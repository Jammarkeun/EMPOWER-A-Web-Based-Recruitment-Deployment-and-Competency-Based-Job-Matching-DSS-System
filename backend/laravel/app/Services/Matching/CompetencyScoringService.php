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
    /**
     * Education levels and their order, lowest first.
     *
     * The single copy: the applicant's "highest attainment" reads it too, so
     * the level shown on a record and the level the scorer compares can never
     * be ranked differently (audit CQ-02).
     *
     * @return array<string, int>
     */
    public static function educationRanks(): array
    {
        return self::EDUCATION_RANK;
    }

    public static function acceptedValues(string $criteriaCode): ?array
    {
        return match ($criteriaCode) {
            'education' => array_keys(self::EDUCATION_RANK),
            'gender' => ['any', 'male', 'female'],
            default => null,
        };
    }

    /**
     * How a list criterion - skills, certifications - is satisfied.
     *
     * Two genuinely different questions, and the catalogue's own type says
     * which one a criterion asks:
     *
     * - `graded` (a `weighted_scale` criterion): every item listed is
     *   required, and the applicant earns the share they hold. A client asking
     *   for communication, customer service, teamwork, and computer literacy
     *   is asking for all four, so holding three earns three quarters of the
     *   points - not all of them, which is what treating the list as
     *   alternatives used to award. As a mandatory gate, all must be held.
     * - `any` (a `weighted_binary` criterion): the items are accepted
     *   alternatives, and holding one is enough. "TESDA NC II or NC III" means
     *   either certificate will do.
     *
     * Kept on the catalogue rather than hard-coded per criterion, so an
     * administrator can change how a criterion is read from the settings
     * screen instead of asking a developer.
     */
    public static function listMode(?string $criteriaType): string
    {
        return $criteriaType === 'weighted_scale' ? 'graded' : 'any';
    }

    /**
     * Reads a typed education requirement as one of the ranked levels.
     *
     * The request form takes education as free text, so "High School
     * Graduate", "HS grad" and "high_school" all arrive. Recognised wording is
     * mapped to the ranked level; anything else returns null so the caller can
     * say it was not understood, rather than guessing a level for the client.
     */
    public static function normaliseEducation(string $text): ?string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($text)), '_');

        if (isset(self::EDUCATION_RANK[$slug])) {
            return $slug;
        }

        return match (true) {
            str_contains($slug, 'post') || str_contains($slug, 'master') || str_contains($slug, 'doctor') => 'postgraduate',
            str_contains($slug, 'senior_high') || $slug === 'shs' || str_starts_with($slug, 'shs_') => 'senior_high_school',
            str_contains($slug, 'vocational') || str_contains($slug, 'tesda') => 'vocational',
            str_contains($slug, 'college') && (str_contains($slug, 'under') || str_contains($slug, 'level')) => 'college_undergraduate',
            str_contains($slug, 'college') || str_contains($slug, 'bachelor') || str_contains($slug, 'degree') => 'college_graduate',
            str_contains($slug, 'high_school') || str_starts_with($slug, 'hs_') || $slug === 'hs' => 'high_school',
            str_contains($slug, 'elementary') || str_contains($slug, 'grade_school') => 'elementary',
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
        /*
         * One evaluation of a request at a time, and never while its criteria
         * are being saved (audit S-01, S-02). The request's row stays locked
         * for the whole run, and saving criteria takes the same lock. Without
         * it, two runs could interleave and leave ranks from both, and a save
         * landing mid-run could leave results scored against the old criteria
         * looking current.
         */
        return DB::transaction(function () use ($jobRequest, $actor, $options) {
            $request = JobRequest::query()->whereKey($jobRequest->getKey())->lockForUpdate()->firstOrFail();

            $criteria = $request->criteria()->with('criterion')->get();

            if ($criteria->isEmpty()) {
                throw new RuntimeException('This request has no competency criteria configured yet.');
            }

            if ($criteria->filter($this->isWeighted(...))->sum('weight_score') <= 0) {
                throw new RuntimeException('At least one weighted criterion with a score above zero is required.');
            }

            $wholePool = $this->isWholePool($options);
            $applicants = $this->candidatePool($request, $options);

            $scores = $applicants->map(fn (Applicant $a) => $this->score($a, $criteria, $request))->all();

            $this->persist($request, $scores, $actor, $wholePool);

            $this->audit->record(
                action: 'evaluate',
                module: 'matching',
                recordType: JobRequest::class,
                recordId: $request->id,
                newValues: [
                    'evaluated_candidates' => count($scores),
                    'qualified' => count(array_filter($scores, fn ($s) => $s->passedAllGates)),
                    'whole_pool' => $wholePool,
                    'candidate_scope' => $options['candidate_scope'] ?? null,
                    'limit' => $options['limit'] ?? null,
                ],
            );

            return $scores;
        });
    }

    /**
     * The applicants this run scores: the request's pool, optionally narrowed
     * to one status or to the first N.
     *
     * The first N are taken in the agency's queue order - longest waiting
     * first, then the earlier record - so running the same narrowed
     * evaluation twice scores the same people (audit S-01). It used to be
     * whichever rows the database happened to return first.
     */
    private function candidatePool(JobRequest $jobRequest, array $options): Collection
    {
        $query = $jobRequest->candidatePool()
            ->with(['educations', 'experiences', 'skills', 'certifications'])
            ->orderBy('application_date')
            ->orderBy('id');

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
        $listMode = self::listMode($criterion->criteria_type);

        if ($isGate) {
            [$passed, $explanation, $details] = $this->measureGate($value, $requestCriterion, $code, $listMode)
                + [2 => null];

            return new CriterionOutcome(
                criteriaCode: $code,
                criteriaName: $criterion->criteria_name,
                isGate: true,
                passed: $passed,
                weight: 0.0,
                awarded: 0.0,
                applicantValue: $value,
                explanation: ($passed ? 'Requirement met. ' : 'Requirement not met. ').$explanation,
                details: $details,
            );
        }

        [$multiplier, $explanation, $details] = $this->measure(
            $value,
            $requestCriterion,
            $criterion->score_direction,
            $code,
            $listMode
        ) + [2 => null];

        return new CriterionOutcome(
            criteriaCode: $code,
            criteriaName: $criterion->criteria_name,
            isGate: false,
            passed: $multiplier > 0.0,
            weight: $weight,
            awarded: $weight * $multiplier,
            applicantValue: $value,
            explanation: $explanation,
            details: $details,
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
    private function measureGate(mixed $value, RequestCriteria $rc, string $code, string $listMode = 'any'): array
    {
        // A list the client requires is checked item by item, so an applicant
        // with no skills recorded at all is told which ones are missing rather
        // than only that nothing was recorded.
        if (filled($rc->expected_value) && is_array($value)) {
            [$multiplier, $explanation, $details] = $this->applyList($value, $rc->expected_value, $listMode);

            // Graded lists are all required when they are a gate; holding some
            // of them is a score, not eligibility.
            $passed = $listMode === 'graded' ? $multiplier >= 1.0 : $multiplier > 0.0;

            return [$passed, $explanation, $details];
        }

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
        string $code,
        string $listMode = 'any',
    ): array {
        // Lists are compared against what the client asked for even when the
        // applicant has recorded nothing, so the breakdown can still name each
        // requirement they are missing.
        if (filled($rc->expected_value) && is_array($value)) {
            return $this->applyList($value, $rc->expected_value, $listMode);
        }

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

        // Skills and certifications are lists, compared item by item.
        if (is_array($value)) {
            return $this->applyList($value, $expected, 'any');
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
     * Compare what an applicant holds with what the client listed.
     *
     * Every required item is reported as held or missing, so the breakdown
     * reads "3 of 4 required: Communication, Customer Service, Teamwork held;
     * Computer Literacy missing" instead of a bare score. Matching is on slugs
     * so case and spacing do not matter, but the message quotes the client's
     * and the applicant's own wording: re-humanising a slug would turn "NC II"
     * into "Nc Ii" on screen.
     *
     * @param  string[]  $held
     * @return array{0: float, 1: string, 2: array}
     */
    private function applyList(array $held, string $expected, string $mode): array
    {
        $required = array_values(array_filter(array_map('trim', explode(',', $expected)), 'strlen'));
        $heldBySlug = [];

        foreach ($held as $item) {
            $heldBySlug[$this->slug((string) $item)] ??= (string) $item;
        }

        $matched = [];
        $missing = [];

        foreach ($required as $item) {
            if (isset($heldBySlug[$this->slug($item)])) {
                $matched[] = $item;
            } else {
                $missing[] = $item;
            }
        }

        $details = [
            'mode' => $mode,
            'required' => $required,
            'matched' => $matched,
            'missing' => $missing,
        ];

        if ($required === []) {
            return [0.0, 'Nothing is listed to compare against.', $details];
        }

        if ($mode === 'any') {
            return [
                $matched !== [] ? 1.0 : 0.0,
                $matched !== []
                    ? 'Holds '.implode(', ', array_map(fn ($m) => $heldBySlug[$this->slug($m)], $matched)).'.'
                    : 'Does not hold '.implode(' or ', $required).'.',
                $details,
            ];
        }

        $multiplier = count($matched) / count($required);

        $explanation = sprintf('Holds %d of %d required', count($matched), count($required));
        $explanation .= $matched !== [] ? ': '.implode(', ', $matched) : '';
        $explanation .= $missing !== [] ? '. Missing: '.implode(', ', $missing) : '';
        $explanation .= sprintf('. Scoring %d%%.', (int) round($multiplier * 100));

        return [$multiplier, $explanation, $details];
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
     * The order results are ranked in: everyone who clears every gate first,
     * then by score, then whoever has been waiting longer - how the agency
     * handles its physical queue - and finally the earlier record, so equal
     * results always come out in the same order on every database (audit
     * S-01). Ranks are never shared: a tie is broken, not repeated.
     *
     * @param  array{applicant_id: int, passed: bool, percentage: float, application_date: string}  $a
     * @param  array{applicant_id: int, passed: bool, percentage: float, application_date: string}  $b
     */
    private static function rankOrder(array $a, array $b): int
    {
        if ($a['passed'] !== $b['passed']) {
            return $a['passed'] ? -1 : 1;
        }

        if ($a['percentage'] !== $b['percentage']) {
            return $b['percentage'] <=> $a['percentage'];
        }

        if ($a['application_date'] !== $b['application_date']) {
            return strcmp($a['application_date'], $b['application_date']);
        }

        return $a['applicant_id'] <=> $b['applicant_id'];
    }

    /**
     * Whether this run looked at everybody eligible, rather than a slice.
     */
    private function isWholePool(array $options): bool
    {
        return empty($options['candidate_scope']) && empty($options['limit']);
    }

    /**
     * Saves the run and ranks every current result for the request together.
     *
     * A narrowed run - one status, or the first N - used to rank its slice
     * 1, 2, 3 and write those ranks beside results from earlier runs that
     * still held theirs, so a list could show two applicants at number 1
     * (audit S-01). Now the run's scores and the results it did not touch are
     * ordered as one list and every rank is assigned from it. Results carried
     * over keep their scores; evaluationState() says the ranking is
     * provisional until a run covers the whole pool.
     *
     * Runs inside evaluate()'s transaction, under the request's lock.
     *
     * @param  ApplicantScore[]  $scores
     */
    private function persist(JobRequest $jobRequest, array &$scores, User $actor, bool $wholePool): void
    {
        $evaluatedAt = now();
        $evaluated = array_map(fn (ApplicantScore $s) => $s->applicant->id, $scores);

        // Numbered, so a result can always be told apart from one carried over
        // from an earlier run - timestamps kept to the second cannot.
        $run = (int) $jobRequest->evaluation_runs + 1;
        JobRequest::query()->whereKey($jobRequest->id)->update(['evaluation_runs' => $run]);
        $jobRequest->evaluation_runs = $run;

        /*
         * Results for people no longer in the pool are not left ranked.
         *
         * Someone archived, deployed elsewhere, or moved back to incomplete
         * documents since the last run used to keep their old row and their
         * old rank. After a run over the whole pool their unshortlisted
         * results are removed. A shortlisted result is kept, because the
         * shortlist is a decision somebody made and its record should
         * survive, but it loses its rank: it is history now, not a current
         * recommendation.
         *
         * A narrowed run has not scored anyone else, so it removes nothing;
         * but whether somebody is still in the pool is a plain fact, and a
         * result whose applicant has left it loses its rank there too.
         */
        if ($wholePool) {
            $departed = JobRequestMatch::where('job_request_id', $jobRequest->id)
                ->when($evaluated !== [], fn ($q) => $q->whereNotIn('applicant_id', $evaluated));

            (clone $departed)->where('is_shortlisted', false)->delete();
            (clone $departed)->where('is_shortlisted', true)->update(['rank_order' => null]);

            $carried = collect();
        } else {
            $untouched = JobRequestMatch::query()
                ->join('applicants', 'applicants.id', '=', 'job_request_matches.applicant_id')
                ->where('job_request_matches.job_request_id', $jobRequest->id)
                ->whereNotNull('job_request_matches.rank_order')
                ->when($evaluated !== [], fn ($q) => $q->whereNotIn('job_request_matches.applicant_id', $evaluated))
                ->get([
                    'job_request_matches.id',
                    'job_request_matches.applicant_id',
                    'job_request_matches.rank_order',
                    'job_request_matches.hard_filter_pass',
                    'job_request_matches.percentage_score',
                    'applicants.application_date as queued_on',
                ]);

            $stillInPool = $untouched->isEmpty()
                ? collect()
                : $jobRequest->candidatePool()
                    ->whereIn('applicants.id', $untouched->pluck('applicant_id'))
                    ->pluck('applicants.id')
                    ->map(fn ($id) => (int) $id);

            [$carried, $departed] = $untouched->partition(
                fn ($row) => $stillInPool->contains((int) $row->applicant_id)
            );

            if ($departed->isNotEmpty()) {
                JobRequestMatch::whereIn('id', $departed->pluck('id'))->update(['rank_order' => null]);
            }
        }

        $entries = [];

        foreach ($scores as $score) {
            $entries[] = [
                'applicant_id' => (int) $score->applicant->id,
                'passed' => $score->passedAllGates,
                'percentage' => $score->percentage(),
                'application_date' => (string) $score->applicant->application_date?->toDateString(),
            ];
        }

        foreach ($carried as $row) {
            $entries[] = [
                'applicant_id' => (int) $row->applicant_id,
                'passed' => (bool) $row->hard_filter_pass,
                'percentage' => round((float) $row->percentage_score, 2),
                'application_date' => substr((string) $row->queued_on, 0, 10),
            ];
        }

        usort($entries, self::rankOrder(...));

        $ranks = [];
        foreach ($entries as $index => $entry) {
            $ranks[$entry['applicant_id']] = $index + 1;
        }

        foreach ($scores as $score) {
            $score->rank = $ranks[(int) $score->applicant->id];

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
                    'evaluated_at' => $evaluatedAt,
                    // What this score was computed from, so a later change to
                    // either shows it as out of date (audit S-02).
                    'scored_profile_version' => (int) $score->applicant->profile_version,
                    'scored_criteria_version' => (int) $jobRequest->criteria_version,
                    'evaluation_run' => $run,
                ]
            );
        }

        // Carried-over results move only if the run reordered around them.
        foreach ($carried as $row) {
            $rank = $ranks[(int) $row->applicant_id];

            if ((int) $row->rank_order !== $rank) {
                JobRequestMatch::whereKey($row->id)->update(['rank_order' => $rank]);
            }
        }

        usort($scores, fn (ApplicantScore $a, ApplicantScore $b) => $a->rank <=> $b->rank);
    }

    private function isWeighted(RequestCriteria $rc): bool
    {
        return ! $rc->mandatory_flag && $rc->criterion?->criteria_type !== 'hard_filter';
    }

    private function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Case, spacing, hyphens, and underscores do not distinguish two answers:
     * "Customer  Service", "customer-service" and "customer_service" are the
     * same skill typed three ways.
     */
    private function slug(string $value): string
    {
        return trim(preg_replace('/[\s\-_]+/', '_', strtolower(trim($value))), '_');
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
