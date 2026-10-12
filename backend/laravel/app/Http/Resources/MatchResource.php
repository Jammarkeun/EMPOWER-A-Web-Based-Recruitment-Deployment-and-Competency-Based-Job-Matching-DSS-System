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

            // A shortlisted result kept after the applicant left the pool has
            // no rank: it is a record of a decision, not a recommendation.
            'in_current_pool' => $this->rank_order !== null,

            /*
             * What the applicant asked for, beside what they are being
             * matched to.
             *
             * CDE assesses its whole pool against a requisition, not only the
             * people who named that job, so this does not filter or score.
             * It is shown so HR can see at a glance who chose this work and
             * who would be offered something they did not apply for.
             */
            'preferred_position' => $this->whenLoaded('applicant', fn () => $this->applicant->preferred_position),
            'applied_for_this_position' => $this->whenLoaded('applicant', fn () => $this->relationLoaded('jobRequest')
                && $this->jobRequest?->job_position_id !== null
                && (int) $this->applicant->preferred_position_id === (int) $this->jobRequest->job_position_id),
        ];
    }
}
