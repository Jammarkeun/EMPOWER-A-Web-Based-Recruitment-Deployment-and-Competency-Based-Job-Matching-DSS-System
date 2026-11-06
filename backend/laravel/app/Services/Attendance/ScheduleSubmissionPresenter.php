<?php

namespace App\Services\Attendance;

use App\Models\ScheduleSubmission;
use App\Models\ScheduleSubmissionEvent;
use App\Services\ScheduleSubmissionService;

/**
 * How a coordinator's schedule submission reads, for HR and the coordinator
 * alike, so both see the same proposal, checks and history.
 */
final class ScheduleSubmissionPresenter
{
    public function __construct(
        private readonly AttendancePresenter $attendance,
        private readonly ScheduleSubmissionService $submissions,
    ) {
    }

    public function row(ScheduleSubmission $s): array
    {
        $proposal = $s->toSchedule();

        return [
            'id' => $s->id,
            'submission_code' => $s->submission_code,
            'status' => $s->status,
            'status_label' => ScheduleSubmission::LABELS[$s->status] ?? $s->status,
            'is_editable' => $s->isEditable(),
            'employee' => [
                'id' => $s->employee_id,
                'full_name' => $s->employee?->full_name,
                'employee_number' => $s->employee?->employee_number,
                'biometric_number' => $s->employee?->biometric_number,
                'employment_status' => $s->employee?->employment_status,
            ],
            'client_company_id' => $s->client_company_id,
            'company' => $s->company?->company_name,
            'department' => $s->department?->department_name,
            'effective_from' => $s->effective_from?->toDateString(),
            'effective_to' => $s->effective_to?->toDateString(),
            'work_days' => array_map('intval', $s->work_days ?? []),
            'shift_start' => $proposal->time('shift_start'),
            'shift_end' => $proposal->time('shift_end'),
            'crosses_midnight' => $proposal->crossesMidnight(),
            'break_start' => $proposal->time('break_start'),
            'break_minutes' => $s->break_minutes,
            'description' => $proposal->describe(),
            'remarks' => $s->remarks,
            'source_note' => $s->source_note,
            'created_by' => $s->creator?->full_name,
            'created_by_id' => $s->created_by,
            'created_at' => $s->created_at?->toIso8601String(),
            'submitted_at' => $s->submitted_at?->toIso8601String(),
            'reviewed_by' => $s->reviewer?->full_name,
            'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            'review_note' => $s->review_note,
            'employee_schedule_id' => $s->employee_schedule_id,
        ];
    }

    /**
     * The submission in full: the placement it is for, the approved schedule
     * it would replace and what differs, what the checks found, and every
     * step so far.
     */
    public function detail(ScheduleSubmission $s): array
    {
        $s->loadMissing(['employee.applicant', 'company', 'department', 'deployment.department', 'creator', 'reviewer', 'events.actor', 'replaces']);
        $open = in_array($s->status, ScheduleSubmission::OPEN, true);

        // Still open: what it would replace today. Decided: what it replaced
        // (or would have) when it was last sent to HR.
        $current = $open ? $this->submissions->replacedSchedule($s) : $s->replaces?->loadMissing(['company', 'department', 'creator']);
        $proposed = $this->row($s);
        $currentPresented = $this->attendance->schedule($current);

        return $proposed + [
            'deployment' => $s->deployment ? [
                'id' => $s->deployment->id,
                'deployment_code' => $s->deployment->deployment_code,
                'position' => $s->deployment->position_title,
                'department' => $s->deployment->department?->department_name,
                'supervisor' => $s->deployment->supervisor_name,
                'deployment_date' => $s->deployment->deployment_date?->toDateString(),
                'end_date' => $s->deployment->end_date?->toDateString(),
                'deployment_status' => $s->deployment->deployment_status,
            ] : null,
            'hire_date' => $s->employee?->hire_date?->toDateString(),
            'current_schedule' => $currentPresented,
            'changes' => $currentPresented ? $this->changes($currentPresented, $proposed) : [],
            'checks' => $open ? $this->submissions->checks($s) : [],
            'events' => $s->events->map(fn (ScheduleSubmissionEvent $e) => [
                'id' => $e->id,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'label' => $this->eventLabel($e),
                'note' => $e->note,
                'changes' => $e->changes,
                'by' => $e->actor?->full_name,
                'at' => $e->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** The fields where the proposal differs from the schedule it replaces. */
    private function changes(array $current, array $proposed): array
    {
        $fields = ['work_days', 'shift_start', 'shift_end', 'break_start', 'break_minutes'];

        return collect($fields)
            ->filter(fn ($f) => json_encode($current[$f] ?? null) !== json_encode($proposed[$f] ?? null))
            ->values()
            ->all();
    }

    private function eventLabel(ScheduleSubmissionEvent $e): string
    {
        return match (true) {
            $e->from_status === null => 'Draft started',
            $e->from_status === $e->to_status => 'Edited',
            $e->to_status === 'submitted' && $e->from_status === 'returned' => 'Resubmitted to HR',
            $e->to_status === 'submitted' => 'Submitted to HR',
            $e->to_status === 'approved' => 'Approved by HR',
            $e->to_status === 'returned' => 'Returned for correction',
            $e->to_status === 'withdrawn' => 'Withdrawn',
            default => ScheduleSubmission::LABELS[$e->to_status] ?? $e->to_status,
        };
    }
}
