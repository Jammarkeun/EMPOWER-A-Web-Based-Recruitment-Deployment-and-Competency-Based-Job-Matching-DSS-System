<?php

namespace App\Notifications;

use App\Models\Resignation;

/**
 * Tells the office what an employee did with their resignation request in the
 * portal: sent one, answered HR's question about it, or withdrew it.
 *
 * Sent to everyone who handles separations. A resignation leaves a client a
 * worker short on a known date, so it is news the office wants the day it is
 * filed rather than whenever somebody next opens the separations list.
 */
class ResignationRequestActivity extends EmpowerNotification
{
    /**
     * @param  'submitted'|'responded'|'withdrawn'  $kind
     */
    public function __construct(
        private readonly Resignation $resignation,
        private readonly string $kind,
    ) {
    }

    protected function category(): string
    {
        return 'employment';
    }

    protected function title(): string
    {
        $name = $this->resignation->employee?->full_name ?? 'An employee';

        return match ($this->kind) {
            'responded' => $name.' replied about their resignation request',
            'withdrawn' => $name.' withdrew their resignation request',
            default => $name.' submitted a resignation request',
        };
    }

    protected function body(): string
    {
        $number = $this->resignation->employee?->employee_number;

        return match ($this->kind) {
            'responded' => sprintf('%s answered your request for more information. The request is back under review.', $number),
            'withdrawn' => sprintf('%s is staying on. The request stays on file, marked withdrawn.', $number),
            default => sprintf(
                '%s asks to make %s their last working day. Review the request on the employee record.',
                $number,
                $this->resignation->requested_exit_date?->format('j M Y') ?? 'a date to be agreed'
            ),
        };
    }

    protected function link(): ?string
    {
        return '/employees/'.$this->resignation->employee_id;
    }
}
