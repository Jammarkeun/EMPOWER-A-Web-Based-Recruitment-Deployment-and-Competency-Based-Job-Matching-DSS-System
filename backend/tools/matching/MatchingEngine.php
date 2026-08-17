<?php

namespace Empower\Matching;

class MatchingEngine
{
    /**
     * Evaluates applicants against request criteria.
     *
     * @param array $criteriaList Array of criteria definitions. Each definition:
     *   - code: string
     *   - mandatory_flag: bool
     *   - weight: float
     *   - expected_value/match_value/min_value/max_value/rubric (optional)
     * @param array $applicants Array of applicant associative arrays with relevant fields.
     * @param array $options Optional tie-breaker order keys, e.g. ['hard_pass_first' => true]
     * @return array Ranked result: [ { applicant_id, raw_score, percentage, hard_pass, breakdown }, ... ]
     */
    public function evaluate(array $criteriaList, array $applicants, array $options = []): array
    {
        $results = [];

        // Precompute total weight (only include weights > 0)
        $totalWeight = 0.0;
        foreach ($criteriaList as $c) {
            $w = isset($c['weight']) ? floatval($c['weight']) : 0.0;
            $totalWeight += max(0.0, $w);
        }
        if ($totalWeight <= 0) {
            throw new \InvalidArgumentException('Total criteria weight must be greater than zero.');
        }

        foreach ($applicants as $app) {
            $rawScore = 0.0;
            $maxScore = 0.0;
            $hardPass = true;
            $breakdown = [];

            foreach ($criteriaList as $c) {
                $code = $c['code'];
                $weight = isset($c['weight']) ? floatval($c['weight']) : 0.0;
                $maxScore += $weight;

                $value = $this->extractApplicantValue($app, $code);

                $criterionScore = 0.0;
                $explanation = null;

                // Hard filter
                if (isset($c['type']) && $c['type'] === 'hard_filter') {
                    $expected = $c['expected'] ?? null;
                    $pass = $this->evaluateHardFilter($value, $expected, $c);
                    if ($pass) {
                        $criterionScore = $weight; // full weight if pass
                        $explanation = 'passed';
                    } else {
                        $criterionScore = 0.0;
                        $hardPass = false;
                        $explanation = 'failed';
                    }
                } else {
                    // Weighted criteria: binary or scalar or rubric
                    if (isset($c['rubric']) && is_array($c['rubric'])) {
                        // rubric maps discrete labels to 0..1 multipliers
                        $mult = $this->evaluateRubric($value, $c['rubric']);
                        $criterionScore = $weight * $mult;
                        $explanation = 'rubric:' . number_format($mult, 3);
                    } elseif (isset($c['min']) || isset($c['max'])) {
                        // numeric range scoring
                        $min = isset($c['min']) ? floatval($c['min']) : null;
                        $max = isset($c['max']) ? floatval($c['max']) : null;
                        $mult = $this->evaluateScale($value, $min, $max);
                        $criterionScore = $weight * $mult;
                        $explanation = 'scale:' . number_format($mult, 3);
                    } elseif (isset($c['expected'])) {
                        // expected is a value, match yields full weight
                        $mult = ($value !== null && strval($value) === strval($c['expected'])) ? 1.0 : 0.0;
                        $criterionScore = $weight * $mult;
                        $explanation = 'expected:' . ($mult ? 'match' : 'no_match');
                    } else {
                        // Fallback: if value truthy, full weight
                        $mult = $value ? 1.0 : 0.0;
                        $criterionScore = $weight * $mult;
                        $explanation = 'binary:' . ($mult ? '1' : '0');
                    }
                }

                $rawScore += $criterionScore;
                $breakdown[$code] = [
                    'weight' => $weight,
                    'score' => round($criterionScore, 3),
                    'explanation' => $explanation
                ];
            }

            $percentage = ($rawScore / ($maxScore ?: 1.0)) * 100.0;

            $results[] = [
                'applicant_id' => $app['id'] ?? null,
                'name' => $app['name'] ?? null,
                'raw_score' => round($rawScore, 3),
                'max_score' => round($maxScore, 3),
                'percentage' => round($percentage, 2),
                'hard_pass' => $hardPass ? true : false,
                'breakdown' => $breakdown,
                'applied_at' => $app['application_date'] ?? null,
                'distance_km' => isset($app['distance_km']) ? floatval($app['distance_km']) : null
            ];
        }

        // Ranking: primary by percentage desc, but ensure hard filter passers come first if option set
        usort($results, function ($a, $b) use ($options) {
            // hard pass first
            if (!empty($options['hard_pass_first'])) {
                if ($a['hard_pass'] && ! $b['hard_pass']) return -1;
                if (! $a['hard_pass'] && $b['hard_pass']) return 1;
            }
            // compare percentage
            $cmp = $b['percentage'] <=> $a['percentage'];
            if ($cmp !== 0) {
                return $cmp;
            }
            // tie-breaker: hard filter compliance count (not directly available), fallback earliest applied
            if (!empty($options['tie_breaker']) && $options['tie_breaker'] === 'earliest_application') {
                $ta = strtotime($a['applied_at'] ?? '1970-01-01');
                $tb = strtotime($b['applied_at'] ?? '1970-01-01');
                return $ta <=> $tb;
            }
            // fallback distance (closer first)
            if ($a['distance_km'] !== null && $b['distance_km'] !== null) {
                return $a['distance_km'] <=> $b['distance_km'];
            }
            return 0;
        });

        // assign rank and recommendation
        foreach ($results as $idx => &$r) {
            $r['rank'] = $idx + 1;
            $r['recommendation_level'] = $this->recommendationLevel($r['percentage'], $r['hard_pass']);
        }

        return $results;
    }

