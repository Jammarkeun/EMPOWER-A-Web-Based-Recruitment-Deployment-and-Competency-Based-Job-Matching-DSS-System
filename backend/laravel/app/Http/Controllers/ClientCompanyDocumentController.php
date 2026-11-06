<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientCompanyDocumentResource;
use App\Http\Responses\ApiResponse;
use App\Models\ClientCompany;
use App\Models\ClientCompanyDocument;
use App\Services\AuditService;
use App\Services\DocumentStorageService;
use App\Services\DocumentUploadPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

/**
 * A client company's own documents: its BIR registration, permits, licences,
 * and whatever else CDE keeps on file for it.
 *
 * Every route is nested under the company, and every document is looked up
 * through that company, so a document belonging to another client does not
 * resolve at all - changing either id in the address reaches nothing.
 *
 * Permissions are the company's own: reading needs `clients.view`, adding,
 * editing and replacing need `clients.update`, and removing needs
 * `clients.delete` (administrators), the same split as for the company record.
 * Coordinators hold none of these and never reach this controller.
 *
 * Files go through the same private storage and content checks as applicant
 * documents (DocumentStorageService, DocumentUploadPolicy), and are opened only
 * through a short-lived signed link or an authorised download.
 */
class ClientCompanyDocumentController extends Controller
{
    private const FOLDER = 'client-documents';

    /** Stored formats that are only text, refused as company documents. */
    private const PLAIN_TEXT = ['txt', 'csv'];

