<?php

namespace App\Services;

use App\Models\CoordinatorAssignment;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\ScheduleSubmission;
use App\Models\ScheduleSubmissionEvent;
use App\Models\User;
use App\Notifications\ScheduleReviewed;
use App\Notifications\ScheduleSubmitted;
use App\Services\Attendance\PlacementTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A coordinator's work schedule for a worker, from draft to HR's decision.
 *
 * The coordinator writes down the hours the client gave the worker and submits
 * them; HR approves or returns them with a reason. Submitting approves nothing,
 * and nobody approves a schedule they wrote. Approval puts the schedule in
 * force through AttendanceService::setSchedule - the same path, checks and lock
 * as HR's own entry - and links it here. Until then the DTR is compared with
 * whatever was approved before, never with a proposal.
 *
 * An approved schedule is not edited from here. A later change is a new
 * submission from a later date, which closes the old schedule the day before
 * it starts, so the days already compared keep the schedule they were compared
 * with. Every step is written to schedule_submission_events and the audit log.
 */
class ScheduleSubmissionService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditService $audit,
        private readonly ReferenceCodeService $codes,
    ) {
    }

    // ------------------------------------------------------------ coordinator

    public function create(CoordinatorAssignment $assignment, User $coordinator, Employee $employee, array $data): ScheduleSubmission
    {
        $this->assertEmployeeInScope($assignment, $employee);
        $attributes = $this->attributes($assignment, $employee, $data);

        $submission = DB::transaction(function () use ($employee, $coordinator, $attributes) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $pending = ScheduleSubmission::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', ['submitted', 'returned'])
                ->first();

            if ($pending) {
                throw new RuntimeException(sprintf(
                    '%s for this employee is %s. Work on that one instead of starting another.',
                    $pending->submission_code,
                    strtolower(ScheduleSubmission::LABELS[$pending->status])
                ), 409);
            }

            $draft = ScheduleSubmission::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'draft')
                ->where('created_by', $coordinator->id)
                ->first();

            if ($draft) {
                throw new RuntimeException(sprintf(
                    'You already have a draft schedule for this employee (%s). Open it to continue.',
                    $draft->submission_code
                ), 409);
            }

            $submission = ScheduleSubmission::create($attributes + [
                'submission_code' => $this->codes->scheduleSubmission(),
                'employee_id' => $employee->id,
                'status' => 'draft',
                'created_by' => $coordinator->id,
            ]);

            $this->event($submission, null, 'draft', $coordinator, null);

            return $submission;
        });

        $this->audit->record('create', 'attendance', ScheduleSubmission::class, $submission->id, null, [
            'submission_code' => $submission->submission_code,
            'employee_number' => $employee->employee_number,
            'schedule' => $submission->toSchedule()->describe(),
            'effective_from' => $submission->effective_from->toDateString(),
        ], clientCompanyId: $submission->client_company_id);

        return $submission->fresh();
    }

    /**
     * A draft, or one HR returned. The edit is kept as an event, so HR can see
     * what changed between the return and the resubmission.
     */
    public function update(ScheduleSubmission $submission, CoordinatorAssignment $assignment, User $coordinator, array $data): ScheduleSubmission
    {
        $this->assertEditable($submission, $assignment, $coordinator);
        $attributes = $this->attributes($assignment, $submission->employee, $data);

        $before = $this->formValues($submission);
        $submission->fill($attributes);
        $after = $this->formValues($submission);
        $changes = $this->diff($before, $after);

        if ($changes === []) {
            return $submission;
        }

        DB::transaction(function () use ($submission, $coordinator, $changes) {
            $submission->save();
            $this->event($submission, $submission->status, $submission->status, $coordinator, 'Edited', $changes);
        });

        $this->audit->record('update', 'attendance', ScheduleSubmission::class, $submission->id,
            array_map(fn ($c) => $c['before'], $changes),
            array_map(fn ($c) => $c['after'], $changes),
            clientCompanyId: $submission->client_company_id);

        return $submission->fresh();
    }

    public function submit(ScheduleSubmission $submission, CoordinatorAssignment $assignment, User $coordinator): ScheduleSubmission
    {
        $this->assertEditable($submission, $assignment, $coordinator);

        if (blank($submission->source_note)) {
            throw ValidationException::withMessages([
                'source_note' => ['Say where these hours come from - for example the client\'s memo or the site supervisor - so HR can check them.'],
            ]);
        }

        $from = $submission->status;

        DB::transaction(function () use ($submission, $coordinator, $from) {
            Employee::query()->whereKey($submission->employee_id)->lockForUpdate()->first();
            $locked = ScheduleSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isEditable()) {
                throw new RuntimeException('This schedule has already been sent to HR.', 409);
            }

            // What would stop HR approving it stops it being sent: the
            // coordinator can still fix those. Writing over days HR has
            // already compared with an approved schedule is HR's to do.
            $blocking = collect($this->checks($locked))
                ->filter(fn ($c) => $c['level'] === 'error' || $c['code'] === 'replaces_past_days');

            if ($blocking->isNotEmpty()) {
                throw new RuntimeException($blocking->pluck('message')->implode(' '), 422);
            }

            $locked->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'replaces_schedule_id' => $this->replacedSchedule($locked)?->id,
                // A resubmission is a fresh question for HR; the earlier
                // answer stays in the events.
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
            ]);

            $this->event($locked, $from, 'submitted', $coordinator, null);
        });

        $submission->refresh();

        $this->audit->record('submit', 'attendance', ScheduleSubmission::class, $submission->id,
            ['status' => $from], ['status' => 'submitted', 'submission_code' => $submission->submission_code],
            clientCompanyId: $submission->client_company_id);

        $submission->load(['employee.applicant', 'company', 'creator']);

        User::permission('attendance.manage')
            ->where('is_active', true)
            ->whereKeyNot($coordinator->id)
            ->get()
            ->each->notify(new ScheduleSubmitted($submission));

        return $submission;
    }

    /**
     * Takes back a schedule nobody has approved. It stays on file, withdrawn.
     */
    public function withdraw(ScheduleSubmission $submission, CoordinatorAssignment $assignment, User $coordinator, ?string $reason): ScheduleSubmission
    {
        $this->assertInScope($submission, $assignment);
        $this->assertNotOthersDraft($submission, $coordinator);

        $from = DB::transaction(function () use ($submission, $coordinator, $reason) {
            $locked = ScheduleSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ScheduleSubmission::OPEN, true)) {
                throw new RuntimeException(sprintf(
                    'This schedule is %s, so it cannot be withdrawn.',
                    strtolower(ScheduleSubmission::LABELS[$locked->status])
                ), 409);
            }

            $from = $locked->status;
            $locked->update(['status' => 'withdrawn']);
            $this->event($locked, $from, 'withdrawn', $coordinator, $reason);

            return $from;
        });

        $this->audit->record('withdraw', 'attendance', ScheduleSubmission::class, $submission->id,
            ['status' => $from], array_filter(['status' => 'withdrawn', 'reason' => $reason]),
            clientCompanyId: $submission->client_company_id);

        return $submission->fresh();
    }

    // -------------------------------------------------------------------- HR

    /**
     * HR's decision: approve, which puts the schedule in force, or return it
     * to the coordinator with what needs correcting.
     *
     * @param  bool  $confirmPast  HR has read that approving changes which
     *                              schedule some past days are compared with.
     */
    public function review(ScheduleSubmission $submission, User $reviewer, string $action, ?string $note, bool $confirmPast = false): ScheduleSubmission
    {
        if (! in_array($action, ['approve', 'return'], true)) {
            throw new RuntimeException('A schedule can only be approved or returned.', 422);
        }

        // The routes already keep coordinators out; this is the business
        // rule itself, whichever way the request arrives.
        if ($reviewer->isCoordinator() || ! $reviewer->can('attendance.manage')) {
            throw new RuntimeException('Only HR can approve or return a work schedule.', 403);
        }

        if ((int) $submission->created_by === (int) $reviewer->id) {
            throw new RuntimeException('You wrote this schedule, so somebody else has to review it.', 403);
        }

        if ($action === 'return' && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages([
                'note' => ['Say what needs correcting, so the coordinator knows what to change.'],
            ]);
        }

        $to = $action === 'approve' ? 'approved' : 'returned';

        $schedule = DB::transaction(function () use ($submission, $reviewer, $action, $note, $confirmPast, $to) {
            $locked = ScheduleSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'submitted') {
                throw new RuntimeException(sprintf(
                    'This schedule is %s, so it cannot be %s.',
                    strtolower(ScheduleSubmission::LABELS[$locked->status]),
                    $action === 'approve' ? 'approved' : 'returned'
                ), 409);
            }

            $schedule = null;

            if ($action === 'approve') {
                Employee::query()->whereKey($locked->employee_id)->lockForUpdate()->first();
                $schedule = $this->approve($locked, $reviewer, $confirmPast);
            }

            $locked->update(array_filter([
                'status' => $to,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => filled($note) ? trim($note) : null,
                'employee_schedule_id' => $schedule?->id,
            ], fn ($v) => $v !== null));

            $this->event($locked, 'submitted', $to, $reviewer, filled($note) ? trim($note) : null);

            return $schedule;
        });

        $this->audit->record($action, 'attendance', ScheduleSubmission::class, $submission->id,
            ['status' => 'submitted'], array_filter([
                'status' => $to,
                'submission_code' => $submission->submission_code,
                'note' => $note,
                'employee_schedule_id' => $schedule?->id,
            ]), clientCompanyId: $submission->client_company_id);

        $submission->refresh()->load(['employee.applicant', 'creator']);

        if ($submission->creator?->is_active) {
            $submission->creator->notify(new ScheduleReviewed($submission));
        }

        return $submission;
    }

    /**
     * Puts the submission in force. Inside the review transaction, with the
     * submission and the employee locked.
     */
    private function approve(ScheduleSubmission $locked, User $reviewer, bool $confirmPast): EmployeeSchedule
    {
        $checks = collect($this->checks($locked));
        $errors = $checks->where('level', 'error');

        if ($errors->isNotEmpty()) {
            throw new RuntimeException($errors->pluck('message')->implode(' ').' Return it to the coordinator to correct.', 409);
        }

        $past = $checks->firstWhere('code', 'replaces_past_days');

        if ($past && ! $confirmPast) {
            throw ValidationException::withMessages([
                'confirm_replaces_past' => [$past['message'].' Confirm to approve it anyway.'],
            ]);
        }

        return $this->attendance->setSchedule($locked->employee, [
            'effective_from' => $locked->effective_from->toDateString(),
            'effective_to' => $locked->effective_to?->toDateString(),
            'work_days' => $locked->work_days,
            'shift_start' => $locked->toSchedule()->time('shift_start'),
            'shift_end' => $locked->toSchedule()->time('shift_end'),
            'break_start' => $locked->toSchedule()->time('break_start'),
            'break_minutes' => $locked->break_minutes,
            'remarks' => $locked->remarks,
            'schedule_submission_code' => $locked->submission_code,
        ], $reviewer);
    }

    // ------------------------------------------------------------- checks

    /**
     * What HR should know before deciding, worked out from the data as it is
     * now - not as it was when the coordinator wrote the schedule.
     *
     * An error stops approval. A warning is for HR to weigh; the one that
     * changes past days must be confirmed. Info is context.
     *
     * @return list<array{level: string, code: string, message: string}>
     */
    public function checks(ScheduleSubmission $submission): array
    {
        $employee = $submission->employee;
        $from = CarbonImmutable::parse($submission->effective_from)->startOfDay();
        $to = $submission->effective_to ? CarbonImmutable::parse($submission->effective_to)->startOfDay() : null;
        $today = CarbonImmutable::today();
        $checks = [];
        $add = function (string $level, string $code, string $message) use (&$checks) {
            $checks[] = compact('level', 'code', 'message');
        };

        if ($to && $to->lessThan($from)) {
            $add('error', 'ends_before_start', 'The end date is before the start date.');
        }

        if ($employee->hire_date && $from->lessThan(CarbonImmutable::parse($employee->hire_date)->startOfDay())) {
            $add('error', 'before_hire', sprintf('It starts before the hire date (%s).', $employee->hire_date->toDateString()));
        }

        $placement = PlacementTimeline::forEmployees([$employee->id])->get($employee->id)->on($from);

        if (! $placement) {
            $add('error', 'no_placement', sprintf('The employee has no placement on %s.', $from->toDateString()));
        } elseif ((int) $placement['deployment_id'] !== (int) $submission->deployment_id) {
            $add('error', 'placement_changed', sprintf(
                'The employee\'s placement on %s is no longer the one this schedule was written for.',
                $from->toDateString()
            ));
        }

        $later = EmployeeSchedule::query()
            ->where('employee_id', $employee->id)
            ->inForce()
            ->whereDate('effective_from', '>=', $from->toDateString())
            ->orderBy('effective_from')
            ->first();

        if ($later) {
            $add('error', 'later_schedule', sprintf(
                'An approved schedule already starts on %s (%s). Schedules only move forward, so this one has to start after that date.',
                $later->effective_from->toDateString(),
                $later->describe()
            ));
        }

        if ($submission->isEditable()) {
            $other = ScheduleSubmission::query()
                ->where('employee_id', $employee->id)
                ->whereKeyNot($submission->id)
                ->whereIn('status', ['submitted', 'returned'])
                ->first();

            if ($other) {
                $add('error', 'other_open', sprintf(
                    '%s for this employee is %s. Only one schedule per employee can be with HR at a time.',
                    $other->submission_code,
                    strtolower(ScheduleSubmission::LABELS[$other->status])
                ));
            }
        }

        $covered = $this->pastDaysCovered($employee, $from, $to);

        if ($covered > 0) {
            $add('warning', 'replaces_past_days', sprintf(
                'It starts on %s, so %d past day(s) already compared with the approved schedule would be compared with this one instead. Reviews already made keep what they showed.',
                $from->toDateString(),
                $covered
            ));
        } elseif ($from->lessThan($today)) {
            $add('info', 'back_dated', sprintf(
                'It starts on %s, before today. Those days had no approved schedule, so they will be compared with this one.',
                $from->toDateString()
            ));
        }

        if (count($submission->work_days ?? []) === 7) {
            $add('warning', 'no_rest_day', 'No rest day: all seven days are work days. Check this against what the client gave.');
        }

        $current = $this->replacedSchedule($submission);

        if ($current && $this->scheduleValues($current) === $this->scheduleValues($submission->toSchedule())) {
            $add('warning', 'no_change', 'The days and hours are the same as the approved schedule it would replace.');
        }

        if (blank($submission->source_note)) {
            $add('warning', 'no_source', 'No source is given for these hours.');
        }

        if ($employee->employment_status !== 'active') {
            $add('warning', 'not_active', sprintf('The employee is no longer active (%s).', str_replace('_', ' ', $employee->employment_status)));
        }

        return $checks;
    }

    /**
     * The approved schedule this one would close: in force before the start
     * date and not ended before it.
     */
    public function replacedSchedule(ScheduleSubmission $submission): ?EmployeeSchedule
    {
        $from = CarbonImmutable::parse($submission->effective_from)->toDateString();

        return EmployeeSchedule::query()
            ->with(['company', 'department', 'creator'])
            ->where('employee_id', $submission->employee_id)
            ->inForce()
            ->whereDate('effective_from', '<', $from)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Days from the start date up to yesterday that an approved schedule
     * already covers - the days whose comparison approving would change.
     */
    private function pastDaysCovered(Employee $employee, CarbonImmutable $from, ?CarbonImmutable $to): int
    {
        $yesterday = CarbonImmutable::today()->subDay();
        $end = $to && $to->lessThan($yesterday) ? $to : $yesterday;

        if ($end->lessThan($from)) {
            return 0;
        }

        $schedules = $employee->schedules()->inForce()->get();

        if ($schedules->isEmpty()) {
            return 0;
        }

        $timeline = PlacementTimeline::forEmployees([$employee->id])->get($employee->id);
        $comparison = $this->attendance->comparison();
        $count = 0;

        for ($day = $from; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            if ($comparison->scheduleFor($schedules, $day, $timeline->on($day))) {
                $count++;
            }
        }

        return $count;
    }

    // ------------------------------------------------------------ helpers

    /**
     * The submission's columns from the coordinator's form, checked the way a
     * schedule is checked, and tied to the placement in force on its first day.
     */
    private function attributes(CoordinatorAssignment $assignment, Employee $employee, array $data): array
    {
        $from = CarbonImmutable::parse($data['effective_from'])->startOfDay();
        $to = filled($data['effective_to'] ?? null) ? CarbonImmutable::parse($data['effective_to'])->startOfDay() : null;

        if ($to && $to->lessThan($from)) {
            throw ValidationException::withMessages(['effective_to' => ['The end date cannot be before the start date.']]);
        }

        if ($employee->hire_date && $from->lessThan(CarbonImmutable::parse($employee->hire_date)->startOfDay())) {
            throw ValidationException::withMessages(['effective_from' => [sprintf(
                'A schedule cannot start before the hire date (%s).',
                $employee->hire_date->toDateString()
            )]]);
        }

        $placement = PlacementTimeline::forEmployees([$employee->id])->get($employee->id)->on($from);

        if (! $placement || (int) $placement['client_company_id'] !== (int) $assignment->client_company_id) {
            throw ValidationException::withMessages(['effective_from' => [sprintf(
                'On %s this employee is not placed at your client. A schedule starts on a day they work there.',
                $from->toDateString()
            )]]);
        }

        try {
            $fields = $this->attendance->scheduleFields($data);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['break_minutes' => [$e->getMessage()]]);
        }

        return $fields + [
            'effective_from' => $from->toDateString(),
            'effective_to' => $to?->toDateString(),
            'remarks' => filled($data['remarks'] ?? null) ? trim($data['remarks']) : null,
            'source_note' => filled($data['source_note'] ?? null) ? trim($data['source_note']) : null,
            'deployment_id' => $placement['deployment_id'],
            'client_company_id' => $placement['client_company_id'],
            'client_department_id' => $placement['client_department_id'],
        ];
    }

    private function assertEmployeeInScope(CoordinatorAssignment $assignment, Employee $employee): void
    {
        if ((int) $employee->current_client_company_id !== (int) $assignment->client_company_id) {
            throw new RuntimeException('This employee is not under your assigned client company.', 403);
        }
    }

    private function assertInScope(ScheduleSubmission $submission, CoordinatorAssignment $assignment): void
    {
        if ((int) $submission->client_company_id !== (int) $assignment->client_company_id) {
            throw new RuntimeException('This schedule is not under your assigned client company.', 403);
        }
    }

    private function assertNotOthersDraft(ScheduleSubmission $submission, User $coordinator): void
    {
        if ($submission->status === 'draft' && (int) $submission->created_by !== (int) $coordinator->id) {
            throw new RuntimeException('This draft belongs to another coordinator.', 403);
        }
    }

    /**
     * Any coordinator of the client may correct a schedule HR returned - the
     * one who wrote it may have been reassigned - but a draft is its writer's.
     */
    private function assertEditable(ScheduleSubmission $submission, CoordinatorAssignment $assignment, User $coordinator): void
    {
        $this->assertInScope($submission, $assignment);
        $this->assertNotOthersDraft($submission, $coordinator);

        if (! $submission->isEditable()) {
            throw new RuntimeException(sprintf(
                'This schedule is %s, so it can no longer be changed. A new schedule from a later date replaces an approved one.',
                strtolower(ScheduleSubmission::LABELS[$submission->status])
            ), 409);
        }
    }

    /** The days and hours, as compared between two schedules. */
    private function scheduleValues(EmployeeSchedule $schedule): array
    {
        return [
            'work_days' => array_values(array_map('intval', $schedule->work_days ?? [])),
            'shift_start' => $schedule->time('shift_start'),
            'shift_end' => $schedule->time('shift_end'),
            'break_start' => $schedule->time('break_start'),
            'break_minutes' => $schedule->break_minutes,
        ];
    }

    /** Everything on the coordinator's form, for showing what an edit changed. */
    private function formValues(ScheduleSubmission $submission): array
    {
        return $this->scheduleValues($submission->toSchedule()) + [
            'effective_from' => CarbonImmutable::parse($submission->effective_from)->toDateString(),
            'effective_to' => $submission->effective_to ? CarbonImmutable::parse($submission->effective_to)->toDateString() : null,
            'remarks' => $submission->remarks,
            'source_note' => $submission->source_note,
        ];
    }

    /** @return array<string, array{before: mixed, after: mixed}> */
    private function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            if (json_encode($before[$field] ?? null) !== json_encode($value)) {
                $changes[$field] = ['before' => $before[$field] ?? null, 'after' => $value];
            }
        }

        return $changes;
    }

    private function event(ScheduleSubmission $submission, ?string $from, string $to, User $actor, ?string $note, ?array $changes = null): void
    {
        ScheduleSubmissionEvent::create([
            'schedule_submission_id' => $submission->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'changes' => $changes,
            'actor_id' => $actor->id,
        ]);
    }
}
