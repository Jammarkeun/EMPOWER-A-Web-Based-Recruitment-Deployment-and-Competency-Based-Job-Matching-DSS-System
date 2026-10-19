<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * What an uploaded document may be, decided from its content.
 *
 * The file's own name and the Content-Type the browser sent are both chosen by
 * the uploader, so neither decides anything here. The type is read from the
 * file's bytes, checked against an explicit list, and the stored file is given
 * the extension that belongs to that type. A PDF named "notes.html" used to be
 * stored as .html and could be served back as a web page; it is now refused.
 */
class DocumentUploadPolicy
{
    /**
     * Detected content type => the extension the stored file is given.
     */
    public const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Extensions an uploaded file's name may carry. Anything else - .html,
     * .svg, .php, .exe, or no extension at all - is refused before the content
     * is even considered, whatever the bytes say.
     */
    private const NAME_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function rules(): array
    {
        return [
            'required',
            'file',
            'max:'.config('empower.uploads.max_size_kb'),
            'mimes:'.implode(',', config('empower.uploads.allowed_mimes')),
        ];
    }

    /**
     * Refuses anything that is not an allowed, intact document and returns the
     * extension the file must be stored under.
     *
     * @param  array<string, string>|null  $types  A narrower allowlist for one kind of
     *                                             upload, e.g. images only for photos
     */
    public function assertSafe(UploadedFile $file, ?array $types = null): string
    {
        $types ??= self::TYPES;

        if (! $file->isValid()) {
            throw new RuntimeException('The file could not be read. Please choose it again.');
        }

        $nameExtension = strtolower($file->getClientOriginalExtension());

        if (! in_array($nameExtension, self::NAME_EXTENSIONS, true)) {
            throw new RuntimeException(
                'Only PDF, JPG, PNG and WebP files are accepted. Save the document in one of those formats and try again.'
            );
        }

        // Read from the bytes (finfo), not from the name or the request header.
        $mime = (string) $file->getMimeType();

        if (! array_key_exists($mime, $types)) {
            throw new RuntimeException(
                'Only PDF and image files are accepted. The uploaded file appears to be '.$mime.'.'
            );
        }

        $maxBytes = (int) config('empower.uploads.max_size_kb', 10240) * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new RuntimeException('The uploaded file exceeds the allowed size limit.');
        }

        // finfo only reports application/pdf for a file that starts with the
        // %PDF- signature, so a PDF needs no second check; an image must also
        // decode as the type it claims to be.
        if (str_starts_with($mime, 'image/')) {
            $this->assertImage($file, $mime);
        }

        return $types[$mime];
    }

    /**
     * An image must decode as the type it claims to be, within the size limits.
     */
    private function assertImage(UploadedFile $file, string $mime): void
    {
        $dimensions = @getimagesize($file->getRealPath());
        $maxWidth = (int) config('empower.uploads.max_width', 7000);
        $maxHeight = (int) config('empower.uploads.max_height', 7000);

        if (! $dimensions || ($dimensions['mime'] ?? null) !== $mime) {
            throw new RuntimeException('This image could not be read. It may be damaged; try saving it again.');
        }

        if ($dimensions[0] > $maxWidth || $dimensions[1] > $maxHeight) {
            throw new RuntimeException('The image dimensions exceed the allowed limit.');
        }
    }
}
