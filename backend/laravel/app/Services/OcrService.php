<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client for the document-reading service.
 *
 * Reads an uploaded document and returns proposed applicant details. Nothing is
 * written to any record here: the result is handed to HR, who correct it and
 * decide whether to apply it. Recognition on a photographed document is never
 * certain, and a wrong birth date written silently into an applicant's file is
 * worse than no birth date at all.
 *
 * Every failure path returns a structured result rather than throwing, because
 * the service being down must never block registering an applicant.
 */
class OcrService
{
    public function isEnabled(): bool
    {
        return (bool) config('ocr.enabled');
    }

    /**
     * Check whether the service is running, for the UI to decide whether to
     * offer the scan button at all.
     */
    public function isAvailable(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $response = Http::timeout(5)->get($this->url('/health'));

            return $response->successful() && $response->json('status') === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Send a document for reading.
     *
     * @return array{success: bool, message: string, fields?: array, text?: string, meta?: array}
     */
    public function scan(UploadedFile $file, string $documentType = 'auto'): array
    {
        if (! $this->isEnabled()) {
            return $this->failure('Document reading is turned off for this installation.');
        }

        $timeout = (int) config('ocr.timeout');

        /*
         * PHP's own execution limit is separate from the HTTP client's, and the
         * two must agree or the longer one is a lie.
         *
         * Reading a document is the slowest thing this application does: the
         * first scan after the service starts spends roughly fifty seconds
         * loading the recognition models, and a multi-page PDF is not much
         * quicker. Meanwhile `php artisan serve` runs the cli-server SAPI,
         * which applies php.ini's default max_execution_time of 30 seconds — so
         * PHP killed the request with a fatal error while the HTTP client was
         * still patiently waiting out its 120.
         *
         * Deriving the limit from the same config value is what stops them
         * drifting apart again: whatever OCR_TIMEOUT allows, PHP allows a
         * little more, and raising one raises both.
         */
        set_time_limit($timeout + 15);

        try {
            $request = Http::timeout($timeout)
                ->attach('file', fopen($file->getRealPath(), 'rb'), $file->getClientOriginalName());

            if ($token = config('ocr.token')) {
                $request = $request->withHeaders(['X-OCR-Token' => $token]);
            }

            $response = $request->post($this->url('/ocr/parse'), ['document_type' => $documentType]);

            if ($response->status() === 401) {
                Log::error('OCR service rejected the shared secret.');

                return $this->failure(
                    'The document service refused the request. Check that OCR_SERVICE_TOKEN matches on both sides.'
                );
            }

            if (! $response->successful()) {
                return $this->failure(
                    $response->json('detail') ?? 'The document could not be read. Please enter the details manually.'
                );
            }

            $payload = $response->json();

            if (! ($payload['success'] ?? false)) {
                return $this->failure(
                    $payload['message'] ?? 'No readable text was found in that document.'
                );
            }

            return [
                'success' => true,
                'message' => 'Document read. Please check the details before saving.',
                'fields' => $this->mapFields($payload['extraction']['fields'] ?? []),
                'document_type' => $payload['extraction']['document_type'] ?? 'unknown',
                'text' => $payload['text'] ?? '',
                'meta' => [
                    'recognition_confidence' => $payload['recognition_confidence'] ?? null,
                    'processing_seconds' => $payload['processing_seconds'] ?? null,
                    'low_resolution' => (bool) ($payload['pages'][0]['low_resolution_warning'] ?? false),
                ],
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('OCR service unreachable', ['error' => $e->getMessage()]);

            return $this->failure(
                'The document reading service is not running. You can still enter the details manually.'
            );
        } catch (\Throwable $e) {
            Log::error('OCR request failed', ['error' => $e->getMessage()]);

            return $this->failure('The document could not be read. Please enter the details manually.');
        }
    }

    /**
     * Filter and shape the proposed fields for the client.
     *
     * Only columns listed in config('ocr.assignable_fields') survive, so a change
     * to the Python extractor can never start proposing values for columns the
     * form does not expect.
     */
    private function mapFields(array $raw): array
    {
        $allowed = config('ocr.assignable_fields');
        $threshold = (float) config('ocr.review_threshold');

        $fields = [];

        foreach ($raw as $name => $data) {
            if (! in_array($name, $allowed, true)) {
                continue;
            }

            $value = $data['value'] ?? null;
            if (blank($value)) {
                continue;
            }

            $confidence = (float) ($data['confidence'] ?? 0);

            $fields[$name] = [
                'value' => $value,
                'confidence' => $confidence,
                // Drives the highlight in the review screen. Names and addresses
                // land here almost always, which is intended: they are exactly
                // the values a person should check.
                'needs_review' => $confidence < $threshold,
                'source' => $data['source'] ?? null,
            ];
        }

        return $fields;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('ocr.url'), '/').$path;
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message, 'fields' => []];
    }
}
