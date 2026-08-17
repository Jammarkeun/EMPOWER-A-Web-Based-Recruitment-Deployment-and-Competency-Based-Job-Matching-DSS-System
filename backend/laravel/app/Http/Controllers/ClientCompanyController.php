<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientCompanyResource;
use App\Http\Resources\ClientDepartmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Services\AuditService;
use App\Services\ReferenceCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientCompanyController extends Controller
{
    public function __construct(
        private readonly ReferenceCodeService $codes,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ClientCompany::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'business_type' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $operator = ClientCompany::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $companies = ClientCompany::query()
            ->withCount(['departments', 'jobRequests', 'employees'])
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(function ($sub) use ($v, $operator) {
                $sub->where('company_name', $operator, "%{$v}%")
                    ->orWhere('company_code', $operator, "%{$v}%")
                    ->orWhere('contact_person', $operator, "%{$v}%");
            }))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['business_type'] ?? null, fn ($q, $v) => $q->where('business_type', $v))
            ->orderBy('company_name')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated(ClientCompanyResource::collection($companies), 'Client companies retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ClientCompany::class);

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:190', 'unique:client_companies,company_name'],
            'business_type' => ['required', 'string', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:190'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'office_address' => ['required', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
        ]);

        $company = ClientCompany::create(array_merge($data, [
            'company_code' => $this->codes->client(),
            'status' => $data['status'] ?? 'active',
            'created_by' => $request->user()->id,
        ]));

        $this->audit->record('create', 'clients', ClientCompany::class, $company->id, null, $data);

        return ApiResponse::created(new ClientCompanyResource($company), 'Client company registered');
    }

    public function show(ClientCompany $client): JsonResponse
    {
        $this->authorize('view', $client);

        $client->load(['departments' => fn ($q) => $q->withCount('jobRequests')])
            ->loadCount(['jobRequests', 'employees']);

        return ApiResponse::success(new ClientCompanyResource($client));
    }

    public function update(Request $request, ClientCompany $client): JsonResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:190', Rule::unique('client_companies', 'company_name')->ignore($client->id)],
            'business_type' => ['sometimes', 'string', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:190'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'office_address' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
        ]);

        $before = $client->only(array_keys($data));
        $client->update(array_merge($data, ['updated_by' => $request->user()->id]));

        $this->audit->recordUpdate('clients', ClientCompany::class, $client->id, $before, $data);

        return ApiResponse::success(new ClientCompanyResource($client->fresh()), 'Client company updated');
    }

    // --------------------------------------------------------------- departments

    public function departments(ClientCompany $client): JsonResponse
    {
        $this->authorize('view', $client);

        return ApiResponse::success(
            ClientDepartmentResource::collection(
                $client->departments()->withCount('jobRequests')->orderBy('department_name')->get()
            )
        );
    }

    public function storeDepartment(Request $request, ClientCompany $client): JsonResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'department_code' => [
                'required', 'string', 'max:40',
                // Codes and names are unique per company, not globally:
                // "Packaging" legitimately exists at several clients at once.
                Rule::unique('client_departments', 'department_code')
                    ->where('client_company_id', $client->id),
            ],
            'department_name' => [
                'required', 'string', 'max:190',
                Rule::unique('client_departments', 'department_name')
                    ->where('client_company_id', $client->id),
            ],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $department = $client->departments()->create(array_merge($data, [
            'status' => $data['status'] ?? 'active',
            'created_by' => $request->user()->id,
        ]));

        $this->audit->record('create', 'clients', ClientDepartment::class, $department->id, null, $data);

        return ApiResponse::created(new ClientDepartmentResource($department), 'Department added');
    }

    public function updateDepartment(Request $request, ClientCompany $client, ClientDepartment $department): JsonResponse
    {
        $this->authorize('update', $client);

        abort_unless($department->client_company_id === $client->id, 404);

        $data = $request->validate([
            'department_code' => [
                'sometimes', 'string', 'max:40',
                Rule::unique('client_departments', 'department_code')
                    ->where('client_company_id', $client->id)->ignore($department->id),
            ],
            'department_name' => [
                'sometimes', 'string', 'max:190',
                Rule::unique('client_departments', 'department_name')
                    ->where('client_company_id', $client->id)->ignore($department->id),
            ],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $before = $department->only(array_keys($data));
        $department->update(array_merge($data, ['updated_by' => $request->user()->id]));

        $this->audit->recordUpdate('clients', ClientDepartment::class, $department->id, $before, $data);

        return ApiResponse::success(new ClientDepartmentResource($department->fresh()), 'Department updated');
    }
}