    public function __construct(
        private readonly DocumentStorageService $storage,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request, ClientCompany $client): JsonResponse
    {
        $this->authorize('view', $client);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
        ]);

        $documents = $client->documents()
            ->with(['uploader', 'editor'])
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub
                ->whereContains('title', $v)
                ->orWhereContains('file_name', $v)))
            ->orderBy('title')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(ClientCompanyDocumentResource::collection($documents), 'Company documents retrieved');
    }

    public function store(Request $request, ClientCompany $client): JsonResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            ...$this->detailRules(required: true),
            'file' => $this->fileRules(),
        ], $this->messages());

        $this->assertDatesInOrder($data['issued_on'] ?? null, $data['expires_on'] ?? null);

        $file = $request->file('file');

        /*
         * The same file under the same title is answered with the copy already
         * saved instead of a second one. That is what a retry looks like when
         * the first attempt reached the server but its answer never came back,
         * and it is what a double-click looks like too.
         */
        $existing = $client->documents()
            ->where('title', $data['title'])
            ->where('file_hash', hash_file('sha256', $file->getRealPath()))
            ->first();

        if ($existing) {
            return ApiResponse::success(
                new ClientCompanyDocumentResource($existing->load(['uploader', 'editor'])),
                'This document was already saved, so nothing new was added.'
            );
        }

        $stored = $this->storage->store($file, self::FOLDER, $client->id);
        $user = $request->user();

        // The file is written first; if the record then fails, the file is
        // taken away again, so neither exists without the other.
        try {
            $document = DB::transaction(fn () => $client->documents()->create([
                ...$this->details($data),
                ...$stored,
                'uploaded_by' => $user->id,
                'uploaded_at' => now(),
                'created_by' => $user->id,
            ]));
        } catch (Throwable $exception) {
            $this->storage->delete($stored['file_path']);

            throw $exception;
        }

        $this->audit->record(
            action: 'create',
            module: 'clients',
            recordType: ClientCompanyDocument::class,
            recordId: $document->id,
            newValues: [
                'company' => $client->company_name,
                ...$this->details($data),
                ...$this->fileFacts($stored),
            ],
            clientCompanyId: $client->id,
        );

        return ApiResponse::created(
            new ClientCompanyDocumentResource($document->load(['uploader', 'editor'])),
            "\"{$document->title}\" was saved to {$client->company_name}."
        );
    }

    /**
     * Corrects the title or the optional details. The file is untouched.
     */
    public function update(Request $request, ClientCompany $client, ClientCompanyDocument $document): JsonResponse
    {
        $this->authorize('update', $client);
        $this->assertBelongs($client, $document);

        $data = $request->validate($this->detailRules(required: false), $this->messages());

        $this->assertDatesInOrder(
            array_key_exists('issued_on', $data) ? $data['issued_on'] : $document->issued_on?->toDateString(),
            array_key_exists('expires_on', $data) ? $data['expires_on'] : $document->expires_on?->toDateString(),
        );

        $changes = $this->details($data);
        $before = collect($document->only(array_keys($changes)))
            ->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)
            ->all();

        $document->update([...$changes, 'updated_by' => $request->user()->id]);

        $this->audit->recordUpdate('clients', ClientCompanyDocument::class, $document->id, $before, $changes);

        return ApiResponse::success(
            new ClientCompanyDocumentResource($document->fresh(['uploader', 'editor'])),
            'Document details updated'
        );
    }

    /**
     * Puts a new file in place of this document's current one.
     *
     * Only this document's own record changes. The new file is stored first;
     * the old one is removed only after the record points at the new one, so
     * a failure part-way leaves the document exactly as it was. The old file's
     * name, size and fingerprint stay in the audit trail.
     */
    public function replace(Request $request, ClientCompany $client, ClientCompanyDocument $document): JsonResponse
    {
        $this->authorize('update', $client);
        $this->assertBelongs($client, $document);

        $request->validate(['file' => $this->fileRules()], $this->messages());

        $stored = $this->storage->store($request->file('file'), self::FOLDER, $client->id);
        $previous = $document->only(['file_path', 'file_name', 'file_mime', 'file_size_bytes', 'file_hash']);
        $user = $request->user();

        try {
            DB::transaction(fn () => $document->update([
                ...$stored,
                'uploaded_by' => $user->id,
                'uploaded_at' => now(),
                'updated_by' => $user->id,
            ]));
        } catch (Throwable $exception) {
            $this->storage->delete($stored['file_path']);

            throw $exception;
        }

        // The superseded file goes once nothing points at it. Failing to
        // remove it is logged, not shown: the replacement itself succeeded.
        try {
            $this->storage->delete($previous['file_path']);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->audit->record(
            action: 'replace_file',
            module: 'clients',
            recordType: ClientCompanyDocument::class,
            recordId: $document->id,
            oldValues: $this->fileFacts($previous),
            newValues: ['title' => $document->title, ...$this->fileFacts($stored)],
            clientCompanyId: $client->id,
        );

        return ApiResponse::success(
            new ClientCompanyDocumentResource($document->fresh(['uploader', 'editor'])),
            "The file for \"{$document->title}\" was replaced."
        );
    }

    /**
     * Removes the document from the company's list.
     *
     * A soft removal: the record and its file are kept, with who removed it and
     * when, because a company's registration papers are business records and an
     * accidental removal must be recoverable.
     */
    public function destroy(Request $request, ClientCompany $client, ClientCompanyDocument $document): JsonResponse
    {
        $this->authorize('delete', $client);
        $this->assertBelongs($client, $document);

        DB::transaction(function () use ($document, $request) {
            $document->update(['deleted_by' => $request->user()->id]);
            $document->delete();
        });

        $this->audit->record(
            action: 'delete',
            module: 'clients',
            recordType: ClientCompanyDocument::class,
            recordId: $document->id,
            oldValues: [
                'company' => $client->company_name,
                'title' => $document->title,
                'file_name' => $document->file_name,
            ],
            clientCompanyId: $client->id,
        );

        return ApiResponse::success(null, "\"{$document->title}\" was removed from {$client->company_name}.");
    }

    /**
     * A short-lived signed link that opens the file in the browser.
     */
    public function link(ClientCompany $client, ClientCompanyDocument $document): JsonResponse
    {
        $this->authorize('view', $client);
        $this->assertBelongs($client, $document);

        return ApiResponse::success([
            'url' => $this->storage->temporaryUrl($document->file_path),
            'expires_in_minutes' => config('empower.uploads.signed_url_ttl_minutes'),
        ]);
    }

    /**
     * The file itself, as a download named after the original file.
     *
     * Sent through this authorised request rather than a link, so the
     * original name can be given. The name's extension is the stored format's,
     * since that is what the file actually is.
     */
    public function download(ClientCompany $client, ClientCompanyDocument $document): Response
    {
        $this->authorize('view', $client);
        $this->assertBelongs($client, $document);

        $extension = strtolower(pathinfo($document->file_path, PATHINFO_EXTENSION));
        $filename = (pathinfo($document->file_name, PATHINFO_FILENAME) ?: 'document').'.'.$extension;
        $fallback = preg_replace('/[^\x20-\x7e]|[%\/\\\\"]/', '_', Str::ascii($filename));

        return response($this->storage->contents($document->file_path), 200, [
            'Content-Type' => DocumentUploadPolicy::servedType($extension) ?? 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private, max-age=0',
        ]);
    }

    /**
     * Looked up through the company in the address. A document from another
     * company is "not found", never "forbidden", so its existence is not
     * confirmed either.
     */
    private function assertBelongs(ClientCompany $client, ClientCompanyDocument $document): void
    {
        abort_unless((int) $document->client_company_id === (int) $client->id, 404, 'That document was not found for this company.');
    }

    /**
     * The shared document checks, less plain text.
     *
     * The shared policy stores a file whose bytes are only text as a .txt,
     * whatever it was called - harmless, but a "permit.pdf" that is really a
     * script or a note would then sit in the list looking like a permit.
     * Company papers are PDFs, scans, photos and Office files, so text is
     * refused here instead.
     */
    private function fileRules(): array
    {
        return [
            ...DocumentUploadPolicy::rules(),
            static function (string $attribute, mixed $value, \Closure $fail) {
                if (! $value instanceof UploadedFile) {
                    return;
                }

                try {
                    $extension = app(DocumentUploadPolicy::class)->assertSafe($value);
                } catch (\RuntimeException) {
                    return; // Already reported by the shared rule.
                }

                if (in_array($extension, self::PLAIN_TEXT, true)) {
                    $fail('This file is plain text, not a document. Upload a PDF, a scan or photo, or a Word or Excel file.');
                }
            },
        ];
    }

    private function detailRules(bool $required): array
    {
        return [
            // Free text: there is no list of document types to choose from.
            'title' => [...($required ? ['required'] : ['sometimes', 'required']), 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    private function messages(): array
    {
        return [
            'title.required' => 'Give the document a title, such as "BIR Certificate of Registration".',
            'title.max' => 'Keep the title to 150 characters or fewer.',
            'file.required' => 'Choose the file to upload.',
            'file.max' => 'That file is too large. The limit is '.(int) (config('empower.uploads.max_size_kb') / 1024).' MB.',
            'file.uploaded' => 'The file did not finish uploading. It may be too large; the limit is '.(int) (config('empower.uploads.max_size_kb') / 1024).' MB.',
            'issued_on.before_or_equal' => 'The issue date cannot be in the future.',
            'issued_on.date_format' => 'Enter the issue date as a calendar date.',
            'expires_on.date_format' => 'Enter the expiry date as a calendar date.',
        ];
    }

    private function assertDatesInOrder(?string $issued, ?string $expires): void
    {
        if ($issued && $expires && $expires < $issued) {
            throw ValidationException::withMessages([
                'expires_on' => 'The expiry date cannot be before the issue date.',
            ]);
        }
    }

    /** Only the details a request actually sent, trimmed, with blanks as empty. */
    private function details(array $data): array
    {
        return collect($data)
            ->only(['title', 'description', 'issued_on', 'expires_on'])
            ->map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value)
            ->all();
    }

    /** What identifies a stored file in the audit trail, without its storage key. */
    private function fileFacts(array $file): array
    {
        return [
            'file_name' => $file['file_name'] ?? null,
            'file_mime' => $file['file_mime'] ?? null,
            'file_size_bytes' => $file['file_size_bytes'] ?? null,
            'file_hash' => $file['file_hash'] ?? null,
        ];
    }
}
