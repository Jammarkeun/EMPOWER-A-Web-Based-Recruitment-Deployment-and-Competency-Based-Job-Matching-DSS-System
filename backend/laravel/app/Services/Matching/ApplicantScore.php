<?php

namespace App\Services\Matching;

use App\Models\Applicant;

/**
 * An applicant's complete evaluation against one manpower request.
 */
final class ApplicantScore
{
    /** @var CriterionOutcome[] */
    public readonly array $outcomes;

    public int $rank = 0;

    public function __construct(
        public readonly Applicant $applicant,
        array $outcomes,
        public readonly float $rawScore,
        public readonly float $maxScore,
        public readonly bool $passedAllGates,
        /** @var string[] Names of the gates this applicant failed. */
        public readonly array $failedGates,
    ) {
        $this->outcomes = $outcomes;
    }

    /**
     * The overall match: the competency score, scaled by the share of
     * mandatory requirements met.
     *
     * Gates carry no points, so on its own the competency score ignores them,
     * and an applicant who failed the height requirement but matched every
     * scored criterion used to show 100% beside "Not recommended". Scaling by
     * the gates met keeps an applicant who meets every requirement at their
     * full competency score, puts anyone who fails one below it, and still
     * ranks a near miss above someone who failed them all.
     */
    public function percentage(): float
    {
        [$met, $total] = $this->gateCounts();

        $share = $total === 0 ? 1.0 : $met / $total;

        return round($this->competencyRatio() * $share * 100, 2);
    }

    /**
     * Points awarded over points available on the scored criteria alone.
     * Gates are excluded from the denominator because they are eligibility
     * rules rather than measures of merit.
     */
    public function competencyPercentage(): float
    {
        return round($this->competencyRatio() * 100, 2);
    }

    private function competencyRatio(): float
    {
        return $this->maxScore <= 0.0 ? 0.0 : $this->rawScore / $this->maxScore;
    }

    /**
     * @return array{0: int, 1: int} Gates met, and gates on the request.
     */
    private function gateCounts(): array
    {
        $gates = array_filter($this->outcomes, fn (CriterionOutcome $o) => $o->isGate);
        $met = count(array_filter($gates, fn (CriterionOutcome $o) => $o->passed));

        return [$met, count($gates)];
    }

    /**
     * Failing a gate overrides the score outright. A candidate who is 20 years
     * under the client's required age is not a "reserve pool" option no matter
     * how well they score elsewhere.
     */
    public function recommendationLevel(): string
    {
        if (! $this->passedAllGates) {
            return 'not_recommended';
        }

        $bands = config('empower.recommendation_bands');
        $percentage = $this->percentage();

        return match (true) {
            $percentage >= $bands['highly_recommended'] => 'highly_recommended',
            $percentage >= $bands['recommended'] => 'recommended',
            $percentage >= $bands['reserve_pool'] => 'reserve_pool',
            default => 'not_recommended',
        };
    }

    public function breakdown(): array
    {
        [$gatesMet, $gatesTotal] = $this->gateCounts();

        return [
            'criteria' => array_map(fn (CriterionOutcome $o) => $o->toArray(), $this->outcomes),
            'failed_gates' => $this->failedGates,
            'raw_score' => round($this->rawScore, 2),
            'max_score' => round($this->maxScore, 2),
            'competency_percentage' => $this->competencyPercentage(),
            'gates_met' => $gatesMet,
            'gates_total' => $gatesTotal,
            'percentage' => $this->percentage(),
            'summary' => $this->summary(),
        ];
    }

    /**
     * A one-line justification HR can read aloud or paste into an endorsement.
     */
    public function summary(): string
    {
        if (! $this->passedAllGates) {
            [$met, $total] = $this->gateCounts();

            return 'Did not meet the mandatory requirement(s): '.implode(', ', $this->failedGates).'. '
                ."Met {$met} of {$total}, so the {$this->competencyPercentage()}% competency score counts as {$this->percentage()}%.";
        }

        $strengths = [];
        foreach ($this->outcomes as $outcome) {
            if (! $outcome->isGate && $outcome->weight > 0 && $outcome->awarded >= $outcome->weight) {
                $strengths[] = strtolower($outcome->criteriaName);
            }
        }

        $percentage = $this->percentage();

        if ($strengths === []) {
            return "Scored {$percentage}% against the request criteria.";
        }

        return "Scored {$percentage}%, meeting the full weight for ".implode(', ', $strengths).'.';
    }
}
