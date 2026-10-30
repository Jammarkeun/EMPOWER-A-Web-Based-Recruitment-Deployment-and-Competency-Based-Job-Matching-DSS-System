<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\Training;
use App\Models\TrainingEnrollment;
use App\Notifications\TrainingSessionNotice;
use App\Services\ApplicantLifecycleService;
use App\Services\AuditService;
use App\Services\ReferenceCodeService;
use App\Services\TrainingProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Training sessions, their rosters, attendance and completion (audit R-06).
 *
 * CDE trains every worker before endorsing them to a client, and the lifecycle
 * refuses an endorsement without a recorded completion. Until now only the
 * session itself could be created from the screen, so the gate could only be
 * satisfied by typing a status. This controller carries the whole flow: enrol,
 * mark attendance, record completion - each tied to one applicant in one
 * session, by a named officer, and audited.
 *
 * Attendance and completion are separate facts. Being present does not mean
 * the training was completed, and nothing here infers one from the other.
 *
 * The applicant hears about each roster event that concerns them - enrolled,
 * session moved or cancelled, taken off, completion recorded or withdrawn -
 * through one TrainingSessionNotice naming the session. The status moves made
 * here therefore skip the generic status notice, which named no session and
 * would otherwise arrive alongside it.
 */
class TrainingController extends Controller
{
    /** Where an applicant may be in the lifecycle to be put on a roster. */
    private const ENROLLABLE_STATUSES = ['ready_for_deployment', 'training_scheduled', 'approved'];

    /** The applicant is told about the session, not the status word. */
    private const QUIET = ['notify' => false];

