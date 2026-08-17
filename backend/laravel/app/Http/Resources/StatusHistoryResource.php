<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders one entry of an applicant's or employee's status timeline.
 *
 * @mixin \App\Models\ApplicationStatusHistory
 */
class StatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'from_label' => $this->from_status
                ? ucwords(str_replace('_', ' ', $this->from_status))
                : null,
            'to_label' => ucwords(str_replace('_', ' ', $this->to_status)),
            'reason' => $this->reason,
            'changed_at' => $this->changed_at?->toIso8601String(),
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy?->full_name),
        ];
    }
}