    protected function extractApplicantValue(array $applicant, string $code)
    {
        // Map expected codes to applicant keys (simple mapping; integrate with DB fields later)
        $map = [
            'education' => 'education_level',
            'experience' => 'months_experience',
            'skills' => 'skills',
            'availability' => 'availability',
            'distance' => 'distance_km',
            'height' => 'height_cm',
            'gender' => 'sex',
            'certifications' => 'certifications',
            'communication' => 'communication_rating',
            'reliability' => 'reliability_rating'
        ];

        $key = $map[$code] ?? $code;
        return $applicant[$key] ?? null;
    }

    protected function evaluateHardFilter($value, $expected, array $criterion): bool
    {
        if ($expected === null) return true;
        // handle when applicant value is an array (e.g., certifications)
        if (is_array($value)) {
            if (is_array($expected)) {
                // any overlap required
                foreach ($expected as $e) {
                    if (in_array($e, $value, true)) return true;
                }
                return false;
            }
            return in_array($expected, $value, true);
        }

        if (is_array($expected)) {
            return in_array($value, $expected, true);
        }

        return strval($value) === strval($expected);
    }

    protected function evaluateRubric($value, array $rubric): float
    {
        if ($value === null) return 0.0;
        $v = strval($value);
        if (isset($rubric[$v])) return floatval($rubric[$v]);
        // fallback: if numeric string, scale between 0..1 clamped
        if (is_numeric($v)) {
            $f = floatval($v);
            return max(0.0, min(1.0, $f));
        }
        return 0.0;
    }

    protected function evaluateScale($value, $min = null, $max = null): float
    {
        if ($value === null) return 0.0;
        if (!is_numeric($value)) return 0.0;
        $val = floatval($value);
        if ($min !== null && $max !== null) {
            if ($max == $min) return ($val >= $min) ? 1.0 : 0.0;
            // linear interpolation within min..max
            if ($val <= $min) return 0.0;
            if ($val >= $max) return 1.0;
            return ($val - $min) / ($max - $min);
        }
        if ($min !== null) return ($val >= $min) ? 1.0 : 0.0;
        if ($max !== null) return ($val <= $max) ? 1.0 : 0.0;
        return 0.0;
    }

    protected function recommendationLevel(float $percentage, bool $hardPass): string
    {
        if (! $hardPass) return 'not_recommended';
        if ($percentage >= 85) return 'highly_recommended';
        if ($percentage >= 70) return 'recommended';
        if ($percentage >= 50) return 'reserve_pool';
        return 'not_recommended';
    }
}
