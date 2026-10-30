<?php

namespace App\Services;

use Closure;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

/**
 * What an uploaded document may be, decided from its content.
 *
 * The file's own name and the Content-Type the browser sent are both chosen by
 * the uploader, so neither decides anything here. The type is read from the
 * file's bytes, checked against an explicit list, and the stored file is given
 * the extension that belongs to that type. A PDF named "notes.html" used to be
 * stored as .html and could be served back as a web page; it is now refused.
 *
 * The list covers what people actually keep documents in - PDFs, photos
 * (including an iPhone's HEIC), Word, Excel, PowerPoint and OpenDocument
 * files, plain text - not only what a browser can display. What stays out is
 * anything a browser or a computer would run: web pages, SVG, scripts,
 * programs, and archives, whose contents nobody can check before opening.
 */
class DocumentUploadPolicy
{
    /**
     * What document reading (OCR) understands, for uploads that are read
     * rather than only stored: detected content type => stored extension.
     */
    public const SCANNABLE = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** How finfo may see an older (97-2003) Office file: one container, told apart by name. */
    private const OLE = [
        'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
        'application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2',
    ];

    /** How finfo may see a zip-based document it cannot look inside. */
    private const ZIP = ['application/zip', 'application/octet-stream'];

    /**
     * Stored extension => the content types the file's bytes may be detected
     * as. The first is the format's own type. Any after it are what finfo
     * reports for some genuine files of the format - an Office file saved by
     * something other than Office is often seen only as a zip - and are trusted
     * only when the file's name agrees and its insides are checked below.
     */
    private const FORMATS = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'tif' => ['image/tiff'],
        'heic' => ['image/heic', 'image/heif'],
        'doc' => ['application/msword', ...self::OLE],
        'xls' => ['application/vnd.ms-excel', ...self::OLE],
        'ppt' => ['application/vnd.ms-powerpoint', ...self::OLE],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::ZIP],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::ZIP],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::ZIP],
        'odt' => ['application/vnd.oasis.opendocument.text', ...self::ZIP],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', ...self::ZIP],
        'odp' => ['application/vnd.oasis.opendocument.presentation', ...self::ZIP],
        'rtf' => ['text/rtf', 'application/rtf'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain'],
    ];

    /**
     * Extensions an uploaded file's name may carry => the format each names.
     * Anything else - .html, .svg, .php, .exe, .zip, or no extension at all -
     * is refused before the content is even considered, whatever the bytes say.
     */
    private const NAMES = [
        'pdf' => 'pdf',
        'jpg' => 'jpg', 'jpeg' => 'jpg', 'jpe' => 'jpg', 'jfif' => 'jpg',
        'png' => 'png',
        'webp' => 'webp',
        'gif' => 'gif',
        'bmp' => 'bmp',
        'tif' => 'tif', 'tiff' => 'tif',
        'heic' => 'heic', 'heif' => 'heic',
        'doc' => 'doc', 'xls' => 'xls', 'ppt' => 'ppt',
        'docx' => 'docx', 'xlsx' => 'xlsx', 'pptx' => 'pptx',
        'odt' => 'odt', 'ods' => 'ods', 'odp' => 'odp',
        'rtf' => 'rtf',
        'txt' => 'txt',
        'csv' => 'csv',
    ];

    /** The folder inside a newer Office file that makes it that kind of document. */
    private const OFFICE_FOLDERS = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'];

    /** Stored formats a browser displays itself; everything else is downloaded. */
    private const SHOWN_IN_BROWSER = ['pdf', 'jpg', 'png', 'webp', 'gif', 'bmp'];

    private const DAMAGED = 'This document could not be read. It may be damaged; try saving it again.';

    /**
     * Validation rules for a document field. The content is checked here, the
     * same way it is checked again when the file is stored, so a refused file
     * comes back as a message on the field before any record is written.
     *
     * @param  array<string, string>|null  $types  A narrower allowlist, as for assertSafe()
     */
    public static function rules(bool $required = true, ?array $types = null): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'max:'.config('empower.uploads.max_size_kb'),
            static function (string $attribute, mixed $value, Closure $fail) use ($types) {
                // Anything that is not a file is reported by the 'file' rule.
                if (! $value instanceof UploadedFile) {
                    return;
                }

                try {
                    app(self::class)->assertSafe($value, $types);
                } catch (RuntimeException $exception) {
                    $fail($exception->getMessage());
                }
            },
        ];
    }

    /**
     * Refuses anything that is not an allowed, intact document and returns the
     * extension the file must be stored under.
     *
     * @param  array<string, string>|null  $types  A narrower allowlist of detected
     *                                             type => stored extension for one
     *                                             kind of upload, e.g. images only
     *                                             for photos
     */
    public function assertSafe(UploadedFile $file, ?array $types = null): string
    {
        if (! $file->isValid()) {
            throw new RuntimeException('The file could not be read. Please choose it again.');
        }

        $named = self::NAMES[strtolower($file->getClientOriginalExtension())] ?? null;

        if ($named === null) {
            throw new RuntimeException(
                'Files of this kind cannot be uploaded. Use a PDF, a photo, or a Word, Excel, PowerPoint or text document.'
            );
        }

        // Read from the bytes (finfo), not from the name or the request header.
        $mime = (string) $file->getMimeType();
        $extension = $types === null ? $this->formatOf($named, $mime) : ($types[$mime] ?? null);

        if ($extension === null) {
            throw new RuntimeException($types === null
                ? 'This file\'s contents cannot be uploaded; it appears to be '.$mime.'. Save it as a PDF, a photo or an Office document and try again.'
                : 'Only '.self::describe($types).' files can be used here.');
        }

        $maxBytes = (int) config('empower.uploads.max_size_kb', 10240) * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new RuntimeException('The uploaded file exceeds the allowed size limit.');
        }

        // finfo looks only at a file's first bytes. A PDF, RTF, HEIC photo,
        // older Office file or text file is recognised from those alone; an
        // image must also decode, and a zip-based document must open and hold
        // what makes it that kind of document.
        // (getimagesize() cannot read HEIC, so that photo rests on finfo.)
        if (in_array('application/zip', self::FORMATS[$extension], true)) {
            $this->assertZipDocument($file, $extension);
        } elseif (str_starts_with(self::FORMATS[$extension][0], 'image/') && $extension !== 'heic') {
            $this->assertImage($file, $extension);
        }

        return $extension;
    }

    /**
     * The content type a stored file is served with, from the extension it was
     * stored under, or null for one this policy never stores.
     */
    public static function servedType(string $extension): ?string
    {
        $format = self::NAMES[strtolower($extension)] ?? null;

        return $format === null ? null : self::FORMATS[$format][0];
    }

    /** Whether a browser shows the stored file itself, rather than downloading it. */
    public static function opensInBrowser(string $extension): bool
    {
        return in_array(self::NAMES[strtolower($extension)] ?? null, self::SHOWN_IN_BROWSER, true);
    }

    /**
     * The format the bytes are, given the format the name claims. When they
     * are some other accepted format - a photo saved as "scan.pdf" - the file
     * is stored as what it is, provided its type belongs to that format alone.
     */
    private function formatOf(string $named, string $mime): ?string
    {
        if (in_array($mime, self::FORMATS[$named], true)) {
            return $named;
        }

        foreach (self::FORMATS as $format => $types) {
            if ($types[0] === $mime) {
                return $format;
            }
        }

        return null;
    }

    /**
     * An image must decode as the type it claims to be, within the size limits.
     */
    private function assertImage(UploadedFile $file, string $extension): void
    {
        $dimensions = @getimagesize($file->getRealPath());
        $maxWidth = (int) config('empower.uploads.max_width', 7000);
        $maxHeight = (int) config('empower.uploads.max_height', 7000);

        if (! $dimensions || ! in_array($dimensions['mime'] ?? null, self::FORMATS[$extension], true)) {
            throw new RuntimeException('This image could not be read. It may be damaged; try saving it again.');
        }

        if ($dimensions[0] > $maxWidth || $dimensions[1] > $maxHeight) {
            throw new RuntimeException('The image dimensions exceed the allowed limit.');
        }
    }

    /**
     * A Word, Excel, PowerPoint or OpenDocument file is a zip. It must open as
     * one and hold the part that makes it the document it claims to be - which
     * is what lets through a genuine .docx that finfo saw only as "a zip".
     * Only the tiny OpenDocument "mimetype" entry is ever read out.
     */
    private function assertZipDocument(UploadedFile $file, string $extension): void
    {
        $zip = new ZipArchive();

        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(self::DAMAGED);
        }

        try {
            $intact = isset(self::OFFICE_FOLDERS[$extension])
                ? $zip->locateName('[Content_Types].xml') !== false && $this->holdsFolder($zip, self::OFFICE_FOLDERS[$extension])
                : trim((string) $zip->getFromName('mimetype', 100)) === self::FORMATS[$extension][0];
        } finally {
            $zip->close();
        }

        if (! $intact) {
            throw new RuntimeException(self::DAMAGED);
        }
    }

    private function holdsFolder(ZipArchive $zip, string $folder): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), $folder)) {
                return true;
            }
        }

        return false;
    }

    /** "PDF, JPG, PNG or WEBP", for a narrower list's refusal. */
    private static function describe(array $types): string
    {
        $names = array_map('strtoupper', array_values(array_unique($types)));
        $last = array_pop($names);

        return $names ? implode(', ', $names).' or '.$last : $last;
    }
}
