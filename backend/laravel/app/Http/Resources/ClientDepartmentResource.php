<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ClientDepartment
 */
class ClientDepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_company_id' => $this->client_company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company->company_name),
            'department_code' => $this->department_code,
            'department_name' => $this->department_name,
            'status' => $this->status,
            'job_requests_count' => $this->whenCounted('jobRequests'),

            /*
             * Two counts, because they answer different questions and a single
             * number would be wrong for one of them. `employees_count` is who
             * works here now; `former_employees_count` is what is left in the
             * history, so a department that has lost its whole team reads as
             * empty rather than as though its records had gone missing.
             */
            'employees_count' => $this->whenCounted('activeEmployees'),
            'former_employees_count' => $this->when(
                ! is_null($this->employees_count) && ! is_null($this->active_employees_count),
                fn () => max(0, (int) $this->employees_count - (int) $this->active_employees_count)
            ),
        ];
    }
}
