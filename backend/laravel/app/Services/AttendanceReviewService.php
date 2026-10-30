<?php

namespace App\Services;

use App\Models\AttendanceReview;
use App\Models\AttendanceReviewEvent;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A person's review of a day the comparison flagged.
 *
 * The comparison can only say that the DTR and the schedule disagree. Whether
 * the difference was real - a late arrival - or a gap in the data - a missed
 * punch the client can explain, a DTR typed against the wrong day - is found
 * out by asking, and recorded here: for review, under verification, verified,
 * corrected, closed. Each step keeps who took it and what they found.
 *
 * Verifying a day is not a sanction. A verified discrepancy may then be
 * recorded as a violation, but only through the existing violation workflow
 * (EmployeeController::storeViolation and ViolationService), which this
 * service is told about afterwards so the review keeps the link.
 */
class AttendanceReviewService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditService $audit,
    ) {
    }

    public function transition(Employee $employee, CarbonImmutable $date, string $to, ?string $note, User $actor): AttendanceReview
    {
        if (! array_key_exists($to, AttendanceReview::LABELS) || $to === 'for_review') {
            throw new RuntimeException('Choose what the review found.', 422);
        }

        $day = $this->attendance->compareDay($employee, $date);
        $note = filled($note) ? trim($note) : null;

        return DB::transaction(function () use ($employee, $date, $to, $note, $actor, $day) {
            $review = AttendanceReview::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            // A review starts from something the comparison actually flagged.
            // Once open it stays, even if a correction clears the flag - that
            // is exactly when somebody needs to close it. The same rule the
            // queue and the employee's tab read.
            $from = AttendanceReview::statusFor($day, $review);

            if ($from === null) {
                throw new RuntimeException('Nothing on this day is flagged for review.', 422);
            }

            if (! in_array($to, AttendanceReview::TRANSITIONS[$from], true)) {
                throw new RuntimeException(sprintf(
                    'A review that is "%s" cannot move to "%s".',
                    AttendanceReview::LABELS[$from],
                    AttendanceReview::LABELS[$to]
                ), 422);
            }

            if ((in_array($to, AttendanceReview::NOTE_REQUIRED, true) || $from === 'closed') && $note === null) {
                throw new RuntimeException($from === 'closed'
                    ? 'Say why this review is being reopened.'
                    : 'Say what was found. The note is kept with the review.', 422);
            }

            if ($to === 'corrected' && $from === 'verified' && $review?->employee_violation_id) {
                throw new RuntimeException('A violation has already been recorded from this review. Resolve the violation itself rather than marking the day corrected.', 422);
            }

            $snapshot = $this->snapshot($day);

            $review ??= new AttendanceReview([
                'employee_id' => $employee->id,
                'work_date' => $date->toDateString(),
                'opened_by' => $actor->id,
            ]);

            $review->fill([
                'client_company_id' => $day['client_company_id'],
                'client_department_id' => $day['client_department_id'],
                'status' => $to,
                // What was flagged is kept when a correction has since cleared it.
                'discrepancy_types' => $day['discrepancies'] ?: ($review->discrepancy_types ?? []),
                'snapshot' => $snapshot,
                'latest_note' => $note ?? $review->latest_note,
                'updated_by' => $actor->id,
                'closed_at' => $to === 'closed' ? now() : null,
            ])->save();

            AttendanceReviewEvent::create([
                'attendance_review_id' => $review->id,
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
                'snapshot' => $snapshot,
                'actor_user_id' => $actor->id,
            ]);

            $this->audit->record('review', 'attendance', AttendanceReview::class, $review->id, ['status' => $from], [
                'status' => $to,
                'employee_number' => $employee->employee_number,
                'work_date' => $date->toDateString(),
                'discrepancies' => $review->discrepancy_types,
            ], clientCompanyId: $review->client_company_id);

            return $review;
        });
    }

    /**
     * Refuses a violation that the review cannot stand behind.
     */
    public function assertCanLinkViolation(AttendanceReview $review, Employee $employee, string $violationDate): void
    {
        if ($review->employee_id !== $employee->id) {
            throw new RuntimeException('That attendance review belongs to another employee.', 422);
        }

        if ($review->status !== 'verified') {
            throw new RuntimeException('Only a verified attendance discrepancy can be recorded as a violation. Verify it first.', 422);
        }

        if ($review->employee_violation_id !== null) {
            throw new RuntimeException('A violation has already been recorded from this attendance review.', 409);
        }

        if (CarbonImmutable::parse($violationDate)->toDateString() !== $review->work_date->toDateString()) {
            throw new RuntimeException(sprintf(
                'The violation date must be the reviewed day, %s.',
                $review->work_date->toDateString()
            ), 422);
        }
    }

    /**
     * Notes on the review that a violation was recorded from it. Called inside
     * the same transaction as the violation itself.
     */
    public function linkViolation(AttendanceReview $review, EmployeeViolation $violation, User $actor): void
    {
        $review->update(['employee_violation_id' => $violation->id, 'updated_by' => $actor->id]);

        AttendanceReviewEvent::create([
            'attendance_review_id' => $review->id,
            'from_status' => $review->status,
            'to_status' => $review->status,
            'note' => sprintf('Recorded as a violation (%s).', str_replace('_', ' ', $violation->violation_type)),
            'actor_user_id' => $actor->id,
        ]);
    }

    /**
     * What the comparison showed, kept with each step so the review still
     * reads correctly after the DTR or the schedule is corrected.
     */
    public function snapshot(array $day): array
    {
        return [
            'schedule' => $day['schedule'] ? ['start' => $day['schedule']['start'], 'end' => $day['schedule']['end']] : null,
            'time_in' => $day['time_in'],
            'time_out' => $day['time_out'],
            'status' => $day['status'],
            'discrepancies' => $day['discrepancies'],
            'late_minutes' => $day['late_minutes'],
            'early_departure_minutes' => $day['early_departure_minutes'],
            'source' => $day['source'],
            'import_code' => $day['import_code'],
            'exception' => $day['exception']['label'] ?? null,
        ];
    }
}
