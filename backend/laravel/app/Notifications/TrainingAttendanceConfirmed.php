<?php

namespace App\Notifications;

use App\Models\Applicant;
use App\Models\Training;

/**
 * Tells the office that an applicant has said, from the portal, that they will
 * attend a training session.
 *
 * A heads-up for whoever is preparing the room and the materials - not
 * attendance. Who actually came is still marked on the roster on the day.
 */
class TrainingAttendanceConfirmed extends EmpowerNotification
{
    public function __construct(
        private readonly Applicant $applicant,
        private readonly Training $training,
    ) {
    }

    protected function category(): string
    {
        return 'training';
    }

    protected function title(): string
    {
        return $this->applicant->full_name.' will attend training';
    }

    protected function body(): string
    {
        $date = $this->training->training_date?->format('j F Y');
        $time = $this->training->timeLabel();

        return sprintf(
            '%s confirmed they will attend %s (%s)%s.',
            $this->applicant->applicant_code,
            $this->training->training_title,
            $this->training->training_code,
            $date ? ' on '.$date.($time ? ', '.$time : '') : ''
        );
    }

    protected function link(): ?string
    {
        return '/trainings/'.$this->training->id;
    }

    protected function severity(): string
    {
        return 'info';
    }
}
