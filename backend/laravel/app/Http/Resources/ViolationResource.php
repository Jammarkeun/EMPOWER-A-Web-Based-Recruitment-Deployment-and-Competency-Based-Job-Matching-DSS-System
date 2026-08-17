<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\EmployeeViolation
 */
class ViolationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee->full_name),
            'violation_date' => $this->violation_date?->toDateString(),
            'violation_type' => $this->violation_type,
            'type_label' => ucwords(str_replace('_', ' ', (string) $this->violation_type)),
            'description' => $this->description,
            'has_evidence' => ! is_null($this->evidence_path),
            'issued_by' => $this->whenLoaded('issuer', fn () => $this->issuer?->full_name),
            'penalty' => $this->penalty,
            'status' => $this->status,
            'resolution_date' => $this->resolution_date?->toDateString(),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
