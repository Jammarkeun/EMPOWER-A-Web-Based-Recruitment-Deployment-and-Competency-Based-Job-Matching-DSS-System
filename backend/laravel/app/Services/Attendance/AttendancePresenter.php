<?php

namespace App\Services\Attendance;

use App\Models\AttendanceException;
use App\Models\AttendanceImport;
use App\Models\AttendanceRecordRevision;
use App\Models\AttendanceReview;
use App\Models\AttendanceReviewEvent;
use App\Models\AuditLog;
use App\Models\EmployeeSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How attendance records read in the API, shared by every attendance screen
 * so the same schedule or review looks the same wherever it appears.
 */
final class AttendancePresenter
{
    public function schedule(?EmployeeSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        $today = CarbonImmutable::today();

        return [
            'id' => $schedule->id,
            'effective_from' => $schedule->effective_from?->toDateString(),
            'effective_to' => $schedule->effective_to?->toDateString(),
            'work_days' => array_map('intval', $schedule->work_days ?? []),
            'shift_start' => $schedule->time('shift_start'),
            'shift_end' => $schedule->time('shift_end'),
            'crosses_midnight' => $schedule->crossesMidnight(),
            'break_start' => $schedule->time('break_start'),
            'break_minutes' => $schedule->break_minutes,
            'description' => $schedule->describe(),
            'remarks' => $schedule->remarks,
            'status' => $schedule->status,
            'period_status' => match (true) {
                $schedule->isVoided() => 'voided',
                $schedule->effective_from->startOfDay()->greaterThan($today) => 'upcoming',
                $schedule->effective_to !== null && $schedule->effective_to->startOfDay()->lessThan($today) => 'ended',
                default => 'current',
            },
            'company' => $schedule->relationLoaded('company') ? $schedule->company?->company_name : null,
            'department' => $schedule->relationLoaded('department') ? $schedule->department?->department_name : null,
            'set_by' => $schedule->relationLoaded('creator') ? $schedule->creator?->full_name : null,
            'set_at' => $schedule->created_at?->toIso8601String(),
            'voided_at' => $schedule->voided_at?->toIso8601String(),
            'voided_by' => $schedule->relationLoaded('voider') ? $schedule->voider?->full_name : null,
            'void_reason' => $schedule->void_reason,
        ];
    }

    /**
     * Corrections and voids of these schedules, read back from the audit
     * trail where they were written with their old values and reason.
     */
    public function scheduleChanges(Collection $schedules): array
    {
        if ($schedules->isEmpty()) {
            return [];
        }

        return AuditLog::query()
            ->with('actor')
            ->where('record_type', 'EmployeeSchedule')
            ->whereIn('record_id', $schedules->pluck('id'))
            ->whereIn('action_type', ['update', 'void'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditLog $log) => [
                'schedule_id' => (int) $log->record_id,
                'action' => $log->action_type,
                'at' => $log->created_at?->toIso8601String(),
                'by' => $log->actor?->full_name,
                'reason' => $log->new_values_json['reason'] ?? null,
                'before' => $log->old_values_json,
                'after' => array_diff_key($log->new_values_json ?? [], array_flip(['reason'])),
            ])
            ->values()
            ->all();
    }

    /**
     * A compared day as both attendance views show it. The review status is
     * worked out here, once, rather than by each screen.
     */
    public function day(array $day, ?AttendanceReview $review): array
    {
        return $day + [
            'discrepancy_labels' => array_map(
                fn ($type) => AttendanceComparison::DISCREPANCY_LABELS[$type] ?? $type,
                $day['discrepancies']
            ),
            'still_flagged' => $day['status'] === 'discrepancy',
            'review_status' => AttendanceReview::statusFor($day, $review),
            'review' => $this->review($review),
        ];
    }

    /**
     * How many days of a period stand at each review status, counted from
     * presented days exactly as the review queue counts them.
     *
     * @param  iterable<array>  $days  presented days
     */
    public function reviewCounts(iterable $days): array
    {
        $counts = array_fill_keys(array_keys(AttendanceReview::LABELS), 0);

        foreach ($days as $day) {
            if ($day['review_status'] !== null) {
                $counts[$day['review_status']]++;
            }
        }

        return $counts + ['open' => array_sum($counts) - $counts['closed']];
    }

