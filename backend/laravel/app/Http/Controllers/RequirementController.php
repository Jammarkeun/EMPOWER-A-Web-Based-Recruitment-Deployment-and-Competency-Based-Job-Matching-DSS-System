<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApplicantRequirementResource;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ApplicantRequirement;
use App\Models\RequirementType;
use App\Notifications\RequirementReviewed;
use App\Services\ApplicantLifecycleService;
use App\Services\AuditService;
use App\Services\DocumentReviewService;
use App\Services\DocumentStorageService;
use App\Services\FolderCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RequirementController extends Controller
{
    public function __construct(
        private readonly DocumentStorageService $storage,
        private readonly FolderCategoryService $folders,
        private readonly ApplicantLifecycleService $lifecycle,
        private readonly AuditService $audit,
    ) {
    }

    public function types(): JsonResponse
    {
        return ApiResponse::success(
            RequirementType::active()->orderBy('display_order')->get()
        );
    }

    public function index(Applicant $applicant): JsonResponse
    {
        $this->authorize('view', $applicant);

        $applicant->load([
            'requirements.requirementType',
            'requirements.verifier',
            'requirements.firstViewer',
        ]);

        return ApiResponse::success([
            'requirements' => ApplicantRequirementResource::collection($applicant->requirements),
            'folder' => $this->folders->explain($applicant),
        ]);
    }

    /**
     * Verify, reject, or otherwise update the state of a submitted document.
     *
     * Verification and folder recalculation happen in one transaction: an
     * applicant whose documents are complete but whose folder still says
     * otherwise would be invisible to the deployment-ready list.
     */
    public function updateStatus(Request $request, Applicant $applicant, RequirementType $requirementType): JsonResponse
    {
        $this->authorize('verifyRequirement', $applicant);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                'submitted', 'pending', 'verified', 'rejected', 'needs_correction', 'expired', 'missing',
            ])],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            // How the document was checked. Optional: when it is not given the
            // system infers it below, so existing callers keep working.
            'verification_method' => ['nullable', Rule::in(['online_upload', 'walk_in', 'other'])],
            'verification_note' => ['nullable', 'string', 'max:255'],
            'expiry_override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         * The checklist row is created here when it does not already exist.
         *
         * Rows are normally created alongside the applicant, but not always: an
         * administrator who adds a new requirement type from the settings screen
         * creates it for future applicants only, so everyone already on file has
         * no row for it. Failing outright meant that document could never be
         * verified for those applicants, behind a 404 that explained nothing.
         */
        $requirement = ApplicantRequirement::firstOrNew([
            'applicant_id' => $applicant->id,
            'requirement_type_id' => $requirementType->id,
        ]);

        $this->assertVerificationLifecycle($requirementType, $requirement, $data);

        /*
         * A missing file no longer blocks verification.
         *
         * Most applicants walk in and hand their originals across the counter.
         * Requiring a stored file before the system would accept a verification
         * meant those applicants stayed permanently incomplete, and the only
         * workaround was for staff to scan papers purely to satisfy the
         * software. Whether a file exists and whether a person has checked the
         * document are two different facts, and the record now keeps them apart:
         * file_path answers the first, this status and the method answer the
         * second.
         */
        $before = $requirement->only(['status', 'expiry_date', 'rejection_reason', 'verification_method']);

        DB::transaction(function () use ($requirement, $data, $request, $applicant) {
            $isVerified = $data['status'] === 'verified';

            $requirement->fill([
                'status' => $data['status'],
                'rejection_reason' => $data['status'] === 'rejected' ? $data['rejection_reason'] : null,
                'expiry_date' => $data['expiry_date'] ?? $requirement->expiry_date,
                'remarks' => $data['remarks'] ?? $requirement->remarks,
                'verified_at' => $isVerified ? now() : null,
                'verified_by' => $isVerified ? $request->user()->id : null,
                // Inferred when the caller does not say: a stored file means the
                // officer was looking at an upload, no file means they were
                // looking at the original in their hand.
                'verification_method' => $isVerified
                    ? ($data['verification_method']
                        ?? ($requirement->file_path ? 'online_upload' : 'walk_in'))
                    : null,
                'verification_note' => $isVerified
                    ? ($data['verification_note'] ?? $data['expiry_override_reason'] ?? null)
                    : null,
            ])->save();

            $applicant->unsetRelation('requirements');
            $this->folders->recalculate($applicant);
        });

        $this->audit->recordUpdate(
            'requirements',
            ApplicantRequirement::class,
            $requirement->id,
            $before,
            $data
        );

        // Tell the applicant the outcome. A rejection carries the reason, which
        // is what saves them a wasted return trip to the office.
        if (in_array($data['status'], ['verified', 'rejected'], true)) {
            $applicant->notify(new RequirementReviewed(
                $requirement->fresh()->load('requirementType'),
                $data['status']
            ));
        }

        // Advances the applicant automatically when their documents now allow
        // it, so a completed file does not sit unnoticed waiting for someone to
        // remember to move it forward.
        $applicant->unsetRelation('requirements');
        $this->lifecycle->syncStatusToDocuments($applicant->fresh(), $request->user());

        return ApiResponse::success([
            'requirement' => new ApplicantRequirementResource($requirement->fresh()->load('requirementType')),
            'folder' => $this->folders->explain($applicant->fresh()),
            'applicant_status' => $applicant->fresh()->current_status,
        ], 'Requirement updated');
    }

    /**
     * Issues a short-lived download link for a stored document.
     *
     * Opening the document is also what starts its review. Until an officer
     * actually looks at the file the applicant is told their upload was
     * received and no more than that, because that is all that has happened.
     */
    public function download(
        Request $request,
        Applicant $applicant,
        RequirementType $requirementType,
        DocumentReviewService $review,
    ): JsonResponse {
        $this->authorize('view', $applicant);

        $requirement = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $requirementType->id)
            ->firstOrFail();

        if (blank($requirement->file_path)) {
            return ApiResponse::error('No document has been uploaded for this requirement.', 404);
        }

        $minutes = config('empower.uploads.signed_url_ttl_minutes', 10);

        $review->recordStaffView($requirement, $request->user());

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrl($requirement->file_path, $minutes),
            'file_name' => $requirement->file_name,
            'expires_in_minutes' => $minutes,

            // Returned so the screen that opened the document can update the row
            // in place. Without it the officer would have to reload the page to
            // see that the document has moved into review, and a reload is
            // exactly what loses their place in a long checklist.
            'requirement' => new ApplicantRequirementResource(
                $requirement->fresh()->load('requirementType', 'verifier', 'firstViewer')
            ),
        ]);
    }

    /**
     * Verify several requirements at once.
     *
     * The counter case this exists for: an applicant hands over a folder, the
     * officer checks the papers together, and every one of them is in order.
     * Approving them one at a time meant a dialog per document, and with
     * eighteen requirements per applicant that is most of the working day.
     *
     * Deliberately verify-only. Rejecting a document needs a reason written
     * against that specific document, and a bulk reject would either lose that
     * reason or apply one sentence to several unrelated papers - so rejection
     * stays a single, considered action.
     */
    public function verifyBatch(Request $request, Applicant $applicant): JsonResponse
    {
        $this->authorize('verifyRequirement', $applicant);

        $data = $request->validate([
            'requirement_type_ids' => ['required', 'array', 'min:1'],
            'requirement_type_ids.*' => ['integer', 'exists:requirement_types,id'],
            'verification_method' => ['nullable', Rule::in(['online_upload', 'walk_in', 'other'])],
            'verification_note' => ['nullable', 'string', 'max:255'],
            'expiry_override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        // Any requested type without a checklist row gets one, for the same
        // reason as above.
        $requirements = collect($data['requirement_type_ids'])
            ->unique()
            ->map(fn (int $typeId) => ApplicantRequirement::firstOrNew([
                'applicant_id' => $applicant->id,
                'requirement_type_id' => $typeId,
            ]))
            ->each(fn (ApplicantRequirement $r) => $r->loadMissing('requirementType'));

        foreach ($requirements as $requirement) {
            $this->assertVerificationLifecycle(
                $requirement->requirementType,
                $requirement,
                [...$data, 'status' => 'verified']
            );
        }

        $verified = [];

        DB::transaction(function () use ($requirements, $data, $request, $applicant, &$verified) {
            foreach ($requirements as $requirement) {
                // Already verified and still valid: skip rather than rewrite the
                // timestamp, so the record keeps who checked it first.
                if ($requirement->countsAsComplete()) {
                    continue;
                }

                $requirement->fill([
                    'status' => 'verified',
                    'rejection_reason' => null,
                    'verified_at' => now(),
                    'verified_by' => $request->user()->id,
                    'verification_method' => $data['verification_method']
                        ?? ($requirement->file_path ? 'online_upload' : 'walk_in'),
                    'verification_note' => $data['verification_note']
                        ?? $data['expiry_override_reason']
                        ?? null,
                ])->save();

                $verified[] = $requirement->requirementType?->requirement_name;
            }

            $applicant->unsetRelation('requirements');
            $this->folders->recalculate($applicant);
        });

        $this->audit->record(
            action: 'update',
            module: 'requirements',
            recordType: Applicant::class,
            recordId: $applicant->id,
            newValues: [
                'bulk_verified' => $verified,
                'count' => count($verified),
                'method' => $data['verification_method'] ?? 'inferred per document',
            ],
        );

        // One notification for the batch, not one per document. Eight separate
        // alerts saying the same thing is not eight times as informative.
        if ($verified !== []) {
            $applicant->unsetRelation('requirements');
            $this->lifecycle->syncStatusToDocuments($applicant->fresh(), $request->user());
        }

        $fresh = $applicant->fresh()->load('requirements.requirementType', 'requirements.verifier');

        return ApiResponse::success([
            'requirements' => ApplicantRequirementResource::collection($fresh->requirements),
            'folder' => $this->folders->explain($fresh),
            'applicant_status' => $fresh->current_status,
            'verified_count' => count($verified),
        ], count($verified) === 1
            ? '1 document verified'
            : count($verified).' documents verified');
    }

    /**
     * Inactive types remain readable for historical records, but cannot create
     * new checklist rows. Expiring requirements must have a current expiry;
     * staff may override that rule only with an explicit reason.
     */
    private function assertVerificationLifecycle(
        RequirementType $type,
        ApplicantRequirement $requirement,
        array $data,
    ): void {
        if (! $type->active_flag && ! $requirement->exists) {
            throw ValidationException::withMessages([
                'requirement_type' => ['Inactive requirement types cannot be verified for new checklist rows.'],
            ]);
        }

        if (($data['status'] ?? null) !== 'verified' || ! $type->has_expiry) {
            return;
        }

        $expiry = $data['expiry_date'] ?? $requirement->expiry_date;
        $overrideReason = trim((string) ($data['expiry_override_reason'] ?? ''));

        if (! $expiry) {
            throw ValidationException::withMessages([
                'expiry_date' => ['This requirement must have an expiry date before it can be verified.'],
            ]);
        }

        if ($expiry < now()->toDateString() && $overrideReason === '') {
            throw ValidationException::withMessages([
                'expiry_override_reason' => ['A past expiry date requires a reason before verification.'],
            ]);
        }
    }

}
