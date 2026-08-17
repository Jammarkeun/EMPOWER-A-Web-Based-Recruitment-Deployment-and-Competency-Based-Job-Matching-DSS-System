<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ApplicantRequirement
 */
class ApplicantRequirementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requirement_type_id' => $this->requirement_type_id,
            'requirement_code' => $this->whenLoaded('requirementType', fn () => $this->requirementType->requirement_code),
            'requirement_name' => $this->whenLoaded('requirementType', fn () => $this->requirementType->requirement_name),
            'requirement_group' => $this->whenLoaded('requirementType', fn () => $this->requirementType->requirement_group),

            // Needed by any form that offers an upload: a document that expires
            // must be sent with its expiry date, and without this flag the
            // client cannot know to ask for one — the upload would simply fail
            // validation with no field on screen to correct.
            'has_expiry' => $this->whenLoaded('requirementType', fn () => (bool) $this->requirementType->has_expiry),
            'is_required' => $this->whenLoaded('requirementType', fn () => (bool) $this->requirementType->is_required),

            'status' => $this->status,
            'status_label' => ucfirst($this->status),

            // The storage key is deliberately not exposed. Downloads go through
            // an endpoint that mints a short-lived signed URL, so a document
            // reference in the client cannot be replayed later.
            'has_file' => ! is_null($this->file_path),
            'file_name' => $this->file_name,
            'file_size_bytes' => $this->file_size_bytes,

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier?->full_name),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'is_expired' => $this->isExpired(),
            'counts_as_complete' => $this->countsAsComplete(),
            'rejection_reason' => $this->rejection_reason,
            'remarks' => $this->remarks,
        ];
    }
}
