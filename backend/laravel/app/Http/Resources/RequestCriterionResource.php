<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RequestCriteria
 */
class RequestCriterionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'criteria_id' => $this->criteria_id,
            'criteria_code' => $this->whenLoaded('criterion', fn () => $this->criterion->criteria_code),
            'criteria_name' => $this->whenLoaded('criterion', fn () => $this->criterion->criteria_name),
            'criteria_type' => $this->whenLoaded('criterion', fn () => $this->criterion->criteria_type),
            'score_direction' => $this->whenLoaded('criterion', fn () => $this->criterion->score_direction),
            'description' => $this->whenLoaded('criterion', fn () => $this->criterion->description),

            // A mandatory criterion is a gate: it decides eligibility and is
            // excluded from the weighted score entirely.
            'mandatory_flag' => $this->mandatory_flag,
            'weight_score' => (float) $this->weight_score,
            'expected_value' => $this->expected_value,
            'min_value' => $this->min_value,
            'max_value' => $this->max_value,
            'rubric_json' => $this->rubric_json,
        ];
    }
}
