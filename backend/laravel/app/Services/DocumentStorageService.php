<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Stores applicant documents on a private disk and hands out short-lived links.
 *
 * Applicant requirements include birth certificates, police clearances, and
 * medical results, which are sensitive personal information under RA 10173.
 * Every disk this service writes to must be private - the Supabase bucket in
 * the defence setup, `documents` (outside the web root) on a local machine -
 * and a file is only ever reachable through a signed link minted here, so a
 * link copied out of the browser stops working within minutes rather than
 * granting permanent access.
 *
 * There is deliberately no fallback to a plain URL. A disk that cannot sign a
 * link gets no link at all: an unsigned URL to a medical result is worse than
 * a document that will not open.
 */
class DocumentStorageService
{
    public function __construct(private readonly DocumentUploadPolicy $policy)
    {
    }

    /**
     * Which filesystem disk documents live on.
     *
     * Read from config rather than hardcoded, because hardcoding a remote disk
     * in the service meant the test suite wrote applicant documents to the live
     * Supabase bucket. The tests looked like they were faking storage - they
     * called Storage::fake() - but on a disk name nothing actually used, so
     * every upload went over the network to Singapore. That made them slow,
     * intermittently red when the network hiccuped, and quietly polluted real
     * storage with fixture files.
     */
    private function disk(): string
    {
        $disk = (string) config('empower.uploads.disk', 'supabase');

        $this->assertPrivate($disk);

        return $disk;
    }

    /**
     * Refuses to use a disk whose files the web server hands out to anybody.
     *
     * The `public` disk sits behind the public/storage symlink: everything on it
     * can be opened by URL, without signing in, for ever. Documents were kept
     * there on local machines, which is exactly what this check now prevents.
     */
    private function assertPrivate(string $disk): void
    {
        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config) || $disk === 'public' || ($config['visibility'] ?? null) === 'public') {
            Log::critical('Document storage refused: the configured disk is public or missing.', ['disk' => $disk]);

            throw new RuntimeException(
                'Document storage is not configured safely, so documents cannot be stored or opened. Please contact the system administrator.',
                503
            );
        }
    }

    /**
     * @param  string  $folder  Logical grouping, e.g. "requirements" or "violations"
     * @param  array<string, string>|null  $types  A narrower allowlist (detected type =>
     *                                             stored extension) for one kind of upload
     */
    public function store(UploadedFile $file, string $folder, int $ownerId, ?array $types = null): array
    {
        // The extension comes from the detected content, never from the name the
        // uploader gave the file: a PDF called "notes.html" must not be stored,
        // or served back, as a web page.
        $extension = $this->policy->assertSafe($file, $types);

        return $this->put($file, $folder, $ownerId, $extension);
    }

    /**
     * Stores a file whose content the caller has already checked, on the same
     * private disk and under the same random keys as documents.
     *
     * For uploads that are not documents - a client's DTR spreadsheet - whose
     * type the document policy, rightly, would refuse. The caller is
     * responsible for having opened and validated the file first.
     */
    public function storeChecked(UploadedFile $file, string $folder, int $ownerId, string $extension): array
    {
        return $this->put($file, $folder, $ownerId, $extension);
    }

    /**
     * The stored bytes, for a file the server itself has to read back.
     */
    public function contents(string $key): string
    {
        $contents = Storage::disk($this->disk())->get($key);

        if ($contents === null) {
            throw new RuntimeException('The stored file could not be read. It may have been removed.', 404);
        }

        return $contents;
    }

    private function put(UploadedFile $file, string $folder, int $ownerId, string $extension): array
    {
        $disk = $this->disk();

        // A random object key means a document cannot be located by guessing an
        // applicant's name or ID, and two uploads of "birth_certificate.pdf"
        // never collide. Nothing the uploader typed reaches the path.
        $key = sprintf('%s/%d/%s.%s', $folder, $ownerId, Str::uuid(), $extension);

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $stored = false;
            $attempts = (int) config('empower.uploads.storage_retries', 3);

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $stored = Storage::disk($disk)->put($key, $stream, ['visibility' => 'private']);
                    if ($stored) {
                        break;
                    }
                } catch (Throwable $exception) {
                    if ($attempt === $attempts) {
                        // The storage provider's own message names endpoints and
                        // object keys; it goes to the log, not to the browser.
                        report($exception);

                        $message = $exception->getMessage();
                        if (str_contains($message, 'String could not be parsed as XML') || str_contains($message, 'Project paused') || str_contains($message, '540')) {
                            throw new RuntimeException(
                                'Upload failed: The Supabase storage project is currently paused. Please unpause your project on the Supabase Dashboard (https://supabase.com/dashboard) and try again.',
                                503,
                                $exception
                            );
                        }

                        throw new RuntimeException('The document could not be stored. Please try again.', 503, $exception);
                    }
                }
                rewind($stream);
                usleep(100000 * $attempt);
            }

            if (! $stored) {
                throw new RuntimeException('The document could not be stored. Please try again.', 503);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'file_path' => $key,
            'file_name' => $this->sanitiseName($file->getClientOriginalName()),
            // The stored format's own type: a .docx that finfo saw only as a
            // zip is still a Word document.
            'file_mime' => DocumentUploadPolicy::servedType($extension) ?? $file->getMimeType(),
            'file_size_bytes' => $file->getSize(),
            // Lets a duplicate submission be recognised without opening the file.
            'file_hash' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    /**
     * A time-limited, signed download link. The secret key never leaves the
     * server, and there is no unsigned fallback.
     */
    public function temporaryUrl(string $key, ?int $minutes = null): string
    {
        $minutes ??= (int) config('empower.uploads.signed_url_ttl_minutes', 10);
        $disk = $this->disk();

        try {
            // A private disk on this machine is served by DocumentFileController
            // through a link signed with the application key; a remote bucket
            // signs its own links.
            if (config("filesystems.disks.{$disk}.driver") === 'local') {
                return URL::to(URL::temporarySignedRoute(
                    'documents.file',
                    now()->addMinutes($minutes),
                    ['path' => $key],
                    absolute: false
                ));
            }

            return Storage::disk($disk)->temporaryUrl($key, now()->addMinutes($minutes));
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException(
                'A secure link to this document could not be created. Please try again later.',
                503,
                $exception
            );
        }
    }

    public function delete(?string $key): void
    {
        if (blank($key)) {
            return;
        }

        Storage::disk($this->disk())->delete($key);
    }

    public function exists(string $key): bool
    {
        return Storage::disk($this->disk())->exists($key);
    }

    /**
     * Keeps the original filename readable for display while stripping anything
     * that could be interpreted as a path. Used for display only, never for
     * storage.
     */
    private function sanitiseName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        return Str::limit(preg_replace('/[^\w\s.\-()]/u', '', $name), 180, '');
    }
}
