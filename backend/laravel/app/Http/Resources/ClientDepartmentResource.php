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
        ];
    }
}
