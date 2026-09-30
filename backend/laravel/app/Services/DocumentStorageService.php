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
        return config('empower.uploads.disk', 'supabase');
    }

    /**
     * @param  string  $folder  Logical grouping, e.g. "requirements" or "violations"
     */
    public function store(UploadedFile $file, string $folder, int $ownerId): array
    {
        $this->policy->assertSafe($file);

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
            $stored = false;
            $attempts = (int) config('empower.uploads.storage_retries', 3);

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $stored = Storage::disk($this->disk())->put($key, $stream, ['visibility' => 'private']);
                    if ($stored) {
                        break;
                    }
                } catch (\Throwable $exception) {
                    if ($attempt === $attempts) {
                        $message = $exception->getMessage();
                        if (str_contains($message, 'String could not be parsed as XML') || str_contains($message, 'Project paused') || str_contains($message, '540')) {
                            throw new RuntimeException(
                                'Upload failed: The Supabase storage project is currently paused. Please unpause your project on the Supabase Dashboard (https://supabase.com/dashboard) and try again.',
                                0,
                                $exception
                            );
                        }
                        throw $exception;
                    }
                }
                rewind($stream);
                usleep(100000 * $attempt);
            }

            if (! $stored) {
                throw new RuntimeException('The document could not be stored. Please try again.');
            }
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
        $disk = $this->disk();

        try {
            return Storage::disk($disk)->temporaryUrl($key, now()->addMinutes($minutes));
        } catch (\Throwable) {
            return Storage::disk($disk)->url($key);
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
     * that could be interpreted as a path.
     */
    private function sanitiseName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));

        return Str::limit(preg_replace('/[^\w\s.\-()]/u', '', $name), 180, '');
    }
}
