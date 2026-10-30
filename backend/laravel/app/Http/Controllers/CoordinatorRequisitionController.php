<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CoordinatorScoped;
use App\Http\Resources\ManpowerRequisitionResource;
use App\Http\Responses\ApiResponse;
use App\Models\ClientDepartment;
use App\Models\JobPosition;
use App\Models\ManpowerRequisition;
use App\Services\DocumentStorageService;
use App\Services\ManpowerRequisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The coordinator's manpower requisitions for their assigned client, written
 * on CDE's own Manpower Requisition Form (received 29 October 2026).
 *
 * Requisitions filed earlier on the temporary default form still open here;
 * their attachments, a field CDE's form does not have, can still be read.
 */
class CoordinatorRequisitionController extends Controller
{
    use CoordinatorScoped;

    public function __construct(
        private readonly ManpowerRequisitionService $requisitions,
        private readonly DocumentStorageService $storage,
    ) {
    }

    /** The form: its version, the Reason of Request boxes, and the client's lists. */
    public function options(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $form = config('empower.requisition_form');

        return ApiResponse::success([
            'form' => [
                'provisional' => false,
                'version' => $form['version'],
                'title' => $form['title'],
                'reasons' => collect($form['reasons'])->map(fn ($label, $value) => compact('value', 'label'))->values(),
            ],
            'departments' => ClientDepartment::query()
                ->where('client_company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('department_name')
                ->get(['id', 'department_name', 'department_code']),
            'positions' => JobPosition::query()
                ->where('status', 'active')
                ->where(fn ($q) => $q->where('client_company_id', $companyId)->orWhereNull('client_company_id'))
                ->orderBy('position_title')
                ->get(['id', 'position_title']),
            // The group first: the dashboard figure counts it, and opens
            // the list on it.
            'statuses' => collect(['pending' => 'Awaiting HR'] + ManpowerRequisition::LABELS)
                ->map(fn ($label, $value) => compact('value', 'label'))->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            // "pending" is every status still waiting on the office, as on
            // HR's own list.
            'status' => ['nullable', Rule::in([...ManpowerRequisition::STATUSES, 'pending'])],
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $requisitions = ManpowerRequisition::forCompany($this->companyId($request))
            ->with(['department', 'requester', 'jobRequest'])
            // Another coordinator's drafts are their own unfinished work.
            ->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhere('requested_by', $request->user()->id))
            ->when($filters['status'] ?? null, fn ($q, $v) => $v === 'pending'
                ? $q->whereIn('status', ManpowerRequisition::PENDING_REVIEW)
                : $q->where('status', $v))
            ->when($filters['mine'] ?? false, fn ($q) => $q->where('requested_by', $request->user()->id))
            ->latest()
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(ManpowerRequisitionResource::collection($requisitions), 'Requisitions retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(required: true), $this->messages());

        $requisition = $this->requisitions->create(
            $this->assignment($request),
            $request->user(),
            collect($data)->except('submit')->all()
        );

        if ($request->boolean('submit')) {
            $requisition = $this->requisitions->submit($requisition, $request->user());
        }

        return ApiResponse::created(
            new ManpowerRequisitionResource($this->loaded($requisition)),
            $requisition->status === 'submitted' ? 'Requisition submitted to HR' : 'Draft saved'
        );
    }

    public function show(Request $request, ManpowerRequisition $requisition): JsonResponse
    {
        $this->authorizeRequisition($request, $requisition);

        return ApiResponse::success(new ManpowerRequisitionResource($this->loaded($requisition)));
    }

    public function update(Request $request, ManpowerRequisition $requisition): JsonResponse
    {
        $this->authorizeRequisition($request, $requisition);

        $data = $request->validate($this->rules(required: false), $this->messages());

        $requisition = $this->requisitions->update(
            $requisition,
            $this->assignment($request),
            $request->user(),
            collect($data)->except('submit')->all()
        );

        if ($request->boolean('submit')) {
            $requisition = $this->requisitions->submit($requisition, $request->user());
        }

        return ApiResponse::success(
            new ManpowerRequisitionResource($this->loaded($requisition)),
            $requisition->status === 'submitted' ? 'Requisition submitted to HR' : 'Requisition saved'
        );
    }

    public function submit(Request $request, ManpowerRequisition $requisition): JsonResponse
    {
        $this->authorizeRequisition($request, $requisition);

        $requisition = $this->requisitions->submit($requisition, $request->user());

        return ApiResponse::success(new ManpowerRequisitionResource($this->loaded($requisition)), 'Requisition submitted to HR');
    }

    public function destroy(Request $request, ManpowerRequisition $requisition): JsonResponse
    {
        $this->authorizeRequisition($request, $requisition);

        $this->requisitions->discard($requisition, $request->user());

        return ApiResponse::success(null, 'Draft discarded');
    }

    public function attachment(Request $request, ManpowerRequisition $requisition): JsonResponse
    {
        $this->authorizeRequisition($request, $requisition);

        if (blank($requisition->attachment_path)) {
            return ApiResponse::error('No attachment was added to this requisition.', 404);
        }

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrl($requisition->attachment_path),
            'file_name' => $requisition->attachment_name,
        ]);
    }

    /**
     * CDE's form. Only these keys are validated, so only these can be written;
     * anything else sent - including the temporary form's old fields - is
     * dropped.
     */
    private function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'date_requested' => [$presence, 'date', 'before_or_equal:today'],
            'required_by' => [$presence, 'date'],

            // Total Manpower Needed: how many, how many of them men and
            // women, and for what position.
            'workers_needed' => [$presence, 'integer', 'min:1', 'max:1000'],
            'male_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'female_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'job_position_id' => ['nullable', 'integer'],
            'position_title' => [$required ? 'required_without:job_position_id' : 'sometimes', 'nullable', 'string', 'max:150'],

            'qualifications' => ['nullable', 'string', 'max:2000'],
            'other_remarks' => ['nullable', 'string', 'max:2000'],

            'request_reason' => [$presence, Rule::in(array_keys(config('empower.requisition_form.reasons')))],
            'request_reason_details' => ['nullable', 'required_if:request_reason,others', 'string', 'max:190'],

            'client_department_id' => ['nullable', 'integer'],
            'submit' => ['nullable', 'boolean'],
        ];
    }

    /** Errors in the form's own words. */
    private function messages(): array
    {
        return [
            'date_requested.required' => 'Enter the Date requested.',
            'date_requested.before_or_equal' => 'The Date requested cannot be in the future.',
            'required_by.required' => 'Enter the Date Needed.',
            'workers_needed.required' => 'Enter the Total Manpower Needed.',
            'workers_needed.min' => 'The Total Manpower Needed must be at least 1.',
            'position_title.required_without' => 'Say which position the manpower is for.',
            'request_reason.required' => 'Tick the Reason of Request.',
            'request_reason.in' => 'Tick one of the reasons listed on the form.',
            'request_reason_details.required_if' => 'Write the reason beside "Others".',
        ];
    }

    /**
     * A requisition is visible to the coordinators of the client it was made
     * for, and a draft only to the coordinator writing it.
     */
    private function authorizeRequisition(Request $request, ManpowerRequisition $requisition): void
    {
        $this->abortUnlessInScope($request, $requisition->client_company_id, 'requisition');

        abort_if(
            $requisition->status === 'draft' && (int) $requisition->requested_by !== (int) $request->user()->id,
            403,
            'This draft belongs to another coordinator.'
        );
    }

    private function loaded(ManpowerRequisition $requisition): ManpowerRequisition
    {
        return $requisition->load(['company', 'department', 'requester', 'reviewer', 'jobRequest', 'history.changedBy']);
    }
}
