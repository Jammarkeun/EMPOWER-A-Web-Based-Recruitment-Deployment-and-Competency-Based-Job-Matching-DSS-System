<?php

namespace App\Notifications;

use App\Models\Termination;

/**
 * Tells the HR officer who filed a termination request what the
 * administrator decided: approved for clearance and final processing,
 * returned for correction, or rejected.
 *
 * None of these ends the employment, and the employee is not told of any of
 * them.
 */
class TerminationRequestReviewed extends EmpowerNotification
{
    /**
     * @param  'approved'|'returned'|'rejected'  $decision
     */
    public function __construct(
        private readonly Termination $termination,
        private readonly string $decision,
    ) {
    }

    protected function category(): string
    {
        return 'employment';
    }

    protected function title(): string
    {
        return match ($this->decision) {
            'approved' => 'Termination request approved - clearance needed',
            'returned' => 'Termination request returned for correction',
            default => 'Termination request rejected',
        };
    }

    protected function body(): string
    {
        $who = sprintf(
            '%s for %s',
            $this->termination->reference,
            $this->termination->employee?->full_name ?? 'an employee'
        );

        return match ($this->decision) {
            'approved' => $who.' was approved. Complete the pre-termination clearance, then finalise it. The employee stays active until then.',
            'returned' => $who.' was returned: '.($this->termination->review_note ?? 'see the administrator\'s note').'.',
            default => $who.' was rejected. The employee stays active. Reason: '.($this->termination->review_note ?? 'not given').'.',
        };
    }

    protected function link(): ?string
    {
        return '/separations/terminations/'.$this->termination->id;
    }

    protected function severity(): string
    {
        return $this->decision === 'approved' ? 'info' : 'warning';
    }
}
