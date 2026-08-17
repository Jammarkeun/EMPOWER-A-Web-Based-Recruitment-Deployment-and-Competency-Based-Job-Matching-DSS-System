<?php

namespace App\Http\Resources;

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

            'criteria' => RequestCriterionResource::collection($this->whenLoaded('criteria')),
            'criteria_count' => $this->whenCounted('criteria'),
            'matches_count' => $this->whenCounted('matches'),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
