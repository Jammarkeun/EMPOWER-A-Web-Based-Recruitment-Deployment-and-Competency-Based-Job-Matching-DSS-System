<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\TrainingEnrollment;
use App\Support\ApplicantStatuses;
use Illuminate\Support\Collection;

/**
 * Where an applicant stands on training, as the applicant is shown it.
 *
 * The portal's Training step, its training reminder and its training page all
 * read this, so they cannot disagree with one another. Everything is derived
 * from the roster - the enrolments, attendance and completions officers record
 * on the Training screen - never from the status word: being enrolled is not
 * attending, and attending is not completing. "Completed" is the very check
 * the endorsement gate makes (ApplicantLifecycleService::hasCompletedTraining).
 *
 * Only what the session record holds is passed on. A session has a date, a
 * location and a trainer but no start time or meeting link, so none is
 * implied. Remarks and the officers' names stay on the staff side.
 */
class TrainingProgressService
{
    /** The overall state of the Training step. */
    public const LABELS = [
        'not_reached' => 'Not yet',
        'not_required' => 'Not required',
        'pending' => 'Waiting to be scheduled',
        'scheduled' => 'Scheduled',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'missed' => 'Missed',
        'not_recorded' => 'No training on record',
    ];

    /** One session as it stands for this applicant. */
    public const SESSION_LABELS = [
        'scheduled' => 'Scheduled',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'missed' => 'Marked absent',
        'cancelled' => 'Session cancelled',
    ];

    /** Session states the applicant still has to attend or finish. */
    public const OPEN_STATES = ['scheduled', 'in_progress'];

    public function __construct(private readonly ApplicantLifecycleService $lifecycle)
    {
    }

    public function forApplicant(Applicant $applicant): array
    {
        $sessions = $applicant->trainingEnrollments()
            ->with('training')
            ->get()
            ->filter(fn (TrainingEnrollment $e) => $e->training !== null)
            ->sortByDesc(fn (TrainingEnrollment $e) => [$e->training->training_date?->toDateString(), $e->id])
            ->map(fn (TrainingEnrollment $e) => $this->presentSession($e))
            ->values();

        $required = $this->isRequired();
        $completed = $this->lifecycle->hasCompletedTraining($applicant);
        $status = $this->status($applicant->current_status, $sessions, $completed, $required);

        return [
            'status' => $status,
            'label' => self::LABELS[$status],
            'explanation' => $this->explain($status, $required),
            // Whether a recorded completion is needed before the application
            // can move on - the Settings switch behind both gates.
            'required' => $required,
            'sessions' => $sessions->all(),
        ];
    }

    /**
     * Whether training stands between this applicant and their next step: the
     * gate is on and no completion is on record.
     */
    public function stillRequiredFor(Applicant $applicant): bool
    {
        return $this->isRequired() && ! $this->lifecycle->hasCompletedTraining($applicant);
    }

    private function isRequired(): bool
    {
        return (bool) config('empower.require_training_before_deployment', true);
    }

    /**
     * The step as a whole. A recorded completion settles it; otherwise the
     * session nearest to needing the applicant's attention speaks for it, and
     * with no session at all it depends on where the application is.
     */
    private function status(string $current, Collection $sessions, bool $completed, bool $required): string
    {
        if ($completed) {
            return 'completed';
        }

        foreach (['in_progress', 'scheduled', 'missed'] as $state) {
            if ($sessions->contains('state', $state)) {
                return $state;
            }
        }

        if (! $required) {
            return 'not_required';
        }

        $stages = array_keys(ApplicantStatuses::STAGES);
        $stage = ApplicantStatuses::stageOf($current);
        $position = $stage === null ? null : array_search($stage, $stages, true);
        $training = array_search('training', $stages, true);

        return match (true) {
            $position === null => 'not_recorded',
            $position < $training => 'not_reached',
            // Deployed without a completion on record (the gate was switched
            // off at the time): nothing is waiting on training any more.
            $stage === 'deployed' => 'not_recorded',
            default => 'pending',
        };
    }

    private function explain(string $status, bool $required): string
    {
        $mustComplete = $required
            ? ' Completing your training is necessary before your application can move to the next step.'
            : '';

        return match ($status) {
            'not_reached' => 'Training comes after your documents are verified. We will let you know here when you are scheduled.',
            'not_required' => 'Training is not currently required for your application to move forward.',
            'pending' => 'Training is the next step in your application. CDE Manpower Services will schedule your session and let you know here.',
            'scheduled' => 'You are enrolled in a training session. Please attend it as scheduled.'.$mustComplete,
            'in_progress' => 'Your training has started. It is complete once CDE Manpower Services records your completion.'.$mustComplete,
            'completed' => 'CDE Manpower Services has recorded that you completed your training.',
            'missed' => $required
                ? 'You were marked absent from your training session, so your training is not complete yet. Please contact the office to be scheduled for another session.'
                : 'You were marked absent from your training session. Please contact the office if you have questions.',
            default => 'There is no completed training on your record.',
        };
    }

    /**
     * Where one session stands for this applicant.
     *
     * "In progress" is a session that has begun - the applicant was marked
     * present, the session is marked under way or over, or its day has passed -
     * without a completion recorded for this applicant yet. None of those is a
     * completion; only the officer's record is.
     */
    private function sessionState(TrainingEnrollment $enrollment): string
    {
        $training = $enrollment->training;

        return match (true) {
            $training->isCancelled() => 'cancelled',
            $enrollment->hasCompleted() => 'completed',
            $enrollment->attendance_status === 'absent' => 'missed',
            $enrollment->attendance_status === 'present',
            in_array($training->status, ['ongoing', 'completed'], true),
            $training->training_date?->lt(today()) => 'in_progress',
            default => 'scheduled',
        };
    }

    private function presentSession(TrainingEnrollment $enrollment): array
    {
        $training = $enrollment->training;
        $state = $this->sessionState($enrollment);

        return [
            'enrollment_id' => $enrollment->id,
            'training_code' => $training->training_code,
            'title' => $training->training_title,
            'date' => $training->training_date?->toDateString(),
            'location' => $training->location,
            'trainer_name' => $training->trainer_name,
            'state' => $state,
            'state_label' => self::SESSION_LABELS[$state],
            'attendance_status' => $enrollment->attendance_status,
            'completion_status' => $enrollment->completion_status,
            'completed_on' => $enrollment->hasCompleted()
                ? $enrollment->completion_recorded_at?->toDateString()
                : null,
        ];
    }
}
