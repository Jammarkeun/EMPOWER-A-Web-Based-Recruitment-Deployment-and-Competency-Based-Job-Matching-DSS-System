<?php

namespace App\Http\Controllers;

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
 * PDF at all. Instead only the four stored types are ever served, each with its
 * exact content type and `nosniff`, so a file can never be read as a page.
 */
class DocumentFileController extends Controller
{
    /** Stored extension => the content type it is served with. Nothing else is served. */
    private const TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function __invoke(Request $request, string $path): Response
    {
        $diskName = (string) config('empower.uploads.disk');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        abort_if(
            config("filesystems.disks.{$diskName}.driver") !== 'local'
                || ! isset(self::TYPES[$extension])
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
            'Content-Type' => self::TYPES[$extension],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private, max-age=0',
            // The signed link must not travel onward in a Referer header.
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => $extension === 'pdf'
                ? "frame-ancestors 'none'"
                : "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'; sandbox",
        ];

        return $disk->response($path, basename($path), $headers, 'inline');
    }
}
