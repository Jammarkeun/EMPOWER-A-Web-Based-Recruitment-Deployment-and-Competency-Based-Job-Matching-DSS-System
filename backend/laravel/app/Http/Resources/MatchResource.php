<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ranked competency result, including the full per-criterion trace.
 *
 * The breakdown is always returned rather than hidden behind a second request:
 * a recommendation the user cannot immediately interrogate is not decision
 * support, it is just a number.
 *
 * @mixin \App\Models\JobRequestMatch
 */
class MatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_request_id' => $this->job_request_id,
            'applicant_id' => $this->applicant_id,
            'applicant_code' => $this->whenLoaded('applicant', fn () => $this->applicant->applicant_code),
            'applicant_name' => $this->whenLoaded('applicant', fn () => $this->applicant->full_name),
            'folder_category' => $this->whenLoaded('applicant', fn () => $this->applicant->folder_category),
            'current_status' => $this->whenLoaded('applicant', fn () => $this->applicant->current_status),

            'rank_order' => $this->rank_order,
            'raw_score' => (float) $this->raw_score,
            'max_score' => (float) $this->max_score,
            'percentage_score' => (float) $this->percentage_score,
            'recommendation_level' => $this->recommendation_level,
            'recommendation_label' => ucwords(str_replace('_', ' ', (string) $this->recommendation_level)),
            'hard_filter_pass' => $this->hard_filter_pass,

            'breakdown' => $this->breakdown_json,

            'is_shortlisted' => $this->is_shortlisted,
            'shortlisted_at' => $this->shortlisted_at?->toIso8601String(),
            'evaluated_at' => $this->evaluated_at?->toIso8601String(),
        ];
    }
}
