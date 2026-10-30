<?php

namespace App\Notifications;

use App\Models\ManpowerRequisition;

/**
 * Tells the coordinator what HR did with their requisition.
 */
class RequisitionReviewed extends EmpowerNotification
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
        $code = $this->requisition->requisition_code;

        return match ($this->requisition->status) {
            'under_review' => "HR is reviewing requisition {$code}",
            'returned' => "Requisition {$code} was returned for correction",
            'approved' => "Requisition {$code} was approved",
            'rejected' => "Requisition {$code} was rejected",
            'fulfilled' => "Requisition {$code} is fulfilled",
            default => "Requisition {$code} was updated",
        };
    }

    protected function body(): string
    {
        $outcome = match ($this->requisition->status) {
            'under_review' => 'HR has started reviewing it.',
            'returned' => 'Please correct it and submit it again.',
            'approved' => 'CDE will recruit against it.',
            'rejected' => 'CDE will not act on it.',
            'fulfilled' => 'The people requested have been provided.',
            default => 'Its status has changed.',
        };

        return trim(sprintf('%d × %s: %s', $this->requisition->workers_needed, $this->requisition->position_title, $outcome)
            .($this->requisition->review_note ? " HR's note: ".$this->requisition->review_note : ''));
    }

    protected function link(): ?string
    {
        return '/coordinator/requisitions/'.$this->requisition->id;
    }

    protected function severity(): string
    {
        return match ($this->requisition->status) {
            'returned', 'rejected' => 'warning',
            'approved', 'fulfilled' => 'success',
            default => 'info',
        };
    }
}
