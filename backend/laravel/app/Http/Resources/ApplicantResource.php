<?php

namespace App\Http\Resources;

use App\Services\ProfilePhotoService;
use App\Support\ApplicantStatuses;
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

            // A signed link to the applicant's own picture, minted only inside
            // responses the viewer was already allowed to receive.
            'photo_url' => app(ProfilePhotoService::class)->url($this->profile_photo_path),

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

            // The answer to the current endorsement (audit R-05, R-07), when the
            // caller loaded it; the full history is in the `placement` block of
            // the applicant view. The old applicant columns are no longer read.
            'placement_response' => $this->currentPlacementDecision()?->decision,
            'placement_responded_at' => $this->currentPlacementDecision()?->decided_at?->toIso8601String(),
            'placement_response_note' => $this->currentPlacementDecision()?->note,
            'availability_date' => $this->availability_date?->toDateString(),
            'distance_km' => $this->distance_km,
            'communication_rating' => $this->communication_rating,
            'reliability_rating' => $this->reliability_rating,

            'application_date' => $this->application_date?->toDateString(),
            'self_registered_at' => $this->self_registered_at?->toIso8601String(),
            'identity_verified_at' => $this->identity_verified_at?->toIso8601String(),
            'awaiting_identity_check' => $this->awaiting_identity_check,
            'current_status' => $this->current_status,
            'status_label' => ApplicantStatuses::label($this->current_status),
            'stage' => ApplicantStatuses::stageOf((string) $this->current_status),
            // The status says every document was verified; the folder says one
            // has lapsed or been rejected since. Shown, never acted on (WF-06).
            'documents_lapsed' => ApplicantStatuses::documentsLapsed((string) $this->current_status, $this->folder_category),
            // Placed today, by the employment record, when the list asked for
            // it (Applicant::scopeWithPlacement).
            'placement' => $this->when(
                array_key_exists('currently_deployed', $this->resource->getAttributes()),
                fn () => ApplicantStatuses::placement(
                    $this->current_status,
                    (bool) $this->resource->getAttribute('currently_deployed'),
                    $this->resource->getAttribute('deployed_company'),
                )
            ),
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

    /**
     * Read only from relations the caller eager-loaded, so a list of applicants
     * never issues a query per row.
     */
    private function currentPlacementDecision(): ?\App\Models\PlacementDecision
    {
        if (! $this->resource->relationLoaded('openEndorsement') || ! $this->openEndorsement) {
            return null;
        }

        return $this->openEndorsement->relationLoaded('currentDecision')
            ? $this->openEndorsement->currentDecision
            : null;
    }
}
