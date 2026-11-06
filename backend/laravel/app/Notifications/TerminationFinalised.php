<?php

namespace App\Notifications;

use App\Models\Termination;

/**
 * Tells the office - the administrator who approved the request and the HR
 * officer who filed it - that a termination has been finalised.
 *
 * Sent only after the finalisation has committed. The employee's own notice
 * is a separate notification (TerminationNotice) written for them.
 */
class TerminationFinalised extends EmpowerNotification
{
    public function __construct(
        private readonly Termination $termination,
    ) {
    }

    protected function category(): string
    {
        return 'employment';
    }

    protected function title(): string
    {
        return 'Termination finalised';
    }

    protected function body(): string
    {
        return sprintf(
            '%s for %s was finalised by %s after clearance. Employment ended effective %s.',
            $this->termination->reference,
            $this->termination->employee?->full_name ?? 'an employee',
            $this->termination->finalizer?->full_name ?? 'HR',
            $this->termination->termination_date?->format('j M Y') ?? 'the termination date',
        );
    }

    protected function link(): ?string
    {
        return '/separations/terminations/'.$this->termination->id;
    }
}
