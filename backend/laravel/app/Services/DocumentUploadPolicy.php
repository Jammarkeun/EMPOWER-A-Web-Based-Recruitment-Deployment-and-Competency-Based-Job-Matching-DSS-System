<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class DocumentUploadPolicy
{
    public function rules(): array
    {
        return [
            'required',
            'file',
            'max:'.config('empower.uploads.max_size_kb'),
            'mimes:'.implode(',', config('empower.uploads.allowed_mimes')),
        ];
    }

    public function assertSafe(UploadedFile $file): void
    {
        $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        $mime = $file->getMimeType();

        if (! in_array($mime, $allowed, true)) {
            throw new RuntimeException(
                'Only PDF and image files are accepted. The uploaded file appears to be '.$mime.'.'
            );
        }

        $maxBytes = (int) config('empower.uploads.max_size_kb', 10240) * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new RuntimeException('The uploaded file exceeds the allowed size limit.');
        }

        if (! str_starts_with((string) $mime, 'image/')) {
            return;
        }

        $dimensions = @getimagesize($file->getRealPath());
        $maxWidth = (int) config('empower.uploads.max_width', 7000);
        $maxHeight = (int) config('empower.uploads.max_height', 7000);

        if (! $dimensions || $dimensions[0] > $maxWidth || $dimensions[1] > $maxHeight) {
            throw new RuntimeException('The image dimensions exceed the allowed limit.');
        }
    }
}
