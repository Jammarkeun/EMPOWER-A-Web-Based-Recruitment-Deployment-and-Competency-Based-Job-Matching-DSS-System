<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Applicant
 */
class ApplicantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'applicant_code' => $this->applicant_code,
            'source_channel' => $this->source_channel,

            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'full_name' => $this->full_name,

            'sex' => $this->sex,
            'birth_date' => $this->birth_date?->toDateString(),
            'age' => $this->age,
            'civil_status' => $this->civil_status,
            'nationality' => $this->nationality,
            'height_cm' => $this->height_cm,
            'weight_kg' => $this->weight_kg,

            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'present_address' => $this->present_address,
            'provincial_address' => $this->provincial_address,

            'preferred_position' => $this->preferred_position,
            'preferred_position_id' => $this->preferred_position_id,

            // The applicant's own answer to an offer of work, which is theirs to
            // give and nobody else's to enter. Shown to HR because deploying
            // somebody who has declined is refused, and the reason should be on
            // the screen rather than only in the error.
            // Why an application stopped, where one did. Countable, so the
            // agency can see how many people it loses to each cause.
            'disposition_reason' => $this->disposition_reason,
            'disposition_label' => match ($this->disposition_reason) {
                'incomplete_requirements' => 'Incomplete requirements',
                'not_suitable' => 'Not suitable for the job',
                'other' => 'Other',
                default => null,
            },
            'disposition_note' => $this->disposition_note,

            'placement_response' => $this->placement_response,
            'placement_responded_at' => $this->placement_responded_at?->toIso8601String(),
            'placement_response_note' => $this->placement_response_note,
            'availability_date' => $this->availability_date?->toDateString(),
            'distance_km' => $this->distance_km,
            'communication_rating' => $this->communication_rating,
            'reliability_rating' => $this->reliability_rating,

            'application_date' => $this->application_date?->toDateString(),
            'self_registered_at' => $this->self_registered_at?->toIso8601String(),
            'identity_verified_at' => $this->identity_verified_at?->toIso8601String(),
            'awaiting_identity_check' => $this->awaiting_identity_check,
            'current_status' => $this->current_status,
            'status_label' => ucwords(str_replace('_', ' ', $this->current_status)),
            'folder_category' => $this->folder_category,
            'folder_label' => config("empower.folders.{$this->folder_category}.label"),
            'remarks' => $this->remarks,

            'educations' => ApplicantEducationResource::collection($this->whenLoaded('educations')),
            'experiences' => ApplicantExperienceResource::collection($this->whenLoaded('experiences')),
            'skills' => $this->whenLoaded('skills'),
            'certifications' => $this->whenLoaded('certifications'),
            'requirements' => ApplicantRequirementResource::collection($this->whenLoaded('requirements')),
            'status_history' => StatusHistoryResource::collection($this->whenLoaded('statusHistory')),
            'employee' => new EmployeeResource($this->whenLoaded('employee')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
