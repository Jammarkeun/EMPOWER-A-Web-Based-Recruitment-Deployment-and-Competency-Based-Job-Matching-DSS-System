<?php

namespace App\Notifications;

use App\Models\ManpowerRequisition;

/**
 * Tells HR a coordinator has sent in a manpower requisition to review.
 */
class RequisitionSubmitted extends EmpowerNotification
{
    public function __construct(private readonly ManpowerRequisition $requisition)
    {
    }

    protected function category(): string
    {
        return 'coordination';
    }

    protected function title(): string
    {
        return sprintf(
            'Manpower requisition from %s',
            $this->requisition->company?->company_name ?? 'a client'
        );
    }

    protected function body(): string
    {
        $reason = config('empower.requisition_form.reasons.'.$this->requisition->request_reason);

        return sprintf(
            '%s: %d × %s%s, needed by %s. Submitted by %s for HR review.',
            $this->requisition->requisition_code,
            $this->requisition->workers_needed,
            $this->requisition->position_title,
            $reason ? " ({$reason})" : '',
            $this->requisition->required_by?->format('M j, Y'),
            $this->requisition->requester?->full_name ?? 'the coordinator',
        );
    }

    protected function link(): ?string
    {
        return '/requisitions/'.$this->requisition->id;
    }
}
