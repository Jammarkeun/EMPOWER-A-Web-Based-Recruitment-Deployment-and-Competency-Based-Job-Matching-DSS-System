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

            /*
             * Upload and verification are reported as two independent facts,
             * because they genuinely are. A walk-in applicant's requirement can
             * be verified with no file ever stored, and a portal upload can sit
             * unverified for days. Collapsing them into one value is what made
             * "not uploaded" wrongly read as "not verified".
             */
            'has_file' => ! is_null($this->file_path),
            'upload_status' => is_null($this->file_path) ? 'not_uploaded' : 'uploaded',

            /*
             * Where the document stands as a person waiting on it would
             * understand it, which is not the same question as `status`.
             *
             * A stored file used to be reported as "being checked" the moment
             * the upload finished. It was not being checked; it had been
             * received. The two are told apart here by whether a member of
             * staff has actually opened it, and both the portal and the staff
             * screens read this one value so they cannot disagree.
             */
            'review_state' => $this->reviewState(),
            'review_state_label' => match ($this->reviewState()) {
                'not_uploaded' => 'Not yet sent',
                'uploaded' => 'Upload successful',
                'under_review' => 'Being checked',
                'verified' => 'Accepted',
                'rejected' => 'Needs resubmitting',
                'needs_correction' => 'Needs correction',
                'expired' => 'Expired',
                default => 'Not yet sent',
            },
            'first_viewed_at' => $this->first_viewed_at?->toIso8601String(),
            'first_viewed_by' => $this->whenLoaded('firstViewer', fn () => $this->firstViewer?->full_name),

            'status' => $this->status,
            'status_label' => ucfirst(str_replace('_', ' ', $this->status)),
            'verification_method' => $this->verification_method,
            'verification_method_label' => match ($this->verification_method) {
                'online_upload' => 'Online upload',
                'walk_in' => 'Walk-in / physical',
                'other' => 'Other',
                default => null,
            },
            'verification_note' => $this->verification_note,

            // The storage key is deliberately not exposed. Downloads go through
            // an endpoint that mints a short-lived signed URL, so a document
            // reference in the client cannot be replayed later.
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
