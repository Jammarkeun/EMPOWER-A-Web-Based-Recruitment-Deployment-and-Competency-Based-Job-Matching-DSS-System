<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ClientCompany
 */
class ClientCompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_code' => $this->company_code,
            'company_name' => $this->company_name,
            'business_type' => $this->business_type,
            'contact_person' => $this->contact_person,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'office_address' => $this->office_address,
            'status' => $this->status,
            'notes' => $this->notes,
            'departments' => ClientDepartmentResource::collection($this->whenLoaded('departments')),
            'departments_count' => $this->whenCounted('departments'),
            'job_requests_count' => $this->whenCounted('jobRequests'),
            'employees_count' => $this->whenCounted('employees'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
