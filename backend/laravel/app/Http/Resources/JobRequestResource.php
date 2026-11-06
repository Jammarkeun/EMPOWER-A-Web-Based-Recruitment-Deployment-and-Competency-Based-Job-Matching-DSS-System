<?php

namespace App\Http\Resources;

use App\Models\ManpowerRequisition;
use App\Models\ManpowerRequisitionStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\JobRequest
 */
class JobRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_code' => $this->request_code,
            'client_company_id' => $this->client_company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->company_name),
            'client_department_id' => $this->client_department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->department_name),

            'position_title' => $this->position_title,
            'required_education' => $this->required_education,
            'required_experience_months' => $this->required_experience_months,
            'required_certifications' => $this->required_certifications,
            'gender_preference' => $this->gender_preference,
            'age_min' => $this->age_min,
            'age_max' => $this->age_max,
            'height_min_cm' => $this->height_min_cm,
            'physical_requirement' => $this->physical_requirement,
            'availability_requirement' => $this->availability_requirement,

            'workers_needed' => $this->workers_needed,
            'workers_fulfilled' => $this->workers_fulfilled,
            'remaining_headcount' => $this->remaining_headcount,

            'date_requested' => $this->date_requested?->toDateString(),
            'deployment_deadline' => $this->deployment_deadline?->toDateString(),
            'is_overdue' => $this->deployment_deadline
                && $this->deployment_deadline->isPast()
                && $this->remaining_headcount > 0,

            'request_status' => $this->request_status,
            'status_label' => ucwords(str_replace('_', ' ', (string) $this->request_status)),
            'can_accept_deployment' => $this->canAcceptDeployment(),
            'request_source' => $this->request_source,
            'remarks' => $this->remarks,

            // Where the request came from: a coordinator requisition HR
            // approved, or HR recording it directly.
            'source' => $this->manpower_requisition_id ? 'requisition' : 'direct',
            'manpower_requisition_id' => $this->manpower_requisition_id,
            'source_requisition' => $this->whenLoaded('requisition', fn () => $this->requisition
                ? $this->sourceRequisition($this->requisition)
                : null),

            'criteria' => RequestCriterionResource::collection($this->whenLoaded('criteria')),
            // Only on the single-request view, where criteria are loaded: it
            // costs three aggregate queries (see evaluationState), which a list
            // of requests should not pay per row.
            'evaluation' => $this->whenLoaded('criteria', fn () => $this->evaluationState()),
            'job_position_id' => $this->job_position_id,
            'criteria_count' => $this->whenCounted('criteria'),
            'matches_count' => $this->whenCounted('matches'),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The requisition as the coordinator submitted it - the client's own
     * words, kept apart from the criteria and weights HR sets for matching.
     * The list loads only the code; the single view loads the rest.
     */
    private function sourceRequisition(ManpowerRequisition $r): array
    {
        $summary = [
            'id' => $r->id,
            'requisition_code' => $r->requisition_code,
            'status' => $r->status,
        ];

        if (! $r->relationLoaded('history')) {
            return $summary;
        }

        $approval = $r->history->last(fn (ManpowerRequisitionStatusHistory $h) => $h->to_status === 'approved');

        return $summary + [
            'status_label' => ManpowerRequisition::LABELS[$r->status] ?? $r->status,
            'provisional' => $r->isProvisional(),
            'position_title' => $r->position_title,
            'workers_needed' => $r->workers_needed,
            'male_count' => $r->male_count,
            'female_count' => $r->female_count,
            'date_requested' => $r->date_requested?->toDateString(),
            'required_by' => $r->required_by?->toDateString(),
            'qualifications' => $r->qualifications,
            'other_remarks' => $r->other_remarks,
            'request_reason_label' => $r->request_reason
                ? config("empower.requisition_form.reasons.{$r->request_reason}", $r->request_reason)
                : null,
            'request_reason_details' => $r->request_reason_details,
            // The temporary form's free-text reason, on requisitions filed on it.
            'reason' => $r->reason,
            'department_named' => (bool) $r->client_department_id,
            'requested_by' => $r->requester?->full_name,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'approved_by' => $approval?->changedBy?->full_name,
            'approved_at' => $approval?->changed_at?->toIso8601String(),
        ];
    }
}
