<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Services\AuditService;
use App\Services\OcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reads an uploaded document and proposes applicant details.
 *
 * The endpoint returns a proposal and stops there. It does not create or modify
 * an applicant, because recognition is never certain and a wrongly-read birth
 * date written silently into a record is worse than an empty field. HR reviews
 * the proposal on screen and submits it through the normal applicant endpoints,
 * where the usual validation applies.
 */
class DocumentScanController extends Controller
{
    public function __construct(
        private readonly OcrService $ocr,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Whether document reading is available, so the client can decide whether to
     * offer the button rather than showing one that fails.
     */
    public function status(): JsonResponse
    {
        return ApiResponse::success([
            'enabled' => $this->ocr->isEnabled(),
            'available' => $this->ocr->isAvailable(),
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $this->authorize('create', Applicant::class);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.config('empower.uploads.max_size_kb'),
                'mimes:'.implode(',', config('empower.uploads.allowed_mimes')),
            ],
            'document_type' => [
                'nullable',
                Rule::in(['auto', 'resume', 'id', 'umid', 'philsys', 'drivers_license']),
            ],
        ]);

        $result = $this->ocr->scan(
            $request->file('file'),
            $request->input('document_type', 'auto')
        );

        if (! $result['success']) {
            // 200 rather than an error status: a document that could not be read
            // is a normal outcome the user acts on, not a failure of the request.
            return ApiResponse::success(
                ['fields' => [], 'readable' => false],
                $result['message']
            );
        }

        // Recorded because a scan means a document containing personal
        // information passed through the system, even though nothing was saved.
        $this->audit->record(
            action: 'create',
            module: 'applicants',
            newValues: [
                'document_scan' => $result['document_type'],
                'fields_proposed' => array_keys($result['fields']),
                'confidence' => $result['meta']['recognition_confidence'] ?? null,
            ],
        );

        return ApiResponse::success([
            'readable' => true,
            'fields' => $result['fields'],
            'document_type' => $result['document_type'],
            'meta' => $result['meta'],
            // Repeated in the payload so no client can treat these as final by
            // accident.
            'notice' => 'These are proposed values read from the document. Check each one before saving.',
        ], $result['message']);
    }
}
