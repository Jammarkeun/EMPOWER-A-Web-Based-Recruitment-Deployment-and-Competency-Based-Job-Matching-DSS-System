<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Employee
 */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'applicant_id' => $this->applicant_id,
            'employee_number' => $this->employee_number,
            'biometric_number' => $this->biometric_number,
            'full_name' => $this->whenLoaded('applicant', fn () => $this->applicant->full_name),

            'current_company' => $this->whenLoaded('currentCompany', fn () => $this->currentCompany?->company_name),
            'current_department' => $this->whenLoaded('currentDepartment', fn () => $this->currentDepartment?->department_name),
            'current_position_title' => $this->current_position_title,
            'current_supervisor_name' => $this->current_supervisor_name,

            'hire_date' => $this->hire_date?->toDateString(),
            'employment_status' => $this->employment_status,
            'status_label' => ucfirst($this->employment_status),
            'profile_notes' => $this->profile_notes,

            'applicant' => new ApplicantResource($this->whenLoaded('applicant')),
            'deployments' => DeploymentResource::collection($this->whenLoaded('deployments')),
            'violations' => ViolationResource::collection($this->whenLoaded('violations')),
            'violations_count' => $this->whenCounted('violations'),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
