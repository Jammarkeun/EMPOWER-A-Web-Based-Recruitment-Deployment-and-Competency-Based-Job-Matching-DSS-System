<?php

namespace App\Http\Controllers;

use App\Services\DocumentUploadPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands out one document from the private local disk, for a signed link only.
 *
 * Used when documents are kept on this machine (DOCUMENT_DISK=documents); on
 * Supabase the bucket's own pre-signed links do this job. The route carries
 * the `signed:relative` middleware, so an unsigned, altered or expired link is
 * refused before this runs. The link is minted by DocumentStorageService only
 * inside a response the viewer was already authorised to receive.
 *
 * Laravel's built-in file route was not used because it sends
 * `Content-Security-Policy: sandbox`, under which Chrome refuses to display a
 * PDF at all. Instead only the types DocumentUploadPolicy stores are ever
 * served, each with its exact content type and `nosniff`, so a file can never
 * be read as a page. PDFs and ordinary images open in the browser; a Word file
 * or anything else a browser cannot show is sent as a download.
 */
class DocumentFileController extends Controller
{
    public function __invoke(Request $request, string $path): Response
    {
        $diskName = (string) config('empower.uploads.disk');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $type = DocumentUploadPolicy::servedType($extension);

        abort_if(
            config("filesystems.disks.{$diskName}.driver") !== 'local'
                || $type === null
                || str_contains($path, '..')
                || str_contains($path, "\0"),
            404
        );

        $disk = Storage::disk($diskName);

        try {
            abort_unless($disk->exists($path), 404);
        } catch (PathTraversalDetected|FilesystemException) {
            abort(404);
        }

        $headers = [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private, max-age=0',
            // The signed link must not travel onward in a Referer header.
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => $extension === 'pdf'
                ? "frame-ancestors 'none'"
                : "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'; sandbox",
        ];

        return $disk->response(
            $path,
            basename($path),
            $headers,
            DocumentUploadPolicy::opensInBrowser($extension) ? 'inline' : 'attachment'
        );
    }
}
