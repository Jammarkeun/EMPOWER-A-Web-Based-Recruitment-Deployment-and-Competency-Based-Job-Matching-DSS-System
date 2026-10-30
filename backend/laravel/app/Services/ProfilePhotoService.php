<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * A profile picture: storing it, replacing it, and handing out a link to
 * whoever is allowed to see it.
 *
 * Two kinds of owner. An applicant's picture is on their applicant record, set
 * from the portal. A staff account's - administrator, HR, or coordinator - is
 * on the account itself, set from Settings, because staff have no person
 * record to hold it.
 *
 * The picture goes on the same disk as applicant documents, through the same
 * storage service, under its own `profile-photos/` prefix. That keeps one
 * storage arrangement for the whole system — private bucket, random keys,
 * short-lived signed links — while never mixing a face shown on screen with the
 * employment documents HR verifies.
 *
 * Nothing here decides *who* may see a picture. A link is only ever minted
 * inside a response the caller was already authorised to receive, so the
 * existing permissions and the coordinator's client scope govern pictures
 * exactly as they govern the rest of the applicant's record.
 */
class ProfilePhotoService
{
    public const FOLDER = 'profile-photos';

    /**
     * Staff pictures, kept apart from applicants' because the two are numbered
     * separately: user 7 and applicant 7 are different people.
     */
    public const STAFF_FOLDER = self::FOLDER.'/staff';

    /**
     * Content types accepted, keyed to the extension the stored file is given.
     * The extension comes from the detected content, never from the filename,
     * so a picture renamed to .html is still stored and served as a picture.
     */
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly DocumentStorageService $storage,
        private readonly AuditService $audit,
    ) {
    }

    /** Validation rules for the uploaded field, checked against the file's content. */
    public function rules(): array
    {
        $min = (int) config('empower.uploads.profile_photo_min_px', 64);
        $maxWidth = (int) config('empower.uploads.max_width', 7000);
        $maxHeight = (int) config('empower.uploads.max_height', 7000);

        return [
            'required',
            'file',
            'max:'.(int) config('empower.uploads.profile_photo_max_kb', 5120),
            'mimes:jpg,jpeg,png,webp',
            "dimensions:min_width={$min},min_height={$min},max_width={$maxWidth},max_height={$maxHeight}",
        ];
    }

    /** Messages written for the applicant rather than for a developer. */
    public function messages(string $field = 'photo'): array
    {
        $maxMb = round(config('empower.uploads.profile_photo_max_kb', 5120) / 1024);
        $min = (int) config('empower.uploads.profile_photo_min_px', 64);

        return [
            "{$field}.required" => 'Choose a picture to upload.',
            "{$field}.file" => 'The picture could not be uploaded. Please try again.',
            "{$field}.uploaded" => "The picture could not be uploaded. It may be larger than {$maxMb} MB.",
            "{$field}.max" => "The picture must be {$maxMb} MB or smaller.",
            "{$field}.mimes" => 'Use a JPG, PNG, or WebP image.',
            "{$field}.dimensions" => "The picture must be at least {$min} × {$min} pixels and no more than 7000 pixels on either side.",
        ];
    }

    /**
     * Replaces the applicant's or staff account's picture with the uploaded one.
     *
     * Ordered so a failure at any step leaves the owner with a working
     * picture: the new file is stored first, the record is pointed at it second,
     * and only then is the old file removed. If the record cannot be updated,
     * the new file is removed instead, so nothing is left orphaned and the old
     * picture is still the one on file.
     */
    public function replace(Applicant|User $owner, UploadedFile $file, string $field = 'photo'): Applicant|User
    {
        $this->assertDecodableImage($file, $field);

        $isApplicant = $owner instanceof Applicant;

        // Images only: the storage service checks the content again against this
        // narrower list and names the file by the detected type.
        $stored = $this->storage->store($file, $isApplicant ? self::FOLDER : self::STAFF_FOLDER, $owner->id, self::TYPES);
        $previous = $owner->profile_photo_path;

        try {
            DB::transaction(function () use ($owner, $isApplicant, $stored, $previous) {
                // Set directly rather than mass-assigned: the column is not
                // fillable on either model, so no edit form or profile update
                // can reach it.
                $owner->forceFill(['profile_photo_path' => $stored['file_path']])->save();

                $this->audit->record(
                    action: 'update',
                    module: $isApplicant ? 'portal' : 'auth',
                    recordType: $owner::class,
                    recordId: $owner->id,
                    newValues: array_filter([
                        'profile_photo' => $previous ? 'replaced' : 'added',
                        'applicant_code' => $isApplicant ? $owner->applicant_code : null,
                    ]),
                );
            });
        } catch (Throwable $exception) {
            $owner->forceFill(['profile_photo_path' => $previous]);
            $this->deleteQuietly($stored['file_path']);

            // The cause is logged for whoever maintains the system; the
            // applicant is told plainly that nothing changed. Re-throwing a
            // database exception as-is would surface its SQLSTATE text as the
            // error and, because that code is a string, fail inside the
            // application's own error handler as well.
            report($exception);

            throw new RuntimeException(
                'Your picture could not be saved, so your current picture is unchanged. Please try again.',
                500,
                $exception
            );
        }

        if ($previous && $previous !== $stored['file_path']) {
            $this->deleteQuietly($previous);
        }

        return $owner;
    }

    /**
     * A display link for a stored picture, or null when there is none.
     *
     * Never checks that the file still exists: on remote storage that is a
     * network round trip per row of every list. A link to a missing file simply
     * fails to load, and the screen falls back to the person's initials.
     */
    public function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        try {
            return $this->storage->temporaryUrl(
                $path,
                (int) config('empower.uploads.profile_photo_url_ttl_minutes', 60)
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Confirms the file really is one of the accepted image types and can be
     * read as an image, returning the extension it should be stored under.
     *
     * The validation rules already sniff the content; this repeats the check on
     * the decoded header so a file whose bytes merely start like an image, or
     * whose detected type disagrees with its own header, is refused.
     */
    private function assertDecodableImage(UploadedFile $file, string $field): string
    {
        $mime = $file->getMimeType();
        $extension = self::TYPES[$mime] ?? null;
        $info = @getimagesize($file->getRealPath());

        if (! $extension) {
            throw ValidationException::withMessages([$field => 'Use a JPG, PNG, or WebP image.']);
        }

        if (! $info || ($info['mime'] ?? null) !== $mime) {
            throw ValidationException::withMessages([
                $field => 'This file could not be read as an image. It may be damaged; try saving it again.',
            ]);
        }

        return $extension;
    }

    /**
     * Removes a stored picture without letting a storage hiccup undo work that
     * has already succeeded. A leftover file is far less harmful than reporting
     * a saved picture as a failure.
     */
    private function deleteQuietly(?string $path): void
    {
        try {
            $this->storage->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
