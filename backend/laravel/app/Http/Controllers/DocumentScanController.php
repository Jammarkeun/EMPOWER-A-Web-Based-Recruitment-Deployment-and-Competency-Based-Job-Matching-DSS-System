<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Jobs\ProcessDocumentScan;
use App\Models\Applicant;
use App\Models\DocumentScan;
use App\Services\OcrService;
use App\Services\DocumentUploadPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        private readonly DocumentUploadPolicy $uploads,
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
            'file' => $this->uploads->rules(),
            'document_type' => [
                'nullable',
                Rule::in(['auto', 'resume', 'id', 'umid', 'philsys', 'drivers_license']),
            ],
        ]);

        $file = $request->file('file');
        $this->uploads->assertSafe($file);
        $path = 'ocr-scans/'.Str::uuid().'-'.basename($file->getClientOriginalName());
        if (! Storage::disk('local')->putFileAs('ocr-scans', $file, basename($path))) {
            return ApiResponse::error('The scan could not be queued. Please try again.', 503);
        }

        $scan = DocumentScan::create([
            'user_id' => $request->user()->id,
            'stored_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'document_type' => $request->input('document_type', 'auto'),
            'status' => 'queued',
        ]);

        ProcessDocumentScan::dispatch($scan->id);

        return ApiResponse::success([
            'scan_id' => $scan->id,
            'status' => $scan->status,
            'poll_url' => url('/api/v1/document-scan/status/'.$scan->id),
        ], 'Document scan queued', 202);
    }

    public function result(Request $request, DocumentScan $scan): JsonResponse
    {
        abort_unless($scan->user_id === $request->user()->id, 404);

        $payload = ['scan_id' => $scan->id, 'status' => $scan->status];
        if ($scan->status === 'completed') {
            $result = $scan->result ?? [];
            $payload += [
                'readable' => (bool) ($result['success'] ?? false),
                'fields' => $result['fields'] ?? [],
                'document_type' => $result['document_type'] ?? 'unknown',
                'meta' => $result['meta'] ?? [],
                'notice' => 'These are proposed values read from the document. Check each one before saving.',
            ];
        } elseif ($scan->status === 'failed') {
            $payload += ['readable' => false, 'fields' => [], 'message' => $scan->error_message];
        }

        return ApiResponse::success($payload);
    }
}
