<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client company's document as the screen sees it.
 *
 * The storage key is never included: the file is reached only through the
 * authorised open and download endpoints.
 *
 * @mixin \App\Models\ClientCompanyDocument
 */
class ClientCompanyDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_company_id' => $this->client_company_id,
            'title' => $this->title,
            'description' => $this->description,
            'issued_on' => $this->issued_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            // Only ever true for a document whose own expiry date has passed.
            'is_expired' => $this->isExpired(),
            'file_name' => $this->file_name,
            'file_type' => self::typeLabel((string) $this->file_mime),
            'file_mime' => $this->file_mime,
            'file_size_bytes' => $this->file_size_bytes,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->full_name),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by' => $this->whenLoaded('editor', fn () => $this->editor?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** "PDF", "Image", "Word" - what kind of file it is, in words. */
    public static function typeLabel(string $mime): string
    {
        return match (true) {
            $mime === 'application/pdf' => 'PDF',
            str_starts_with($mime, 'image/') => 'Image',
            str_contains($mime, 'wordprocessing'), $mime === 'application/msword', $mime === 'application/vnd.oasis.opendocument.text' => 'Word',
            str_contains($mime, 'spreadsheet'), $mime === 'application/vnd.ms-excel', $mime === 'text/csv' => 'Spreadsheet',
            str_contains($mime, 'presentation'), $mime === 'application/vnd.ms-powerpoint' => 'Presentation',
            default => 'Document',
        };
    }
}
