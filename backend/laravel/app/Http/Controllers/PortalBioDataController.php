<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Services\BioDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The applicant's own digital Bio-Data.
 *
 * Like everything under /portal, no route takes an identifier: the record is
 * the signed-in account's own, so there is nothing to change in a request to
 * reach somebody else's Bio-Data. Request bodies are run through the form's
 * own rules and normaliser, which keep only the form's fields - an
 * `applicant_id`, a status or a "verified" flag sent along is dropped.
 */
class PortalBioDataController extends Controller
{
    public function __construct(private readonly BioDataService $bioData)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->bioData->forPortal($this->applicant($request)));
    }

    /** Saves the working copy. The office does not see it and nothing changes status. */
    public function saveDraft(Request $request): JsonResponse
    {
        $applicant = $this->applicant($request);
        $this->bioData->saveDraft($applicant, $request->all());

        return ApiResponse::success(
            $this->bioData->forPortal($applicant->fresh()),
            'Draft saved. It has not been sent to the office yet.'
        );
    }

    /**
     * Sends the saved form. The applicant has to confirm the details are
     * theirs and correct - a confirmation, not a signature.
     */
    public function submit(Request $request): JsonResponse
    {
        $applicant = $this->applicant($request);

        $request->validate([
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Please confirm that the information is true and correct before sending it.',
        ]);

        $this->bioData->submit($applicant, $request->user());

        return ApiResponse::success(
            $this->bioData->forPortal($applicant->fresh()),
            'Your Bio-Data has been sent to the office. They will review it and let you know.'
        );
    }

    /**
     * The applicant's own Bio-Data as a PDF: what they last sent, or their
     * saved draft if they have not sent one. Rendered on request, never stored.
     */
    public function pdf(Request $request): Response
    {
        $applicant = $this->applicant($request);
        $latest = $applicant->latestBioDataVersion;
        $draft = $request->boolean('draft') || ! $latest;

        $version = $draft ? null : $latest;

        return $this->bioData->pdf($applicant, $version, $draft)
            ->download(BioDataService::pdfFilename($applicant, $version));
    }

    /**
     * The applicant behind this login. Every portal account carries one
     * (EnsurePortalUser), an employee's included, so the Bio-Data stays with
     * the person after they are deployed.
     */
    private function applicant(Request $request): Applicant
    {
        $applicant = $request->user()->applicant;

        abort_if(
            ! $applicant,
            403,
            'This account is not linked to an applicant record. Please contact the HR office.'
        );

        return $applicant;
    }
}
