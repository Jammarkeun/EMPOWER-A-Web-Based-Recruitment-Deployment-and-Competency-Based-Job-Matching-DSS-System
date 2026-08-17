<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores applicant documents in the private Supabase Storage bucket.
 *
 * Applicant requirements include birth certificates, police clearances, and
 * medical results, which are sensitive personal information under RA 10173. The
 * bucket is private and files are only ever reachable through a short-lived
 * signed URL minted here, so a link copied out of the browser stops working
 * within minutes rather than granting permanent access.
 */
class DocumentStorageService
{
    private const DISK = 'supabase';

    /**
     * @param  string  $folder  Logical grouping, e.g. "requirements" or "violations"
     */
    public function store(UploadedFile $file, string $folder, int $ownerId): array
    {
        $this->assertSafe($file);

        // A random object key means a document cannot be located by guessing an
        // applicant's name or ID, and two uploads of "birth_certificate.pdf"
        // never collide.
        $key = sprintf(
            '%s/%d/%s.%s',
            $folder,
            $ownerId,
            Str::uuid(),
            strtolower($file->getClientOriginalExtension())
        );

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            Storage::disk(self::DISK)->put($key, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'file_path' => $key,
            'file_name' => $this->sanitiseName($file->getClientOriginalName()),
            'file_mime' => $file->getMimeType(),
            'file_size_bytes' => $file->getSize(),
            // Lets a duplicate submission be recognised without opening the file.
            'file_hash' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    /**
     * A time-limited download link. The secret key never leaves the server.
     */
    public function temporaryUrl(string $key, ?int $minutes = null): string
    {
        $minutes ??= config('empower.uploads.signed_url_ttl_minutes', 10);

        return Storage::disk(self::DISK)->temporaryUrl($key, now()->addMinutes($minutes));
    }

    public function delete(?string $key): void
    {
        if (blank($key)) {
            return;
        }

        Storage::disk(self::DISK)->delete($key);
    }

    public function exists(string $key): bool
    {
        return Storage::disk(self::DISK)->exists($key);
    }

    /**
     * Validates the file's real content type rather than trusting its extension.
     *
     * Laravel's "mimes" rule already sniffs content, but this is the last line
     * before a file is persisted, and an executable renamed to .pdf must not
     * reach the bucket.
     */
    private function assertSafe(UploadedFile $file): void
    {
        $allowed = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ];

        $mime = $file->getMimeType();

        if (! in_array($mime, $allowed, true)) {
            throw new RuntimeException(
                'Only PDF and image files are accepted. The uploaded file appears to be '.$mime.'.'
            );
        }

        $maxBytes = config('empower.uploads.max_size_kb', 10240) * 1024;

        if ($file->getSize() > $maxBytes) {
            throw new RuntimeException(sprintf(
                'The file is %s MB. The maximum accepted size is %s MB.',
                round($file->getSize() / 1048576, 1),
                round($maxBytes / 1048576, 1)
            ));
        }
    }

    /**
     * Keeps the original filename readable for display while stripping anything
     * that could be interpreted as a path.
     */
    private function sanitiseName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        return Str::limit(preg_replace('/[^\w\s.\-()]/u', '', $name), 180, '');
    }
}
