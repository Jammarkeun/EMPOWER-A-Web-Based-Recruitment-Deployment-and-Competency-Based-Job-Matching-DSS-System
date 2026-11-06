<?php

namespace App\Services\Attendance;

use App\Models\AttendanceImport;
use App\Models\DtrSubmission;
use App\Models\DtrSubmissionEvent;

/**
 * How a coordinator's DTR submission reads, for HR and the coordinator alike.
 * The storage key of the file is never sent; it is fetched through its own
 * endpoint by those allowed to see it.
 */
final class DtrSubmissionPresenter
{
    public function row(DtrSubmission $s): array
    {
        return [
            'id' => $s->id,
            'submission_code' => $s->submission_code,
            'status' => $s->status,
            'status_label' => $s->label(),
            'client_company_id' => $s->client_company_id,
            'company' => $s->company?->company_name,
            'period_from' => $s->period_from?->toDateString(),
            'period_to' => $s->period_to?->toDateString(),
            'source_note' => $s->source_note,
            'original_filename' => $s->original_filename,
            'file_size_bytes' => $s->file_size_bytes,
            'total_rows' => $s->total_rows,
            'submitted_by' => $s->submitter?->full_name,
            'submitted_by_id' => $s->submitted_by,
            'submitted_at' => $s->submitted_at?->toIso8601String(),
            'reviewed_by' => $s->reviewer?->full_name,
            'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            'review_note' => $s->review_note,
            'imported_at' => $s->imported_at?->toIso8601String(),
            'import_code' => $s->import?->import_code,
            'resubmission_of_id' => $s->resubmission_of_id,
        ];
    }

    public function detail(DtrSubmission $s): array
    {
        $s->loadMissing(['company', 'submitter', 'reviewer', 'import.completer', 'imports', 'events.actor', 'events.import',
            'resubmissionOf', 'resubmission']);

        return $this->row($s) + [
            'import' => $s->import ? [
                'id' => $s->import->id,
                'import_code' => $s->import->import_code,
                'imported_rows' => $s->import->imported_rows,
                'replaced_rows' => $s->import->replaced_rows,
                'unchanged_rows' => $s->import->unchanged_rows,
                'skipped_rows' => $s->import->skipped_rows,
                'error_rows' => $s->import->error_rows,
                'completed_by' => $s->import->completer?->full_name,
                'completed_at' => $s->import->completed_at?->toIso8601String(),
            ] : null,
            // An import draft HR has opened from this file and not finished.
            'draft_import_id' => $s->imports->first(fn (AttendanceImport $i) => $i->isDraft())?->id,
            'resubmission_of' => $s->resubmissionOf ? [
                'id' => $s->resubmissionOf->id,
                'submission_code' => $s->resubmissionOf->submission_code,
                'review_note' => $s->resubmissionOf->review_note,
            ] : null,
            'resubmission' => $s->resubmission ? [
                'id' => $s->resubmission->id,
                'submission_code' => $s->resubmission->submission_code,
                'status' => $s->resubmission->status,
                'status_label' => $s->resubmission->label(),
            ] : null,
            'events' => $s->events->map(fn (DtrSubmissionEvent $e) => [
                'id' => $e->id,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'label' => DtrSubmission::LABELS[$e->to_status] ?? $e->to_status,
                'note' => $e->note,
                'import_code' => $e->import?->import_code,
                'by' => $e->actor?->full_name,
                'at' => $e->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
