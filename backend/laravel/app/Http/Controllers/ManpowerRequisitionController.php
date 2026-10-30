<?php

namespace App\Http\Controllers;

use App\Http\Resources\ManpowerRequisitionResource;
use App\Http\Responses\ApiResponse;
use App\Models\ManpowerRequisition;
use App\Services\DocumentStorageService;
use App\Services\ManpowerRequisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HR's side of coordinators' manpower requisitions: read them, and decide.
 *
 * Approving one does not create a manpower request by itself. HR records the
 * request through the ordinary screen, which links it here - so every request
 * still passes the same validation, criteria and status rules (R-16) whichever
 * way it arrived.
 */
class ManpowerRequisitionController extends Controller
{
    public function __construct(
        private readonly ManpowerRequisitionService $requisitions,
        private readonly DocumentStorageService $storage,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ManpowerRequisition::class);

        $filters = $request->validate([
            // "pending" is the default view: what is waiting on HR.
            'status' => ['nullable', Rule::in([...ManpowerRequisition::STATUSES, 'pending', 'all'])],
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $status = $filters['status'] ?? 'pending';

        $requisitions = ManpowerRequisition::query()
            ->with(['company', 'department', 'requester', 'jobRequest'])
            // A coordinator's draft is not sent yet, so HR never sees it.
            ->where('status', '!=', 'draft')
            ->when($status === 'pending', fn ($q) => $q->whereIn('status', ManpowerRequisition::PENDING_REVIEW))
            ->when(! in_array($status, ['pending', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub
                ->whereContains('requisition_code', $v)
                ->orWhereContains('position_title', $v)))
            ->orderByRaw("CASE WHEN status IN ('submitted', 'under_review') THEN 0 ELSE 1 END")
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(ManpowerRequisitionResource::collection($requisitions), 'Requisitions retrieved');
    }

    public function show(ManpowerRequisition $requisition): JsonResponse
    {
        $this->authorize('view', $requisition);

        return ApiResponse::success(new ManpowerRequisitionResource($this->loaded($requisition)));
    }

    public function review(Request $request, ManpowerRequisition $requisition): JsonResponse
    {
        $this->authorize('review', $requisition);

        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(ManpowerRequisition::REVIEW_ACTIONS))],
            // Returning or rejecting has to say why, so the coordinator - and
            // later readers - know what was wrong with it.
            'note' => ['nullable', 'required_if:action,return,reject', 'string', 'max:500'],
        ], [
            'note.required_if' => 'Say why, so the coordinator knows what to do next.',
        ]);

        $requisition = $this->requisitions->review($requisition, $request->user(), $data['action'], $data['note'] ?? null);

        return ApiResponse::success(new ManpowerRequisitionResource($this->loaded($requisition)), match ($requisition->status) {
            'under_review' => 'Marked as under review',
            'returned' => 'Returned to the coordinator for correction',
            'approved' => 'Requisition approved. Record it as a manpower request to start recruiting.',
            'rejected' => 'Requisition rejected',
            'fulfilled' => 'Requisition marked fulfilled',
            default => 'Requisition updated',
        });
    }

    public function attachment(ManpowerRequisition $requisition): JsonResponse
    {
        $this->authorize('view', $requisition);

        if (blank($requisition->attachment_path)) {
            return ApiResponse::error('No attachment was added to this requisition.', 404);
        }

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrl($requisition->attachment_path),
            'file_name' => $requisition->attachment_name,
        ]);
    }

    private function loaded(ManpowerRequisition $requisition): ManpowerRequisition
    {
        return $requisition->load(['company', 'department', 'requester', 'reviewer', 'jobRequest', 'history.changedBy']);
    }
}
