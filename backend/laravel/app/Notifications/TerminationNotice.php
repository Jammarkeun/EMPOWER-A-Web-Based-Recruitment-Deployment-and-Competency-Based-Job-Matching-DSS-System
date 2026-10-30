<?php

namespace App\Notifications;

use App\Models\Termination;

/**
 * Tells an employee that a termination decision has been recorded, or that the
 * clearance after it has moved.
 *
 * Sent only from SeparationService::finaliseTermination - never for a draft, a
 * case under review, a violation, or a coordinator's report, none of which is
 * a decision. The preview says that a notice exists and where to read it, and
 * no more: the decision itself is read in the portal, where the employee is
 * signed in, and the internal reason and remarks are never part of it.
 */
class TerminationNotice extends EmpowerNotification
{
    /**
     * @param  'issued'|'clearance_changed'  $kind
     */
    public function __construct(
        private readonly Termination $termination,
        private readonly string $kind = 'issued',
    ) {
    }

    protected function category(): string
    {
        return 'employment';
    }

    protected function title(): string
    {
        return $this->kind === 'clearance_changed'
            ? 'Your clearance status has changed'
            : 'A notice about your employment';
    }

    protected function body(): string
    {
        if ($this->kind === 'clearance_changed') {
            return 'Your clearance is '
                .(ResignationUpdate::CLEARANCE_LABELS[$this->termination->clearance_status] ?? 'updated').'.';
        }

        return 'A formal decision about your employment has been recorded. Please read the notice under My employment.';
    }

    protected function link(): ?string
    {
        return '/portal/employment';
    }
}
