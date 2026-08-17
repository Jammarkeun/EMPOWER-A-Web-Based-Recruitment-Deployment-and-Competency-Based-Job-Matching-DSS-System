<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Deployment
 */
class DeploymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deployment_code' => $this->deployment_code,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee->full_name),
            'employee_number' => $this->whenLoaded('employee', fn () => $this->employee->employee_number),
            'job_request_id' => $this->job_request_id,
            'client_company_id' => $this->client_company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->company_name),
            'client_department_id' => $this->client_department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->department_name),
            'position_title' => $this->position_title,
            'supervisor_name' => $this->supervisor_name,
            'deployment_date' => $this->deployment_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'deployment_status' => $this->deployment_status,
            'remarks' => $this->remarks,
            'history' => $this->whenLoaded('history'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
