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
            // Every move within the placement, newest first, in names. Read
            // only from relations the caller loaded (Deployment::HISTORY_RELATIONS),
            // never lazily.
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn ($move) => [
                'id' => $move->id,
                'change_type' => $move->change_type,
                'effective_date' => $move->effective_date?->toDateString(),
                'from_company' => $this->loadedName($move, 'fromCompany', 'company_name'),
                'from_department' => $this->loadedName($move, 'fromDepartment', 'department_name'),
                'from_position_title' => $move->from_position_title,
                'to_company' => $this->loadedName($move, 'toCompany', 'company_name'),
                'to_department' => $this->loadedName($move, 'toDepartment', 'department_name'),
                'to_position_title' => $move->to_position_title,
                'reason' => $move->remarks,
                'changed_by' => $this->loadedName($move, 'changedBy', 'full_name'),
                'recorded_at' => $move->created_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function loadedName($model, string $relation, string $attribute): ?string
    {
        return $model->relationLoaded($relation) ? $model->{$relation}?->{$attribute} : null;
    }
}
