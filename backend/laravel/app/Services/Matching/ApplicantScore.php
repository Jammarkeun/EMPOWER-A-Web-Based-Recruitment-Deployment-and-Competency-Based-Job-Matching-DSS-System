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
     * Percentage is computed over the weighted criteria only. Gates are excluded
     * from the denominator because they are eligibility rules rather than
     * measures of merit: including them would make an applicant who meets every
     * requirement look like a partial match.
     */
    public function percentage(): float
    {
        if ($this->maxScore <= 0.0) {
            return 0.0;
        }

        return round(($this->rawScore / $this->maxScore) * 100, 2);
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
        return [
            'criteria' => array_map(fn (CriterionOutcome $o) => $o->toArray(), $this->outcomes),
            'failed_gates' => $this->failedGates,
            'raw_score' => round($this->rawScore, 2),
            'max_score' => round($this->maxScore, 2),
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
            return 'Did not meet the mandatory requirement(s): '.implode(', ', $this->failedGates).'.';
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
