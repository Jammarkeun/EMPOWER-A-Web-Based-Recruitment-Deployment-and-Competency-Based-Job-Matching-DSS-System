<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\RequirementType;
use App\Services\DocumentReviewService;
use App\Services\DocumentStorageService;
use App\Services\ProfilePhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The document library: by applicant first, and by kind of document second.
 *
 * The main view starts from the person. HR open an applicant's name and see
 * every document that belongs to them in one place - which is how the agency
 * already thinks about paperwork, one applicant's folder at a time, and what
 * the review panel asked for. Listing files by document type first left the
 * officer reading names off a list of medical certificates and guessing whose
 * was whose.
 *
 * The by-type view is kept as a secondary one for the other question HR ask -
 * "show me every medical certificate that expires this month" - which is
 * genuinely about the document rather than the person.
 *
 * Both are built from the applicant relationship on the server; nothing here
 * relies on the screen filtering a larger list.
 */
class DocumentLibraryController extends Controller
{
    public function __construct(private readonly DocumentStorageService $storage)
    {
    }

    /**
     * Applicants, each with a summary of their documents.
     *
     * One row per person, so the list reads as a set of named folders. The
     * counts say what needs doing without opening anyone: how much they have
     * sent, how much is accepted, and how much is waiting for somebody to look
     * at it. Those waiting come first, because that is the work - and ahead of
     * them, anyone with an upload no member of staff has opened yet, newest
     * first, so a document sent through the portal is seen the day it arrives.
     *
     * The documents themselves are read through the applicant's own
     * requirements endpoint, which authorises against the applicant record.
     */
    public function applicants(Request $request, ProfilePhotoService $photos): JsonResponse
    {
        $this->authorize('viewDocuments');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'needs_review' => ['nullable', 'boolean'],
            'new_uploads' => ['nullable', 'boolean'],
            // By default only people with something on file: a registration
            // with nothing submitted yet is an empty folder.
            'include_empty' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        // "Sent" includes a digital Bio-Data, which has no file (sentIn).
        $uploaded = fn ($q) => $q->sentIn();
        $awaiting = fn ($q) => $q->awaitingReview();
        $onFile = fn ($q) => $q->where(fn ($inner) => $inner->sentIn()->orWhere('status', 'verified'));
        // Sent and not yet opened by anyone. A replacement upload clears the
        // view record, so it counts as new again.
        $unopened = fn ($q) => $q
            ->where(fn ($sent) => $sent
                ->where(fn ($upload) => $upload->sentIn()->where('status', 'submitted'))
                ->orWhere(fn ($form) => $form->pendingDigitalForm()))
            ->whereNull('first_viewed_at');

        $applicants = Applicant::query()
            ->with(['employee:id,applicant_id,employee_number,employment_status'])
            ->search($filters['search'] ?? null)
            ->withCount([
                'requirements as checklist_count',
                'requirements as uploaded_count' => $uploaded,
                'requirements as verified_count' => fn ($q) => $q->where('status', 'verified'),
                'requirements as awaiting_review_count' => $awaiting,
                'requirements as needs_attention_count' => fn ($q) => $q->whereIn('status', ['rejected', 'needs_correction', 'expired']),
                'requirements as unopened_count' => $unopened,
            ])
            ->withExists(['requirements as has_unopened' => $unopened])
            ->withMax('requirements as last_submitted_at', 'submitted_at')
            ->withMax(['requirements as newest_unopened_at' => $unopened], 'submitted_at')
            ->unless($filters['include_empty'] ?? false, fn ($q) => $q->whereHas('requirements', $onFile))
            ->when($filters['needs_review'] ?? false, fn ($q) => $q->whereHas('requirements', $awaiting))
            ->when($filters['new_uploads'] ?? false, fn ($q) => $q->whereHas('requirements', $unopened))
            ->when(
                $filters['client_company_id'] ?? null,
                fn ($q, $companyId) => $q->whereHas(
                    'employee.deployments',
                    fn ($d) => $d->where('client_company_id', $companyId)
                )
            )
            // Bare aliases only: PostgreSQL (Supabase) will not resolve an
            // alias inside an ORDER BY expression. has_unopened is what keeps
            // the NULL dates of everyone else from sorting first there.
            ->orderByDesc('has_unopened')
            ->orderByDesc('newest_unopened_at')
            ->orderByDesc('awaiting_review_count')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(
            $applicants->through(fn (Applicant $a) => [
                'id' => $a->id,
                'full_name' => $a->full_name,
                'photo_url' => $photos->url($a->profile_photo_path),
                'applicant_code' => $a->applicant_code,
                'current_status' => $a->current_status,
                'folder_category' => $a->folder_category,
                'preferred_position' => $a->preferred_position,
                'employee_number' => $a->employee?->employee_number,
                'documents' => [
                    'checklist' => (int) $a->checklist_count,
                    'uploaded' => (int) $a->uploaded_count,
                    'verified' => (int) $a->verified_count,
                    'awaiting_review' => (int) $a->awaiting_review_count,
                    'needs_attention' => (int) $a->needs_attention_count,
                    'unopened' => (int) $a->unopened_count,
                    'last_submitted_at' => $a->last_submitted_at
                        ? \Illuminate\Support\Carbon::parse($a->last_submitted_at)->toIso8601String()
                        : null,
                    'newest_unopened_at' => $a->newest_unopened_at
                        ? \Illuminate\Support\Carbon::parse($a->newest_unopened_at)->toIso8601String()
                        : null,
                ],
            ]),
            'Applicants retrieved'
        );
    }

    /**
     * The folders, with a count of what is in each.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewDocuments');

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
    public function show(Request $request, RequirementType $requirementType, ProfilePhotoService $photos): JsonResponse
    {
        $this->authorize('viewDocuments');

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
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated(
            $files->through(fn (ApplicantRequirement $r) => [
                'id' => $r->id,
                'requirement_type_id' => $r->requirement_type_id,
                'file_name' => $r->file_name,
                'file_size_bytes' => $r->file_size_bytes,
                'submitted_via' => $r->submitted_via,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'status' => $r->status,
                'review_state' => $r->reviewState(),
                'verified_by' => $r->verifier?->full_name,
                'expiry_date' => $r->expiry_date?->toDateString(),

                // Whose document this is, so a file is never an orphan.
                'applicant' => [
                    'id' => $r->applicant?->id,
                    'name' => $r->applicant?->full_name,
                    // The applicant's own picture, so the reviewer can see whose
                    // document this is. Never the document itself.
                    'photo_url' => $photos->url($r->applicant?->profile_photo_path),
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
        $this->authorize('viewDocuments');

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
     * Documents sent in - uploaded files and digital Bio-Data forms - optionally
     * narrowed to one client company.
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
            ->sentIn()
            ->when(
                $filters['client_company_id'] ?? null,
                fn ($q, $companyId) => $q->whereHas(
                    'applicant.employee.deployments',
                    fn ($d) => $d->where('client_company_id', $companyId)
                )
            );
    }
}
