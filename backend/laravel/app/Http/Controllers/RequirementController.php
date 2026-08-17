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
use App\Services\DocumentStorageService;
use App\Services\FolderCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

        $applicant->load(['requirements.requirementType', 'requirements.verifier']);

        return ApiResponse::success([
            'requirements' => ApplicantRequirementResource::collection($applicant->requirements),
            'folder' => $this->folders->explain($applicant),
        ]);
    }

    /**
     * Upload or replace a requirement document.
     */
    public function upload(Request $request, Applicant $applicant, RequirementType $requirementType): JsonResponse
    {
        $this->authorize('uploadRequirement', $applicant);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.config('empower.uploads.max_size_kb'),
                'mimes:'.implode(',', config('empower.uploads.allowed_mimes')),
            ],
            'expiry_date' => [
                // Documents that expire must say when. A police clearance with
                // no expiry recorded would silently count as valid forever.
                $requirementType->has_expiry ? 'required' : 'nullable',
                'date',
                'after:today',
            ],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $requirement = ApplicantRequirement::firstOrNew([
            'applicant_id' => $applicant->id,
            'requirement_type_id' => $requirementType->id,
        ]);

        $previousKey = $requirement->file_path;

        $stored = $this->storage->store($request->file('file'), 'requirements', $applicant->id);

        DB::transaction(function () use ($requirement, $stored, $request, $requirementType) {
            $requirement->fill(array_merge($stored, [
                // A re-uploaded document returns to "submitted": replacing the
                // file invalidates any previous verification.
                'status' => 'submitted',
                'submitted_at' => now(),
                'verified_at' => null,
                'verified_by' => null,
                'rejection_reason' => null,
                'expiry_date' => $request->input('expiry_date'),
                'remarks' => $request->input('remarks'),
            ]))->save();

            $this->audit->record(
                action: 'create',
                module: 'requirements',
                recordType: ApplicantRequirement::class,
                recordId: $requirement->id,
                newValues: [
                    'requirement' => $requirementType->requirement_name,
                    'file_name' => $stored['file_name'],
                ],
            );
        });

        // Only removed once the replacement is safely recorded, so a failure
        // mid-way never leaves the applicant with no document at all.
        if ($previousKey && $previousKey !== $requirement->file_path) {
            $this->storage->delete($previousKey);
        }

        $applicant->unsetRelation('requirements');
        $this->folders->recalculate($applicant);

        return ApiResponse::success(
            new ApplicantRequirementResource($requirement->fresh()->load('requirementType')),
            'Document uploaded'
        );
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
            'status' => ['required', Rule::in(['submitted', 'pending', 'verified', 'rejected', 'expired', 'missing'])],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $requirement = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $requirementType->id)
            ->firstOrFail();

        if ($data['status'] === 'verified' && is_null($requirement->file_path)) {
            return ApiResponse::error('A document must be uploaded before it can be verified.', 400);
        }

        $before = $requirement->only(['status', 'expiry_date', 'rejection_reason']);

        DB::transaction(function () use ($requirement, $data, $request, $applicant) {
            $requirement->fill([
                'status' => $data['status'],
                'rejection_reason' => $data['status'] === 'rejected' ? $data['rejection_reason'] : null,
                'expiry_date' => $data['expiry_date'] ?? $requirement->expiry_date,
                'remarks' => $data['remarks'] ?? $requirement->remarks,
                'verified_at' => $data['status'] === 'verified' ? now() : null,
                'verified_by' => $data['status'] === 'verified' ? $request->user()->id : null,
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
     */
    public function download(Applicant $applicant, RequirementType $requirementType): JsonResponse
    {
        $this->authorize('view', $applicant);

        $requirement = ApplicantRequirement::where('applicant_id', $applicant->id)
            ->where('requirement_type_id', $requirementType->id)
            ->firstOrFail();

        if (blank($requirement->file_path)) {
            return ApiResponse::error('No document has been uploaded for this requirement.', 404);
        }

        $minutes = config('empower.uploads.signed_url_ttl_minutes', 10);

        $this->audit->record(
            action: 'view',
            module: 'requirements',
            recordType: ApplicantRequirement::class,
            recordId: $requirement->id,
            newValues: ['document' => $requirement->file_name],
        );

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrl($requirement->file_path, $minutes),
            'file_name' => $requirement->file_name,
            'expires_in_minutes' => $minutes,
        ]);
    }
}
