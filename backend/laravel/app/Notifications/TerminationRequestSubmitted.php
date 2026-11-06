<?php

namespace App\Notifications;

use App\Models\Termination;

/**
 * Tells the administrators who review terminations that HR has sent one for
 * review - the first time, or again after correcting it.
 *
 * Sent from SeparationService once the request has been saved, and only when
 * it actually reached "submitted for review" in that save, so opening or
 * reloading the request never sends another. The employee is never one of the
 * recipients: a request is not a decision, and they are told nothing until a
 * termination is finalised.
 */
class TerminationRequestSubmitted extends EmpowerNotification
{
    /**
     * @param  'submitted'|'resubmitted'  $kind
     */
    public function __construct(
        private readonly Termination $termination,
        private readonly string $kind = 'submitted',
    ) {
    }

    protected function category(): string
    {
        return 'employment';
    }

    protected function title(): string
    {
        return $this->kind === 'resubmitted'
            ? 'Corrected termination request pending review'
            : 'Termination request pending review';
    }

    protected function body(): string
    {
        $employee = $this->termination->employee;

        return sprintf(
            '%s for %s (%s), filed by %s, needs your decision. Nothing changes for the employee until it is approved and finalised.',
            $this->termination->reference,
            $employee?->full_name ?? 'an employee',
            $employee?->employee_number ?? 'no employee number',
            $this->termination->creator?->full_name ?? 'HR',
        );
    }

    protected function link(): ?string
    {
        return '/separations/terminations/'.$this->termination->id;
    }

    protected function severity(): string
    {
        return 'warning';
    }
}