    public function review(?AttendanceReview $review): ?array
    {
        if (! $review) {
            return null;
        }

        return [
            'id' => $review->id,
            'status' => $review->status,
            'status_label' => $review->label(),
            'discrepancy_types' => $review->discrepancy_types ?? [],
            'latest_note' => $review->latest_note,
            'employee_violation_id' => $review->employee_violation_id,
            'closed_at' => $review->closed_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    public function reviewEvent(AttendanceReviewEvent $event): array
    {
        return [
            'id' => $event->id,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'to_label' => AttendanceReview::LABELS[$event->to_status] ?? $event->to_status,
            'note' => $event->note,
            'snapshot' => $event->snapshot,
            'by' => $event->actor?->full_name,
            'at' => $event->created_at?->toIso8601String(),
        ];
    }

    public function revision(AttendanceRecordRevision $revision): array
    {
        $time = fn ($v) => $v?->format('H:i');

        return [
            'id' => $revision->id,
            'action' => $revision->action,
            'old' => ['time_in' => $time($revision->old_time_in), 'time_out' => $time($revision->old_time_out), 'remarks' => $revision->old_remarks, 'source' => $revision->old_source],
            'new' => ['time_in' => $time($revision->new_time_in), 'time_out' => $time($revision->new_time_out), 'remarks' => $revision->new_remarks, 'source' => $revision->new_source],
            'reason' => $revision->reason,
            'import_code' => $revision->import?->import_code,
            'by' => $revision->changer?->full_name,
            'at' => $revision->created_at?->toIso8601String(),
        ];
    }

    public function exception(AttendanceException $exception): array
    {
        return [
            'id' => $exception->id,
            'scope' => $exception->employee_id ? 'employee' : 'client',
            'employee_id' => $exception->employee_id,
            'employee_number' => $exception->employee?->employee_number,
            'employee_name' => $exception->employee?->full_name,
            'client_company_id' => $exception->client_company_id,
            'company' => $exception->company?->company_name,
            'client_department_id' => $exception->client_department_id,
            'department' => $exception->department?->department_name,
            'exception_type' => $exception->exception_type,
            'label' => $exception->label(),
            'date_from' => $exception->date_from->toDateString(),
            'date_to' => $exception->date_to->toDateString(),
            'remarks' => $exception->remarks,
            'recorded_by' => $exception->recorder?->full_name,
            'recorded_at' => $exception->created_at?->toIso8601String(),
            'voided_at' => $exception->voided_at?->toIso8601String(),
            'void_reason' => $exception->void_reason,
        ];
    }

    public function import(AttendanceImport $import, bool $withResults = false): array
    {
        return [
            'id' => $import->id,
            'import_code' => $import->import_code,
            'status' => $import->status,
            'client_company_id' => $import->client_company_id,
            'company' => $import->company?->company_name,
            'period_from' => $import->period_from->toDateString(),
            'period_to' => $import->period_to->toDateString(),
            'source_note' => $import->source_note,
            'original_filename' => $import->original_filename,
            'file_size_bytes' => $import->file_size_bytes,
            'has_file' => $import->file_path !== null,
            'total_rows' => $import->total_rows,
            'imported_rows' => $import->imported_rows,
            'replaced_rows' => $import->replaced_rows,
            'unchanged_rows' => $import->unchanged_rows,
            'skipped_rows' => $import->skipped_rows,
            'error_rows' => $import->error_rows,
            'uploaded_by' => $import->uploader?->full_name,
            'uploaded_at' => $import->created_at?->toIso8601String(),
            'completed_by' => $import->completer?->full_name,
            'completed_at' => $import->completed_at?->toIso8601String(),
            'discarded_at' => $import->discarded_at?->toIso8601String(),
        ] + ($withResults ? ['results' => $import->results, 'column_mapping' => $import->column_mapping] : []);
    }
}