    public function __construct(
        private readonly ReferenceCodeService $codes,
        private readonly ApplicantLifecycleService $lifecycle,
        private readonly AuditService $audit,
        private readonly TrainingProgressService $progress,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Training::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Training::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $trainings = Training::query()
            ->withCount([
                'enrollments',
                'enrollments as attended_count' => fn ($q) => $q->where('attendance_status', 'present'),
                'enrollments as completed_count' => fn ($q) => $q->where('completion_status', 'completed'),
            ])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('training_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('training_date', '<=', $v))
            ->orderByDesc('training_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated($trainings, 'Training sessions retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Training::class);

        $data = $request->validate([
            'training_title' => ['required', 'string', 'max:190'],
            'training_date' => ['required', 'date'],
            'location' => ['required', 'string', 'max:190'],
            'trainer_name' => ['required', 'string', 'max:190'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $training = Training::create(array_merge($data, [
            'training_code' => $this->codes->training(),
            'status' => 'scheduled',
            'created_by' => $request->user()->id,
        ]));

        $this->audit->record('create', 'training', Training::class, $training->id, null, $data);

        return ApiResponse::created($training, 'Training scheduled');
    }

    /**
     * One session with its roster, as the session screen needs it.
     */
    public function show(Training $training): JsonResponse
    {
        $this->authorize('view', $training);

        $training->load([
            'creator',
            'enrollments' => fn ($q) => $q->orderBy('id'),
            'enrollments.applicant',
            'enrollments.attendanceRecorder',
            'enrollments.completionRecorder',
        ]);

        return ApiResponse::success($this->presentSession($training));
    }

    public function update(Request $request, Training $training): JsonResponse
    {
        $this->authorize('update', $training);

        $data = $request->validate([
            'training_title' => ['sometimes', 'required', 'string', 'max:190'],
            'training_date' => ['sometimes', 'required', 'date'],
            'location' => ['sometimes', 'required', 'string', 'max:190'],
            'trainer_name' => ['sometimes', 'required', 'string', 'max:190'],
            'status' => ['sometimes', 'required', Rule::in(Training::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($refusal = $this->refuseSessionChange($training, $data)) {
            return ApiResponse::error($refusal, 409);
        }

        $cancelling = ($data['status'] ?? null) === 'cancelled' && ! $training->isCancelled();

        /*
         * A session somebody completed did take place; calling it cancelled
         * afterwards would erase the training their endorsement rests on.
         */
        if ($cancelling && $training->enrollments()->where('completion_status', 'completed')->exists()) {
            return ApiResponse::error(
                'This session has completions recorded against it, so it cannot be cancelled. '
                .'Withdraw those completions first if they were entered in error.',
                409
            );
        }

        // Dates as the day they are, so an unchanged date is not reported as
        // changed because one side is a timestamp and the other a string.
        $before = collect($training->only(array_keys($data)))
            ->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)
            ->all();

        // Read before the update: a new day or place is news to the roster.
        $rescheduling = ! $cancelling && $this->movesSession($training, $data);

        DB::transaction(function () use ($training, $data, $cancelling, $rescheduling, $request) {
            $training->update($data);

            // Applicants scheduled only for this session are no longer
            // scheduled for anything once it is called off.
            if ($cancelling) {
                foreach ($training->enrollments()->with('applicant')->get() as $enrollment) {
                    $this->releaseFromSchedule(
                        $enrollment->applicant,
                        $request->user(),
                        "Training session {$training->training_code} was cancelled.",
                        $training->id
                    );
                    $this->tell($enrollment->applicant, $training, 'cancelled');
                }
            }

            // Only those still due to attend. Anybody with attendance or a
            // completion recorded is past the point where a new date concerns
            // them - for them it is a correction to the record.
            if ($rescheduling && $training->acceptsEnrolment()) {
                foreach ($training->enrollments()->with('applicant')->get() as $enrollment) {
                    if ($enrollment->isUntouched()) {
                        $this->tell($enrollment->applicant, $training, 'rescheduled');
                    }
                }
            }
        });

        $this->audit->recordUpdate('training', Training::class, $training->id, $before, $data);

        $training = $training->fresh([
            'creator',
            'enrollments' => fn ($q) => $q->orderBy('id'),
            'enrollments.applicant',
            'enrollments.attendanceRecorder',
            'enrollments.completionRecorder',
        ]);

        return ApiResponse::success($this->presentSession($training), 'Training updated');
    }

    /**
     * Why a change to a session would leave its record contradicting itself,
     * or null when it is fine.
     *
     * Attendance and completion are facts about a day that has happened. A
     * session cannot be marked under way or finished before its date, and once
     * anybody's attendance is recorded its date cannot move into the future -
     * the roster would then say people attended training that has not yet
     * taken place.
     */
    private function refuseSessionChange(Training $training, array $data): ?string
    {
        $status = $data['status'] ?? $training->status;
        $date = isset($data['training_date']) ? Carbon::parse($data['training_date']) : $training->training_date;
        $changingStatus = isset($data['status']) && $data['status'] !== $training->status;

        if ($changingStatus && ! $training->canMoveTo($data['status'])) {
            return $training->isCancelled()
                ? 'This session was cancelled and cannot be reopened. Schedule a new session instead.'
                : sprintf(
                    'A %s session cannot be set to %s.',
                    $training->status,
                    $data['status']
                );
        }

        if ($changingStatus && in_array($status, ['ongoing', 'completed'], true) && $date?->isFuture()) {
            return 'This session has not taken place yet. It can be marked '.$status.' on or after '.$date->toDateString().'.';
        }

        $movingDate = isset($data['training_date'])
            && $training->training_date?->toDateString() !== $date->toDateString();

        if ($movingDate && $date->isFuture()) {
            if ($training->hasRecordedOutcomes()) {
                return 'Attendance or completion is already recorded for this session, so its date cannot move into the future. '
                    .'Correct the date to the day it was held.';
            }

            if ($status === 'completed') {
                return 'A completed session cannot be dated in the future. Set it back to scheduled first.';
            }
        }

        return null;
    }

    /**
     * Applicants who can be put on a roster, and where each stands on training.
     *
     * Also the answer to "who is still waiting to be trained?": anybody here
     * without a completed training cannot yet be endorsed to a client.
     */
    public function candidates(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Training::class);
        $this->authorize('viewAny', Applicant::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'training_id' => ['nullable', 'integer', 'exists:trainings,id'],
            'awaiting_only' => ['nullable', 'boolean'],
        ]);

        $applicants = Applicant::query()
            ->whereIn('current_status', self::ENROLLABLE_STATUSES)
            ->search($filters['search'] ?? null)
            ->with(['trainingEnrollments.training'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(200)
            ->get()
            ->map(function (Applicant $applicant) use ($filters) {
                $enrollments = $applicant->trainingEnrollments->filter(fn ($e) => $e->training && ! $e->training->isCancelled());
                $completed = $enrollments->first(fn ($e) => $e->hasCompleted());
                $open = $enrollments->first(fn ($e) => ! $e->hasCompleted() && $e->training->acceptsEnrolment());

                return [
                    'id' => $applicant->id,
                    'full_name' => $applicant->full_name,
                    'applicant_code' => $applicant->applicant_code,
                    'current_status' => $applicant->current_status,
                    'has_completed_training' => (bool) $completed,
                    'completed_training' => $completed ? [
                        'training_id' => $completed->training_id,
                        'title' => $completed->training->training_title,
                        'date' => $completed->training->training_date?->toDateString(),
                    ] : null,
                    'scheduled_training' => $open ? [
                        'training_id' => $open->training_id,
                        'title' => $open->training->training_title,
                        'date' => $open->training->training_date?->toDateString(),
                    ] : null,
                    'in_this_session' => isset($filters['training_id'])
                        && $applicant->trainingEnrollments->contains('training_id', (int) $filters['training_id']),
                ];
            })
            ->when($filters['awaiting_only'] ?? false, fn ($rows) => $rows->reject(fn ($row) => $row['has_completed_training']))
            ->values();

        return ApiResponse::success($applicants);
    }

    /**
     * Enrol applicants into a session.
     *
     * Each applicant is enrolled or skipped with the reason, never silently:
     * an officer adding six people should see that two were already on the
     * roster and one is not at a stage where training applies.
     */
    public function enrol(Request $request, Training $training): JsonResponse
    {
        $this->authorize('update', $training);

        $data = $request->validate([
            'applicant_ids' => ['required', 'array', 'min:1', 'max:200'],
            'applicant_ids.*' => ['integer', 'distinct', 'exists:applicants,id'],
        ]);

        if (! $training->acceptsEnrolment()) {
            return ApiResponse::error(
                $training->isCancelled()
                    ? 'This session was cancelled, so nobody can be enrolled in it.'
                    : 'This session is marked completed. Set it back to scheduled or ongoing to add a late attendee.',
                409
            );
        }

        $enrolled = [];
        $skipped = [];

        DB::transaction(function () use ($data, $training, $request, &$enrolled, &$skipped) {
            $applicants = Applicant::whereIn('id', $data['applicant_ids'])->get()->keyBy('id');

            foreach ($data['applicant_ids'] as $id) {
                $applicant = $applicants[$id];

                if (! in_array($applicant->current_status, self::ENROLLABLE_STATUSES, true)) {
                    $skipped[] = $this->skip($applicant, 'Not at a stage where training applies ('.str_replace('_', ' ', $applicant->current_status).').');

                    continue;
                }

                if (TrainingEnrollment::where('training_id', $training->id)->where('applicant_id', $applicant->id)->exists()) {
                    $skipped[] = $this->skip($applicant, 'Already on this session\'s roster.');

                    continue;
                }

                $enrollment = TrainingEnrollment::create([
                    'training_id' => $training->id,
                    'applicant_id' => $applicant->id,
                    'attendance_status' => 'pending',
                    'completion_status' => 'not_completed',
                ]);

                $this->audit->record('create', 'training', TrainingEnrollment::class, $enrollment->id, null, [
                    'training' => $training->training_code,
                    'applicant_code' => $applicant->applicant_code,
                ]);

                $required = $this->progress->stillRequiredFor($applicant);

                // Enrolling is what schedules somebody for training; the status
                // follows the roster rather than being typed beside it.
                if ($this->lifecycle->canTransition($applicant->current_status, 'training_scheduled')) {
                    $this->lifecycle->transition(
                        $applicant,
                        'training_scheduled',
                        $request->user(),
                        "Enrolled in {$training->training_title} ({$training->training_code}).",
                        [],
                        self::QUIET
                    );
                }

                // Every enrolment is told, not only the one that moved the
                // status: a second session is news too. Somebody already on
                // this roster was skipped above, so nobody hears it twice.
                $this->tell($applicant, $training, 'enrolled', $required);

                $enrolled[] = ['applicant_id' => $applicant->id, 'full_name' => $applicant->full_name];
            }
        });

        return ApiResponse::success([
            'enrolled' => $enrolled,
            'enrolled_count' => count($enrolled),
            'skipped' => $skipped,
        ], match (true) {
            $enrolled === [] => 'Nobody was enrolled.',
            $skipped === [] => count($enrolled).' enrolled.',
            default => count($enrolled).' enrolled, '.count($skipped).' skipped.',
        });
    }

    /**
     * Record attendance and completion for one enrolled applicant.
     *
     * Completion is never inferred from attendance; it is its own decision.
     * It does require attendance - nobody completes a session they were not
     * at - and once recorded it moves the applicant to "training completed".
     */
    public function updateEnrolment(Request $request, Training $training, TrainingEnrollment $enrollment): JsonResponse
    {
        $this->authorize('update', $training);

        abort_unless($enrollment->training_id === $training->id, 404);

        $data = $request->validate([
            'attendance_status' => ['sometimes', Rule::in(['pending', 'present', 'absent'])],
            'completion_status' => ['sometimes', Rule::in(['not_completed', 'completed'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $attendance = $data['attendance_status'] ?? $enrollment->attendance_status;
        $completion = $data['completion_status'] ?? $enrollment->completion_status;
        $recordsOutcome = isset($data['attendance_status']) || isset($data['completion_status']);

        if ($recordsOutcome && $training->isCancelled()) {
            return ApiResponse::error('This session was cancelled, so no attendance or completion can be recorded for it.', 409);
        }

        if ($recordsOutcome && ($attendance !== 'pending' || $completion === 'completed') && ! $training->canRecordOutcomes()) {
            return ApiResponse::error('This session has not taken place yet. Record attendance on or after '.$training->training_date?->toDateString().'.', 409);
        }

        if ($completion === 'completed' && $attendance !== 'present') {
            return ApiResponse::error(
                $enrollment->completion_status === 'completed'
                    ? 'This applicant\'s completion is recorded. Withdraw the completion before changing their attendance.'
                    : 'Mark the applicant present before recording that they completed the training.',
                422
            );
        }

        $withdrawing = $enrollment->completion_status === 'completed' && $completion === 'not_completed';
        $completing = $enrollment->completion_status !== 'completed' && $completion === 'completed';
        $before = $enrollment->only(['attendance_status', 'completion_status', 'remarks']);

        DB::transaction(function () use ($enrollment, $training, $data, $attendance, $completion, $withdrawing, $completing, $request) {
            $actor = $request->user();
            $applicant = $enrollment->applicant()->lockForUpdate()->first();

            $changes = ['remarks' => array_key_exists('remarks', $data) ? $data['remarks'] : $enrollment->remarks];

            if ($attendance !== $enrollment->attendance_status) {
                $changes += [
                    'attendance_status' => $attendance,
                    'attendance_recorded_by' => $attendance === 'pending' ? null : $actor->id,
                    'attendance_recorded_at' => $attendance === 'pending' ? null : now(),
                ];
            }

            if ($completion !== $enrollment->completion_status) {
                $changes += [
                    'completion_status' => $completion,
                    'completion_recorded_by' => $completing ? $actor->id : null,
                    'completion_recorded_at' => $completing ? now() : null,
                ];
            }

            $enrollment->forceFill($changes)->save();

            if ($withdrawing) {
                $this->withdrawCompletion($applicant, $training, $actor);
                $this->tell($applicant, $training, 'completion_withdrawn');
            }

            // Completing training moves the applicant forward, so the record
            // and the recruitment status cannot drift apart.
            if ($completing && $this->lifecycle->canTransition($applicant->current_status, 'training_completed')) {
                $this->lifecycle->transition(
                    $applicant,
                    'training_completed',
                    $actor,
                    "Completed {$training->training_title} ({$training->training_code}).",
                    [],
                    self::QUIET
                );
            }

            if ($completing) {
                $this->tell($applicant, $training, 'completed');
            }
        });

        $after = $enrollment->fresh()->only(['attendance_status', 'completion_status', 'remarks']);
        $this->audit->recordUpdate('training', TrainingEnrollment::class, $enrollment->id, $before, $after);

        return ApiResponse::success(
            $this->presentEnrollment($enrollment->fresh(['applicant', 'attendanceRecorder', 'completionRecorder'])),
            match (true) {
                $completing => 'Completion recorded.',
                $withdrawing => 'Completion withdrawn.',
                default => 'Attendance updated.',
            }
        );
    }

    /**
     * Takes somebody off a roster they were put on by mistake.
     *
     * Only while nothing has been recorded for them: once attendance or a
     * completion exists, the enrolment is part of the record and stays.
     */
    public function removeEnrolment(Request $request, Training $training, TrainingEnrollment $enrollment): JsonResponse
    {
        $this->authorize('update', $training);

        abort_unless($enrollment->training_id === $training->id, 404);

        if (! $enrollment->isUntouched()) {
            return ApiResponse::error(
                'Attendance or completion is already recorded for this applicant, so they stay on the roster. '
                .'Correct the attendance instead.',
                409
            );
        }

        DB::transaction(function () use ($enrollment, $training, $request) {
            $applicant = $enrollment->applicant;

            $this->audit->record('delete', 'training', TrainingEnrollment::class, $enrollment->id, [
                'training' => $training->training_code,
                'applicant_code' => $applicant?->applicant_code,
            ]);

            $enrollment->delete();

            if ($applicant) {
                $this->releaseFromSchedule($applicant, $request->user(), "Removed from {$training->training_title} ({$training->training_code}).", $training->id);
            }

            // Only while they might still turn up for it. Somebody on a session
            // that is over, or already told it was cancelled, has nothing to act on.
            if ($training->acceptsEnrolment()) {
                $this->tell($applicant, $training, 'removed');
            }
        });

        return ApiResponse::success(null, 'Removed from the roster.');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Moves an applicant out of "training scheduled" when the enrolment that
     * put them there is gone and no other session is lined up for them.
     */
    private function releaseFromSchedule(Applicant $applicant, $actor, string $reason, int $exceptTrainingId): void
    {
        if ($applicant->current_status !== 'training_scheduled') {
            return;
        }

        $stillScheduled = $applicant->trainingEnrollments()
            ->where('training_id', '!=', $exceptTrainingId)
            ->where('completion_status', '!=', 'completed')
            ->whereHas('training', fn ($q) => $q->whereIn('status', ['scheduled', 'ongoing']))
            ->exists();

        // Quietly: the caller tells the applicant why. The status notice for
        // this move says "You are ready for deployment", which is not the news.
        if (! $stillScheduled && $this->lifecycle->canTransition('training_scheduled', 'ready_for_deployment')) {
            $this->lifecycle->transition($applicant, 'ready_for_deployment', $actor, $reason, [], self::QUIET);
        }
    }

    /** Whether a change gives the session a different day or place. */
    private function movesSession(Training $training, array $data): bool
    {
        $movesDate = isset($data['training_date'])
            && $training->training_date?->toDateString() !== Carbon::parse($data['training_date'])->toDateString();
        $movesPlace = isset($data['location']) && $data['location'] !== $training->location;

        return $movesDate || $movesPlace;
    }

    /**
     * Tells the applicant about a roster event once it is saved, so a change
     * that is refused or rolled back never announces anything. The session is
     * read when the notice is written, after the change, so it carries the
     * new date and place.
     */
    private function tell(?Applicant $applicant, Training $training, string $event, bool $required = false): void
    {
        if (! $applicant) {
            return;
        }

        DB::afterCommit(fn () => $applicant->notify(new TrainingSessionNotice($training, $event, $required)));
    }

    /**
     * A completion recorded in error is withdrawn, and the applicant's status
     * goes back with it - unless they have already been endorsed on the
     * strength of it, in which case the withdrawal is refused.
     */
    private function withdrawCompletion(Applicant $applicant, Training $training, $actor): void
    {
        if ($this->lifecycle->hasCompletedTraining($applicant)) {
            return; // another completed session still stands
        }

        if ($applicant->current_status === 'training_completed') {
            $this->lifecycle->transitionForWorkflow(
                $applicant,
                'training_scheduled',
                $actor,
                "Completion of {$training->training_title} ({$training->training_code}) withdrawn.",
                ['training_completed'],
                [],
                self::QUIET
            );

            return;
        }

        if (! in_array($applicant->current_status, self::ENROLLABLE_STATUSES, true)) {
            throw new RuntimeException(
                'This applicant has already moved on to '.str_replace('_', ' ', $applicant->current_status)
                .' on the strength of this training, so the completion cannot be withdrawn here.',
                409
            );
        }
    }

    private function skip(Applicant $applicant, string $reason): array
    {
        return ['applicant_id' => $applicant->id, 'full_name' => $applicant->full_name, 'reason' => $reason];
    }

    private function presentSession(Training $training): array
    {
        $enrollments = $training->enrollments;

        return [
            'id' => $training->id,
            'training_code' => $training->training_code,
            'training_title' => $training->training_title,
            'training_date' => $training->training_date?->toDateString(),
            'location' => $training->location,
            'trainer_name' => $training->trainer_name,
            'status' => $training->status,
            'remarks' => $training->remarks,
            'created_by' => $training->creator?->full_name,
            'accepts_enrolment' => $training->acceptsEnrolment(),
            'can_record_outcomes' => $training->canRecordOutcomes(),
            'counts' => [
                'enrolled' => $enrollments->count(),
                'present' => $enrollments->where('attendance_status', 'present')->count(),
                'absent' => $enrollments->where('attendance_status', 'absent')->count(),
                'completed' => $enrollments->where('completion_status', 'completed')->count(),
            ],
            'enrollments' => $enrollments->map(fn ($e) => $this->presentEnrollment($e))->values(),
        ];
    }

    private function presentEnrollment(TrainingEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'training_id' => $enrollment->training_id,
            'applicant' => [
                'id' => $enrollment->applicant?->id,
                'full_name' => $enrollment->applicant?->full_name,
                'applicant_code' => $enrollment->applicant?->applicant_code,
                'current_status' => $enrollment->applicant?->current_status,
            ],
            'attendance_status' => $enrollment->attendance_status,
            'attendance_recorded_by' => $enrollment->attendanceRecorder?->full_name,
            'attendance_recorded_at' => $enrollment->attendance_recorded_at?->toIso8601String(),
            'completion_status' => $enrollment->completion_status,
            'completion_recorded_by' => $enrollment->completionRecorder?->full_name,
            'completion_recorded_at' => $enrollment->completion_recorded_at?->toIso8601String(),
            'remarks' => $enrollment->remarks,
            'can_remove' => $enrollment->isUntouched(),
        ];
    }
}
