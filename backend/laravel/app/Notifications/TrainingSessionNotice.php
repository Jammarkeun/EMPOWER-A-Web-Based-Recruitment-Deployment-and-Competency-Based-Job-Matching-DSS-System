<?php

namespace App\Notifications;

use App\Models\Training;

/**
 * Tells an applicant what has happened to a training session they are on.
 *
 * Replaces the bare status notice "You have been scheduled for training",
 * which named no session, no date and no place - and was not sent at all
 * when somebody already scheduled was put on a second session, nor when a
 * session moved or was called off. The training workflow sends this one
 * instead of the status notice, so each event produces exactly one alert.
 *
 * Every detail is read from the session record. A session has no start time
 * or meeting link, so none is mentioned.
 */
class TrainingSessionNotice extends EmpowerNotification
{
    public const EVENTS = ['enrolled', 'rescheduled', 'cancelled', 'removed', 'completed', 'completion_withdrawn'];

    /**
     * @param  bool  $required  whether a recorded completion still stands
     *                          between the applicant and their next step
     */
    public function __construct(
        private readonly Training $training,
        private readonly string $event,
        private readonly bool $required = false,
    ) {
    }

    protected function category(): string
    {
        return 'training';
    }

    protected function title(): string
    {
        return match ($this->event) {
            'enrolled' => 'You are scheduled for training',
            'rescheduled' => 'Your training schedule has changed',
            'cancelled' => 'Your training session was cancelled',
            'removed' => 'You are no longer on a training session',
            'completed' => 'Training completed',
            'completion_withdrawn' => 'Your training completion was withdrawn',
            default => 'Your training has been updated',
        };
    }

    protected function body(): string
    {
        $session = $this->training->training_title;
        $date = $this->training->training_date?->format('j F Y');
        // "on 3 November 2026 at CDE office" - whichever parts the record has.
        $when = implode(' ', array_filter([
            $date ? 'on '.$date : null,
            $this->training->location ? 'at '.$this->training->location : null,
        ]));

        return match ($this->event) {
            'enrolled' => 'You have been registered for a training session by CDE Manpower Services: '
                .trim($session.' '.$when).'. '
                .'Please review your training schedule, attend the session as scheduled, and complete the training.'
                .($this->required
                    ? ' Completing your required training is necessary before you can proceed to the next step in your application.'
                    : ''),
            'rescheduled' => $session.($when !== '' ? ' is now '.$when : ' has new details').'. Please attend on the updated schedule.',
            'cancelled' => $session.($date ? ' on '.$date : '').' has been cancelled, so you do not need to attend it. '
                .'Your training page shows your current schedule.',
            'removed' => 'You have been taken off the roster for '.$session.($date ? ' on '.$date : '').', so you do not need to attend it. '
                .'Your training page shows your current schedule.',
            'completed' => 'CDE Manpower Services has recorded that you completed '.$session.'.',
            'completion_withdrawn' => 'The office has withdrawn the completion recorded for '.$session.'. '
                .'Your training page shows where your training stands.',
            default => 'Your training page shows your current schedule.',
        };
    }

    protected function link(): ?string
    {
        return '/portal/training';
    }

    protected function severity(): string
    {
        return match ($this->event) {
            'completed' => 'success',
            'rescheduled', 'cancelled', 'removed', 'completion_withdrawn' => 'warning',
            default => 'info',
        };
    }
}
