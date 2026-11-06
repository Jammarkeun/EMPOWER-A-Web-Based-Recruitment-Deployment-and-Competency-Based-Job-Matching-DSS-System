<?php

namespace App\Services;

use App\Models\AttendanceImport;
use App\Models\CoordinatorAssignment;
use App\Models\DtrSubmission;
use App\Models\DtrSubmissionEvent;
use App\Models\User;
use App\Notifications\DtrSubmissionReviewed;
use App\Notifications\DtrSubmissionSubmitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * A client's DTR file, handed over by the coordinator and taken in by HR.
 *
 * This is a handover, not an importer. The coordinator's file is checked with
 * the importer's own file checks and kept as received. When HR opens it, its
 * bytes go through DtrImportService::upload exactly as a file HR uploads
 * would - same checks, same duplicate rules, same DTR period - and become an
 * ordinary import draft linked back here. Columns, preview, validation and
 * the import itself are the existing importer's; this service only follows
 * the outcome: "imported" is set from inside the importer's own confirm
 * transaction, so a failed import can never leave a submission marked imported.
 *
 * Nothing is overwritten. A returned file stays as it was; the coordinator
 * corrects it by sending a new submission that points back to it.
 */
class DtrSubmissionService
{
    public function __construct(
        private readonly DtrImportService $imports,
        private readonly DocumentStorageService $storage,
        private readonly AuditService $audit,
        private readonly ReferenceCodeService $codes,
    ) {
    }

    // ------------------------------------------------------------ coordinator

    /**
     * @param  array{period_from: string, period_to: string, source_note?: ?string}  $data
     * @param  DtrSubmission|null  $correcting  the returned submission this file corrects
     */
    public function submit(CoordinatorAssignment $assignment, User $coordinator, UploadedFile $file, array $data, ?DtrSubmission $correcting = null): DtrSubmission
    {
        $companyId = (int) $assignment->client_company_id;

        if ($correcting) {
            if ((int) $correcting->client_company_id !== $companyId) {
                throw new RuntimeException('This submission is not under your assigned client company.', 403);
            }

            if ($correcting->status !== 'returned') {
                throw new RuntimeException('Only a submission HR returned for correction can be corrected.', 409);
            }

            if ($correcting->resubmission()->exists()) {
                throw new RuntimeException('A corrected file has already been sent for this submission.', 409);
            }
        }

        // The importer's own checks: the bytes are what the name says and
        // can be read as rows, and the file has not already been imported.
        $checked = $this->imports->checkFile($file);
        $this->imports->assertNotAlreadyImported($checked['hash']);

        $pending = DtrSubmission::query()
            ->where('file_hash', $checked['hash'])
            ->whereIn('status', [...DtrSubmission::PENDING, 'imported'])
            ->first();

        if ($pending) {
            throw new RuntimeException(sprintf(
                'This exact file was already sent as %s (%s). It does not need to be sent again.',
                $pending->submission_code,
                strtolower($pending->label())
            ), 409);
        }

        $stored = $this->storage->storeChecked($file, 'dtr-submissions', $companyId, $checked['extension']);

        try {
            $submission = DB::transaction(function () use ($companyId, $coordinator, $data, $checked, $stored, $correcting) {
                $submission = DtrSubmission::create([
                    'submission_code' => $this->codes->dtrSubmission(),
                    'client_company_id' => $companyId,
                    'period_from' => $data['period_from'],
                    'period_to' => $data['period_to'],
                    'source_note' => filled($data['source_note'] ?? null) ? trim($data['source_note']) : null,
                    'original_filename' => $stored['file_name'] ?: 'dtr.'.$checked['extension'],
                    'file_disk' => (string) config('empower.uploads.disk'),
                    'file_path' => $stored['file_path'],
                    'file_hash' => $checked['hash'],
                    'file_size_bytes' => (int) $stored['file_size_bytes'],
                    'total_rows' => max(0, count($checked['rows']) - 1),
                    'status' => 'submitted',
                    'resubmission_of_id' => $correcting?->id,
                    'submitted_by' => $coordinator->id,
                    'submitted_at' => now(),
                ]);

                $this->event($submission, null, 'submitted', $coordinator,
                    $correcting ? 'Corrected file for '.$correcting->submission_code : null);

                return $submission;
            });
        } catch (Throwable $e) {
            // Nothing refers to the stored copy if the record was never made.
            $this->storage->delete($stored['file_path']);

            throw $e;
        }

        $this->audit->record('submit', 'attendance', DtrSubmission::class, $submission->id, null, array_filter([
            'submission_code' => $submission->submission_code,
            'file' => $submission->original_filename,
            'period' => $submission->period_from->toDateString().' to '.$submission->period_to->toDateString(),
            'source_note' => $submission->source_note,
            'corrects' => $correcting?->submission_code,
        ]), clientCompanyId: $companyId);

        $submission->load(['company', 'submitter']);

        User::permission('attendance.manage')
            ->where('is_active', true)
            ->whereKeyNot($coordinator->id)
            ->get()
            ->each->notify(new DtrSubmissionSubmitted($submission));

        return $submission;
    }

