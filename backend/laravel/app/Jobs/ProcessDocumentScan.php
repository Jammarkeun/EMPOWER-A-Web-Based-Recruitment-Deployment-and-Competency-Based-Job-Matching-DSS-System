<?php

namespace App\Jobs;

use App\Models\DocumentScan;
use App\Services\AuditService;
use App\Services\OcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ProcessDocumentScan implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(public readonly int $scanId)
    {
    }

    public function handle(OcrService $ocr, AuditService $audit): void
    {
        $scan = DocumentScan::find($this->scanId);

        if (! $scan || $scan->status !== 'queued') {
            return;
        }

        $scan->update(['status' => 'processing', 'started_at' => now()]);
        $path = Storage::disk('local')->path($scan->stored_path);

        try {
            $file = new UploadedFile($path, $scan->original_name, $scan->mime_type, null, true);
            $result = $ocr->scan($file, $scan->document_type);
            $storedResult = $result;
            unset($storedResult['text'], $storedResult['lines']);

            $scan->update([
                'status' => $result['success'] ? 'completed' : 'failed',
                'result' => $storedResult,
                'error_message' => $result['success'] ? null : ($result['message'] ?? 'The document could not be read.'),
                'completed_at' => now(),
            ]);

            $audit->record(
                action: 'create',
                module: 'applicants',
                newValues: [
                    'document_scan' => $scan->document_type,
                    'scan_id' => $scan->id,
                    'status' => $scan->status,
                    'fields_proposed' => array_keys($result['fields'] ?? []),
                    'confidence' => $result['meta']['recognition_confidence'] ?? null,
                ],
            );
        } finally {
            Storage::disk('local')->delete($scan->stored_path);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $scan = DocumentScan::find($this->scanId);

        if ($scan) {
            $scan->update([
                'status' => 'failed',
                'error_message' => 'The document scan failed. Please enter the details manually.',
                'completed_at' => now(),
            ]);
            Storage::disk('local')->delete($scan->stored_path);
        }
    }
}
