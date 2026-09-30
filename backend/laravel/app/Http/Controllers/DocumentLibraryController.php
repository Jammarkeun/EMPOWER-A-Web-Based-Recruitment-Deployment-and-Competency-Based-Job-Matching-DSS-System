<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\RequirementType;
use App\Services\DocumentReviewService;
use App\Services\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Every uploaded document, grouped by what kind of document it is.
 *
 * This is a different idea from the Folder 1/2/3 filing, and the two are
 * deliberately kept apart. Folders describe how complete one applicant's paperwork
 * is; this describes where a particular kind of paper lives across everybody. HR
 * reach for this when the question is "show me the medical certificates", which
 * previously meant opening applicants one at a time until you found them.
 *
 * Only documents that were actually uploaded appear here. A requirement verified
 * at the counter with no file has nothing to show, and saying so plainly is
 * better than an empty row that looks like a fault.
 */
class DocumentLibraryController extends Controller
{
    public function __construct(private readonly DocumentStorageService $storage)
    {
    }

    /**
     * The folders, with a count of what is in each.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Applicant::class);

        $filters = $request->validate([
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
        ]);

        $counts = $this->scopedQuery($filters)
            ->selectRaw('requirement_type_id, COUNT(*) AS total')
            ->groupBy('requirement_type_id')
            ->pluck('total', 'requirement_type_id');

        $types = RequirementType::active()->orderBy('display_order')->get();

        return ApiResponse::success([
            'folders' => $types->map(fn (RequirementType $type) => [
                'requirement_type_id' => $type->id,
                'name' => $type->requirement_name,
                'group' => $type->requirement_group,
                'file_count' => (int) ($counts[$type->id] ?? 0),
            ])->values(),

            'total_files' => (int) $counts->sum(),
        ]);
    }

    /**
     * What is inside one folder.
     */
    public function show(Request $request, RequirementType $requirementType): JsonResponse
    {
        $this->authorize('viewAny', Applicant::class);

        $filters = $request->validate([
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'status' => ['nullable', Rule::in(['verified', 'submitted', 'rejected', 'needs_correction', 'expired'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $files = $this->scopedQuery($filters)
            ->with(['applicant', 'verifier'])
            ->where('requirement_type_id', $requirementType->id)
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('submitted_at')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(
            $files->through(fn (ApplicantRequirement $r) => [
                'id' => $r->id,
                'requirement_type_id' => $r->requirement_type_id,
                'file_name' => $r->file_name,
                'file_size_bytes' => $r->file_size_bytes,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'status' => $r->status,
                'review_state' => $r->reviewState(),
                'verified_by' => $r->verifier?->full_name,
                'expiry_date' => $r->expiry_date?->toDateString(),

                // Whose document this is, so a file is never an orphan.
                'applicant' => [
                    'id' => $r->applicant?->id,
                    'name' => $r->applicant?->full_name,
                    'applicant_code' => $r->applicant?->applicant_code,
                ],
            ]),
            $requirementType->requirement_name
        );
    }

    /**
     * A short-lived link to one file.
     *
     * The storage key is never exposed; the link is minted per request and
     * expires within minutes, so a URL copied out of the page cannot be replayed
     * later or shared onward.
     */
    public function download(
        Request $request,
        ApplicantRequirement $requirement,
        DocumentReviewService $review,
    ): JsonResponse {
        $this->authorize('viewAny', Applicant::class);

        if (! $requirement->file_path) {
            return ApiResponse::error('That requirement has no uploaded file.', 404);
        }

        // Opening a document here counts exactly as it does on the applicant's
        // own record. The applicant is told their document is being checked
        // because somebody opened it, and it cannot matter which screen they
        // opened it from.
        $review->recordStaffView($requirement, $request->user());

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrl($requirement->file_path),
            'file_name' => $requirement->file_name,
            'review_state' => $requirement->fresh()->reviewState(),
        ]);
    }

    /**
     * Uploaded documents only, optionally narrowed to one client company.
     *
     * An applicant reaches a company through their deployment, since an
     * applicant belongs to the agency's pool rather than to a single client. The
     * filter therefore means "documents of people currently placed with this
     * client", which is the question someone on a company page is actually
     * asking.
     */
    private function scopedQuery(array $filters)
    {
        return ApplicantRequirement::query()
            ->whereNotNull('file_path')
            ->when(
                $filters['client_company_id'] ?? null,
                fn ($q, $companyId) => $q->whereHas(
                    'applicant.employee.deployments',
                    fn ($d) => $d->where('client_company_id', $companyId)
                )
            );
    }
}