    // -------------------------------------------------------------------- HR

    /**
     * Opens the submitted file in the existing DTR importer.
     *
     * The stored bytes are handed to DtrImportService::upload as an uploaded
     * file, so the import draft is made exactly as one HR uploads: the same
     * content and duplicate checks, the same DTR period rules. A draft already
     * opened from this file is returned rather than a second one made.
     */
    public function startImport(DtrSubmission $submission, User $hr): AttendanceImport
    {
        $this->assertHr($hr);

        return DB::transaction(function () use ($submission, $hr) {
            $locked = DtrSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, DtrSubmission::PENDING, true)) {
                throw new RuntimeException(sprintf('This submission is %s, so it cannot be imported.', strtolower($locked->label())), 409);
            }

            $draft = AttendanceImport::query()
                ->where('dtr_submission_id', $locked->id)
                ->where('status', 'draft')
                ->first();

            if ($draft) {
                return $draft;
            }

            $clients = $this->imports->importableClients($hr)->pluck('id')->map(fn ($id) => (int) $id);

            if (! $clients->contains((int) $locked->client_company_id)) {
                throw new RuntimeException('A DTR can only be imported for a client with workers placed there.', 409);
            }

            $path = tempnam(sys_get_temp_dir(), 'dtrs');

            try {
                file_put_contents($path, $this->storage->contents($locked->file_path));
                $file = new UploadedFile($path, $locked->original_filename, null, null, true);

                $import = $this->imports->upload($file, [
                    'client_company_id' => $locked->client_company_id,
                    'period_from' => $locked->period_from->toDateString(),
                    'period_to' => $locked->period_to->toDateString(),
                    'source_note' => trim('From coordinator submission '.$locked->submission_code.'. '.($locked->source_note ?? '')),
                    'dtr_submission_id' => $locked->id,
                ], $hr);
            } finally {
                @unlink($path);
            }

            $from = $locked->status;
            $locked->update(['status' => 'under_review', 'reviewed_by' => $hr->id, 'reviewed_at' => now()]);
            $this->event($locked, $from, 'under_review', $hr, 'Opened in the DTR importer as '.$import->import_code, $import->id);

            $this->audit->record('start_import', 'attendance', DtrSubmission::class, $locked->id, ['status' => $from], [
                'status' => 'under_review',
                'import_code' => $import->import_code,
            ], clientCompanyId: $locked->client_company_id);

            return $import;
        });
    }

    /** Sends the file back to the coordinator with what needs correcting. */
    public function returnToCoordinator(DtrSubmission $submission, User $hr, ?string $note): DtrSubmission
    {
        return $this->decide($submission, $hr, 'returned', $note);
    }

    /** Refuses the file outright, for example one that is not this client's DTR at all. */
    public function reject(DtrSubmission $submission, User $hr, ?string $note): DtrSubmission
    {
        return $this->decide($submission, $hr, 'rejected', $note);
    }

    private function decide(DtrSubmission $submission, User $hr, string $to, ?string $note): DtrSubmission
    {
        $this->assertHr($hr);

        if (mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages([
                'note' => [$to === 'returned'
                    ? 'Say what needs correcting, so the coordinator knows what to send instead.'
                    : 'Say why the file is rejected.'],
            ]);
        }

        // An import draft opened from the file is abandoned first; nothing
        // was written from it.
        AttendanceImport::query()
            ->where('dtr_submission_id', $submission->id)
            ->where('status', 'draft')
            ->get()
            ->each(fn (AttendanceImport $draft) => $this->imports->discard($draft, $hr));

        $from = DB::transaction(function () use ($submission, $hr, $to, $note) {
            $locked = DtrSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, DtrSubmission::PENDING, true)) {
                throw new RuntimeException(sprintf(
                    'This submission is %s, so it cannot be %s.',
                    strtolower($locked->label()),
                    $to
                ), 409);
            }

            $from = $locked->status;
            $locked->update([
                'status' => $to,
                'reviewed_by' => $hr->id,
                'reviewed_at' => now(),
                'review_note' => trim($note),
            ]);
            $this->event($locked, $from, $to, $hr, trim($note));

            return $from;
        });

        $this->audit->record($to === 'returned' ? 'return' : 'reject', 'attendance', DtrSubmission::class, $submission->id,
            ['status' => $from], ['status' => $to, 'note' => trim($note)], clientCompanyId: $submission->client_company_id);

        $submission->refresh()->load(['company', 'submitter']);
        $this->notifySubmitter($submission);

        return $submission;
    }

    // ------------------------------------------------- called by the importer

    /**
     * The import opened from this submission was confirmed. Runs inside the
     * importer's confirm transaction, so it commits or rolls back with it.
     */
    public function imported(AttendanceImport $import, User $actor): void
    {
        $submission = DtrSubmission::query()->whereKey($import->dtr_submission_id)->lockForUpdate()->firstOrFail();

        if (! in_array($submission->status, DtrSubmission::PENDING, true)) {
            throw new RuntimeException(sprintf(
                'The coordinator\'s submission %s is %s, so this file cannot be imported from it.',
                $submission->submission_code,
                strtolower($submission->label())
            ), 409);
        }

        $from = $submission->status;
        $submission->update([
            'status' => 'imported',
            'attendance_import_id' => $import->id,
            'imported_at' => now(),
        ]);
        $this->event($submission, $from, 'imported', $actor, sprintf(
            'Imported as %s: %d day(s) added, %d replaced, %d problem row(s)',
            $import->import_code,
            $import->imported_rows,
            $import->replaced_rows,
            $import->error_rows
        ), $import->id);

        $this->audit->record('import', 'attendance', DtrSubmission::class, $submission->id, ['status' => $from], [
            'status' => 'imported',
            'import_code' => $import->import_code,
        ], clientCompanyId: $submission->client_company_id, actor: $actor);

        // Only once the import is committed does anyone hear of it.
        DB::afterCommit(function () use ($submission) {
            $this->notifySubmitter($submission->fresh(['company', 'submitter', 'import']));
        });
    }

    /** An import draft opened from this submission was abandoned. */
    public function draftDiscarded(AttendanceImport $import, User $actor): void
    {
        $submission = DtrSubmission::find($import->dtr_submission_id);

        if (! $submission || $submission->status !== 'under_review') {
            return;
        }

        $submission->update(['status' => 'submitted']);
        $this->event($submission, 'under_review', 'submitted', $actor,
            'Import draft '.$import->import_code.' discarded; waiting for HR again', $import->id);
    }

    // ---------------------------------------------------------------- helpers

    private function assertHr(User $user): void
    {
        if ($user->isCoordinator() || ! $user->can('attendance.manage')) {
            throw new RuntimeException('Only HR can review or import a DTR submission.', 403);
        }
    }

    private function notifySubmitter(DtrSubmission $submission): void
    {
        if ($submission->submitter?->is_active) {
            $submission->submitter->notify(new DtrSubmissionReviewed($submission));
        }
    }

    private function event(DtrSubmission $submission, ?string $from, string $to, User $actor, ?string $note, ?int $importId = null): void
    {
        DtrSubmissionEvent::create([
            'dtr_submission_id' => $submission->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'attendance_import_id' => $importId,
            'actor_id' => $actor->id,
        ]);
    }
}
