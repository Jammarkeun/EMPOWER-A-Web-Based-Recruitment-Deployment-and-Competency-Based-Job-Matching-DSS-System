<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\ClientCompany;
use App\Models\JobPosition;
use App\Services\AuditService;
use App\Services\ReferenceCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The roles the agency recruits for.
 *
 * The list an applicant chooses from is read from here and nowhere else, which
 * is the point: adding a position for a new client company makes it appear on
 * the application form immediately, with no change to the front end. The agency
 * expands by signing clients, and a design that needed a developer for each one
 * would have failed on the second.
 */
class JobPositionController extends Controller
{
    public function __construct(
        private readonly ReferenceCodeService $codes,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * The positions a member of the public may apply for.
     *
     * Public, because the people who need it do not have accounts yet - the same
     * reason the document checklist beside it is public. It exposes nothing that
     * is not already on the agency's job postings: a job title and the company
     * that is hiring.
     */
    public function open(): JsonResponse
    {
        $positions = JobPosition::query()
            ->openForApplication()
            ->with('company:id,company_name')
            ->withCount(['jobRequests as open_request_count' => fn ($q) => $q->open()])
            ->orderBy('position_title')
            ->get();

        return ApiResponse::success([
            'positions' => $positions->map(fn (JobPosition $position) => [
                'id' => $position->id,
                'position_code' => $position->position_code,
                'position_title' => $position->position_title,
                'description' => $position->description,
                'company' => $position->company?->company_name,

                // Shown so an applicant can tell a role with vacancies open now
                // from one the agency places for from time to time. Both are
                // worth applying for, and saying which is which is more honest
                // than presenting one flat list.
                'is_hiring_now' => $position->open_request_count > 0,
            ])->values(),
        ]);
    }

    /**
     * Every position, for the staff screens that attach one to a request.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ClientCompany::class);

        $filters = $request->validate([
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $positions = JobPosition::query()
            ->with('company:id,company_name')
            ->when(
                $filters['client_company_id'] ?? null,
                fn ($q, $id) => $q->where('client_company_id', $id)
            )
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('position_title')
            ->get();

        return ApiResponse::success(['positions' => $this->present($positions)]);
    }

    /**
     * The positions belonging to one client company, shown in its workspace.
     */
    public function forCompany(ClientCompany $client): JsonResponse
    {
        $this->authorize('view', $client);

        $positions = $client->positions()
            ->withCount([
                'jobRequests as open_request_count' => fn ($q) => $q->open(),
                'applicants as applicant_count',
            ])
            ->orderBy('position_title')
            ->get();

        return ApiResponse::success([
            'positions' => $this->present($positions),
        ]);
    }

    public function store(Request $request, ClientCompany $client): JsonResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'position_title' => [
                'required', 'string', 'max:150',
                // Scoped to this company. Two clients may both hire welders, and
                // refusing the second would be wrong.
                Rule::unique('job_positions', 'position_title')
                    ->where('client_company_id', $client->id)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ], [
            'position_title.unique' => 'This company already has a position with that title.',
        ]);

        $position = JobPosition::create([
            'position_code' => $this->codes->position(),
            'client_company_id' => $client->id,
            'position_title' => $data['position_title'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record(
            action: 'create',
            module: 'clients',
            recordType: JobPosition::class,
            recordId: $position->id,
            newValues: [
                'company' => $client->company_name,
                'position_title' => $position->position_title,
                'status' => $position->status,
            ],
        );

        return ApiResponse::created(
            $this->present(collect([$position]))->first(),
            'Position added. Applicants can now choose it.'
        );
    }

    public function update(Request $request, ClientCompany $client, JobPosition $position): JsonResponse
    {
        $this->authorize('update', $client);

        abort_if(
            (int) $position->client_company_id !== (int) $client->id,
            404,
            'That position does not belong to this company.'
        );

        $data = $request->validate([
            'position_title' => [
                'sometimes', 'required', 'string', 'max:150',
                Rule::unique('job_positions', 'position_title')
                    ->where('client_company_id', $client->id)
                    ->whereNull('deleted_at')
                    ->ignore($position->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ], [
            'position_title.unique' => 'This company already has a position with that title.',
        ]);

        $before = $position->only(['position_title', 'description', 'status']);
        $position->update($data);

        $this->audit->recordUpdate('clients', JobPosition::class, $position->id, $before, $data);

        return ApiResponse::success(
            $this->present(collect([$position->refresh()]))->first(),
            ($data['status'] ?? $position->status) === 'inactive'
                ? 'Position withdrawn. It no longer appears on the application form.'
                : 'Position updated'
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, JobPosition>  $positions
     */
    private function present($positions)
    {
        return $positions->map(fn (JobPosition $position) => [
            'id' => $position->id,
            'position_code' => $position->position_code,
            'client_company_id' => $position->client_company_id,
            'company' => $position->relationLoaded('company') ? $position->company?->company_name : null,
            'position_title' => $position->position_title,
            'description' => $position->description,
            'status' => $position->status,
            'open_request_count' => $position->open_request_count ?? null,
            'applicant_count' => $position->applicant_count ?? null,
        ])->values();
    }
}
