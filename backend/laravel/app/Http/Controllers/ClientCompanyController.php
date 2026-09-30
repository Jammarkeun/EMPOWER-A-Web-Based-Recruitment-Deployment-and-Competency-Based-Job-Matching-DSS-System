<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientCompanyResource;
use App\Http\Resources\ClientDepartmentResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\CompanyCriteria;
use App\Models\CriteriaCatalog;
use App\Models\Deployment;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\Matching\CompetencyScoringService;
use App\Services\ReferenceCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                $client->departments()
                    // Counted in the query rather than by loading the people:
                    // a client with a few hundred placements would otherwise
                    // pull every employee row to display a number.
                    ->withCount(['jobRequests', 'employees', 'activeEmployees'])
                    ->orderBy('department_name')
                    ->get()
            )
        );
    }

    /**
     * Who is working in one department.
     *
     * Nested under the client on purpose. The company is not a filter that the
     * caller may drop - it is part of the address, so a department belonging to
     * another client does not resolve at all rather than resolving and then
     * being refused. That is what makes "company A cannot read company B's
     * staff" a property of the route rather than of a condition somebody has to
     * remember to write.
     *
     * Both permissions are required, not either. `clients.view` gets you the
     * company page; reading the names, positions, and employment status of the
     * people placed there is a different question, and a role that may see a
     * client's requests has no automatic claim on its payroll.
     */
    public function departmentEmployees(Request $request, ClientCompany $client, ClientDepartment $department): JsonResponse
    {
        $this->authorize('view', $client);
        $this->authorize('viewAny', Employee::class);

        abort_unless(
            (int) $department->client_company_id === (int) $client->id,
            404,
            'That department does not belong to this company.'
        );

        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $employees = $department->employees()
            ->with(['applicant', 'currentCompany', 'currentDepartment'])
            /*
             * Current staff first, then former, and by name within each.
             *
             * Ordering by hire date would bury a long-serving worker at the
             * bottom of a list somebody is scanning for a name. The status sort
             * is a CASE expression rather than an ordering on the status text,
             * because alphabetically "active" happens to sort before
             * "resigned" and "terminated" today and would stop doing so the
             * moment a status beginning with A through D is added.
             */
            ->orderByRaw("CASE WHEN employment_status = 'active' THEN 0 ELSE 1 END")
            ->orderBy(
                Applicant::select('last_name')->whereColumn('applicants.id', 'employees.applicant_id')
            )
            ->paginate($filters['per_page'] ?? 50);

        /*
         * The department travels with its people.
         *
         * This page is reachable by its own URL, so it cannot assume the
         * company screen was visited first and left the department name lying
         * around. Sending both means one request answers "which department is
         * this, and who is in it".
         */
        return ApiResponse::success([
            'department' => new ClientDepartmentResource(
                $department->loadCount(['employees', 'activeEmployees', 'jobRequests'])
            ),
            'company' => [
                'id' => $client->id,
                'company_name' => $client->company_name,
            ],
            'employees' => EmployeeResource::collection($employees->items()),
        ], $department->department_name, 200, [
            'current_page' => $employees->currentPage(),
            'per_page' => $employees->perPage(),
            'total' => $employees->total(),
            'last_page' => $employees->lastPage(),
        ]);
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

    /**
     * Everything that belongs to one client company, in one call.
     *
     * The company page is the natural place to start work — you pick the client
     * first, then look at their requests, their people, and their requirements —
     * so this gathers those in a single request rather than leaving the page to
     * assemble itself from four.
     */
    public function overview(ClientCompany $client): JsonResponse
    {
        $this->authorize('view', $client);

        $requests = $client->jobRequests()
            ->with('department')
            ->orderByDesc('date_requested')
            ->take(10)
            ->get();

        $deployed = Deployment::query()
            ->with(['employee.applicant', 'department'])
            ->where('client_company_id', $client->id)
            ->where('deployment_status', 'active')
            ->orderByDesc('deployment_date')
            ->take(10)
            ->get();

        return ApiResponse::success([
            'company' => new ClientCompanyResource($client->loadCount('departments')),

            'summary' => [
                // Counted rather than derived from the trimmed lists above,
                // which are only the most recent ten.
                'open_requests' => $client->jobRequests()->whereIn('request_status', ['open', 'partially_fulfilled'])->count(),
                'positions_to_fill' => (int) $client->jobRequests()
                    ->whereIn('request_status', ['open', 'partially_fulfilled'])
                    ->selectRaw('COALESCE(SUM(workers_needed - workers_fulfilled), 0) AS total')
                    ->value('total'),
                'deployed_staff' => Deployment::where('client_company_id', $client->id)
                    ->where('deployment_status', 'active')->count(),
                'departments' => $client->departments()->count(),
                'requirements_set' => CompanyCriteria::where('client_company_id', $client->id)->count(),
            ],

            'requests' => $requests->map(fn ($r) => [
                'id' => $r->id,
                'request_code' => $r->request_code,
                'position_title' => $r->position_title,
                'department' => $r->department?->department_name,
                'workers_needed' => $r->workers_needed,
                'workers_fulfilled' => $r->workers_fulfilled,
                'request_status' => $r->request_status,
                'deadline' => $r->deployment_deadline?->toDateString(),
            ])->values(),

            'deployed' => $deployed->map(fn ($d) => [
                'deployment_id' => $d->id,
                'employee_id' => $d->employee_id,
                'applicant_id' => $d->employee?->applicant_id,
                'name' => $d->employee?->applicant?->full_name ?? 'Unknown',
                'employee_number' => $d->employee?->employee_number,
                'position' => $d->position_title,
                'department' => $d->department?->department_name,
                'deployment_date' => $d->deployment_date?->toDateString(),
            ])->values(),
        ]);
    }

    /**
     * The requirements this client asks for as standard.
     *
     * Returns the whole catalogue with whatever the company has set against each
     * entry, so the screen can show every available criterion rather than only
     * the ones already chosen — otherwise adding a new one means knowing it
     * exists before you can find it.
     */
    public function criteria(ClientCompany $client): JsonResponse
    {
        $this->authorize('view', $client);

        $configured = CompanyCriteria::where('client_company_id', $client->id)
            ->get()
            ->keyBy('criteria_id');

        $catalogue = CriteriaCatalog::where('is_active', true)->orderBy('criteria_name')->get();

        return ApiResponse::success([
            'company' => ['id' => $client->id, 'company_name' => $client->company_name],
            'criteria' => $catalogue->map(function (CriteriaCatalog $criterion) use ($configured) {
                $set = $configured->get($criterion->id);

                return [
                    'criteria_id' => $criterion->id,
                    'code' => $criterion->criteria_code,
                    'name' => $criterion->criteria_name,
                    'description' => $criterion->description,
                    'criteria_type' => $criterion->criteria_type,
                    'value_type' => $criterion->value_type,

                    // What this criterion's expected value accepts, published
                    // the same way as on a request so both forms offer the same
                    // vocabulary and neither has to guess it.
                    'accepts' => match (true) {
                        CompetencyScoringService::acceptedValues($criterion->criteria_code) !== null => 'choice',
                        $criterion->value_type === 'text' => 'list',
                        default => 'none',
                    },
                    'options' => CompetencyScoringService::acceptedValues($criterion->criteria_code),

                    // Null-safe throughout: most criteria in the catalogue are
                    // not configured for a given company, so $set is usually
                    // null and a plain -> read would warn on every one of them.
                    'in_use' => (bool) $set,
                    'mandatory_flag' => (bool) ($set?->mandatory_flag ?? false),
                    'weight_score' => (float) ($set?->weight_score ?? 0),
                    'expected_value' => $set?->expected_value,
                    'min_value' => $set?->min_value !== null ? (float) $set->min_value : null,
                    'max_value' => $set?->max_value !== null ? (float) $set->max_value : null,
                    'note' => $set?->note,
                ];
            })->values(),
        ]);
    }

    /**
     * Replace this client's standing requirements.
     *
     * Sent as the complete set rather than as individual edits: the screen shows
     * every criterion at once with a tick beside the ones in use, so "what the
     * client asks for" is one decision saved once, not a series of separate
     * changes that can be half-applied.
     */
    public function setCriteria(Request $request, ClientCompany $client): JsonResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'criteria' => ['present', 'array'],
            'criteria.*.criteria_id' => ['required', 'integer', 'exists:criteria_catalog,id'],
            'criteria.*.mandatory_flag' => ['boolean'],
            'criteria.*.weight_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'criteria.*.expected_value' => ['nullable', 'string', 'max:255'],
            'criteria.*.min_value' => ['nullable', 'numeric'],
            'criteria.*.max_value' => ['nullable', 'numeric', 'gte:criteria.*.min_value'],
            'criteria.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $client, $request) {
            CompanyCriteria::where('client_company_id', $client->id)->delete();

            foreach ($data['criteria'] as $row) {
                CompanyCriteria::create([
                    'client_company_id' => $client->id,
                    'criteria_id' => $row['criteria_id'],
                    'mandatory_flag' => $row['mandatory_flag'] ?? false,
                    'weight_score' => $row['weight_score'] ?? 0,
                    'expected_value' => $row['expected_value'] ?? null,
                    'min_value' => $row['min_value'] ?? null,
                    'max_value' => $row['max_value'] ?? null,
                    'note' => $row['note'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        $this->audit->record(
            action: 'update',
            module: 'clients',
            recordType: ClientCompany::class,
            recordId: $client->id,
            newValues: [
                'company_requirements' => count($data['criteria']),
                'company' => $client->company_name,
            ],
        );

        return $this->criteria($client);
    }

}
