<?php

namespace App\Http\Controllers;

use App\Http\Resources\DeploymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\Deployment;
use App\Models\JobRequest;
use App\Services\DeploymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeploymentController extends Controller
{
    public function __construct(private readonly DeploymentService $deployments)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Deployment::class);

        $filters = $request->validate([
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'client_department_id' => ['nullable', 'integer', 'exists:client_departments,id'],
            'deployment_status' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $deployments = Deployment::query()
            ->with(['employee.applicant', 'company', 'department'])
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['client_department_id'] ?? null, fn ($q, $v) => $q->where('client_department_id', $v))
            ->when($filters['deployment_status'] ?? null, fn ($q, $v) => $q->where('deployment_status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('deployment_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('deployment_date', '<=', $v))
            ->orderByDesc('deployment_date')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated(DeploymentResource::collection($deployments), 'Deployments retrieved');
    }

    /**
     * Record a deployment, converting the applicant into an employee.
     *
     * Deliberately a separate, explicit action rather than something the
     * matching engine can trigger: the system recommends, a person decides.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Deployment::class);

        $data = $request->validate([
            'applicant_id' => ['required', 'integer', 'exists:applicants,id'],
            'job_request_id' => ['required', 'integer', 'exists:job_requests,id'],
            'client_department_id' => ['nullable', 'integer', 'exists:client_departments,id'],
            'position_title' => ['nullable', 'string', 'max:150'],
            'supervisor_name' => ['nullable', 'string', 'max:190'],
            'employee_number' => ['nullable', 'string', 'max:60', 'unique:employees,employee_number'],
            'biometric_number' => ['nullable', 'string', 'max:60'],
            'deployment_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);

        $applicant = Applicant::findOrFail($data['applicant_id']);
        $jobRequest = JobRequest::with('company')->findOrFail($data['job_request_id']);

        $deployment = $this->deployments->deploy($applicant, $jobRequest, $data, $request->user());

        return ApiResponse::created(
            new DeploymentResource($deployment),
            'Deployment recorded. The applicant is now an active employee.'
        );
    }

    public function show(Deployment $deployment): JsonResponse
    {
        $this->authorize('view', $deployment);

        $deployment->load(['employee.applicant', 'company', 'department', 'jobRequest', 'history']);

        return ApiResponse::success(new DeploymentResource($deployment));
    }

    public function reassign(Request $request, Deployment $deployment): JsonResponse
    {
        $this->authorize('reassign', $deployment);

        $data = $request->validate([
            'client_company_id' => ['required', 'integer', 'exists:client_companies,id'],
            'client_department_id' => ['required', 'integer', 'exists:client_departments,id'],
            'position_title' => ['nullable', 'string', 'max:150'],
            'supervisor_name' => ['nullable', 'string', 'max:190'],
            'change_type' => ['required', Rule::in(['reassignment', 'transfer', 'return'])],
            'effective_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);

        $deployment = $this->deployments->reassign($deployment, $data, $request->user());

        return ApiResponse::success(new DeploymentResource($deployment), 'Employee reassigned');
    }
}
