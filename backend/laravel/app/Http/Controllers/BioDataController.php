<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApplicantRequirementResource;
use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ApplicantBioDataVersion;
use App\Services\AuditService;
use App\Services\BioDataService;
use App\Services\FolderCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * HR's side of the digital Bio-Data: reading it, deciding on it, printing it.
 *
 * Reading is open to whoever may read the applicant's documents and deciding
 * to whoever may verify them - the same permissions as every other
 * requirement, since this is one.
 */
class BioDataController extends Controller
{
    public function __construct(
        private readonly BioDataService $bioData,
        private readonly FolderCategoryService $folders,
        private readonly AuditService $audit,
    ) {
    }

    /** Opening it is what puts a submitted form under review. */
    public function show(Request $request, Applicant $applicant): JsonResponse
    {
        $this->authorize('viewRequirements', $applicant);

        $this->bioData->recordStaffView($applicant, $request->user());

        return ApiResponse::success($this->bioData->forStaff($applicant->fresh(), $request->user()));
    }

    public function review(Request $request, Applicant $applicant): JsonResponse
    {
        $this->authorize('verifyRequirement', $applicant);

        $data = $request->validate([
            'outcome' => ['required', Rule::in([ApplicantBioDataVersion::VERIFIED, ApplicantBioDataVersion::NEEDS_CORRECTION])],
            // Sending it back without saying what is wrong only earns a phone call.
            'note' => ['required_if:outcome,needs_correction', 'nullable', 'string', 'max:255'],
        ], [
            'note.required_if' => 'Say what the applicant needs to correct.',
        ]);

        $requirement = $this->bioData->review($applicant, $request->user(), $data['outcome'], $data['note'] ?? null);
        $fresh = $applicant->fresh();

        return ApiResponse::success([
            'requirement' => new ApplicantRequirementResource(
                $requirement->fresh()->load(['requirementType', 'verifier', 'firstViewer'])->setRelation('applicant', $fresh)
            ),
            'folder' => $this->folders->explain($fresh),
            'applicant_status' => $fresh->current_status,
            'bio_data' => $this->bioData->forStaff($fresh, $request->user()),
        ], $data['outcome'] === ApplicantBioDataVersion::VERIFIED
            ? 'Bio-Data verified'
            : 'Bio-Data returned for correction');
    }

    /** A submitted version as a PDF - the latest, or ?version=N. Drafts are the applicant's own. */
    public function pdf(Request $request, Applicant $applicant): Response
    {
        $this->authorize('viewRequirements', $applicant);

        $request->validate(['version' => ['nullable', 'integer', 'min:1']]);

        $version = $applicant->bioDataVersions()
            ->when($request->integer('version'), fn ($q, $n) => $q->where('version_number', $n))
            ->firstOrFail();

        $this->audit->record(
            action: 'export',
            module: 'requirements',
            recordType: ApplicantBioDataVersion::class,
            recordId: $version->id,
            newValues: ['document' => 'Digital Bio-Data v'.$version->version_number.' (PDF)', 'applicant_id' => $applicant->id],
        );

        return $this->bioData->pdf($applicant, $version)
            ->download(BioDataService::pdfFilename($applicant, $version));
    }
}
