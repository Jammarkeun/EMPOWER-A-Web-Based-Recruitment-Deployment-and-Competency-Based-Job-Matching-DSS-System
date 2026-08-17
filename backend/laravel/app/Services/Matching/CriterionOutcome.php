<?php

namespace App\Services\Matching;

/**
 * The result of scoring one applicant against one criterion.
 *
 * Every field exists so the outcome can be explained in words to an applicant
 * who was not selected, or to a client company asking why a particular worker
 * was endorsed. A score without an explanation is not decision support.
 */
final class CriterionOutcome
{
    public function __construct(
        public readonly string $criteriaCode,
        public readonly string $criteriaName,
        public readonly bool $isGate,
        public readonly bool $passed,
        public readonly float $weight,
        public readonly float $awarded,
        public readonly mixed $applicantValue,
        public readonly string $explanation,
    ) {
    }

    public function toArray(): array
    {
        return [
            'criteria' => $this->criteriaCode,
            'label' => $this->criteriaName,
            'is_gate' => $this->isGate,
            'passed' => $this->passed,
            'weight' => round($this->weight, 2),
            'score' => round($this->awarded, 2),
            'applicant_value' => $this->normaliseValue($this->applicantValue),
            'explanation' => $this->explanation,
        ];
    }

    /**
     * Keeps the stored breakdown JSON readable. Dates become plain strings and
     * lists are flattened, so the trace stays legible when read straight out of
     * the database years later.
     */
    private function normaliseValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => (string) $v, $value));
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return $value;
    }
}
