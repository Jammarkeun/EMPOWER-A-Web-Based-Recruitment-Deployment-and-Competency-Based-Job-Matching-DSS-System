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

            /*
             * Whether this offence still counts against the employee.
             *
             * Separate from `status`, which is about how the incident was
             * handled. An offence can be resolved and still count, or be
             * unresolved and have aged out — CDE's record clears after a year
             * regardless of what happened to the paperwork.
             *
             * Expired offences are still returned, and still appear in the
             * disciplinary report. They are marked, not hidden: a pattern of
             * behaviour over several years is exactly what an agency needs to
             * be able to see.
             */
            'is_active' => $this->isActive(),
            'is_expired' => $this->isExpired(),
            'expires_on' => $this->expiresOn()?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
