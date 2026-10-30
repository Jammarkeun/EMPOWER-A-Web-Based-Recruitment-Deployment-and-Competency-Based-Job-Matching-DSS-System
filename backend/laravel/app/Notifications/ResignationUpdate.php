<?php

namespace App\Notifications;

use App\Models\Resignation;

/**
 * Tells an employee that something changed on their resignation.
 *
 * One notification per save, named for the most significant change in it, so
 * HR accepting a request and setting its date in one go sends one message and
 * not three. Saving with nothing changed sends nothing (SeparationService).
 *
 * The preview names the change and nothing else. HR's own words are read in
 * the portal, never in a notification that might show on a shared screen.
 */
class ResignationUpdate extends EmpowerNotification
{
    public const CLEARANCE_LABELS = [
        'pending' => 'not started yet',
        'in_progress' => 'in progress',
        'cleared' => 'cleared',
    ];

    /**
     * @param  'recorded'|'info_requested'|'accepted'|'reopened'|'message'|'exit_date_changed'|'clearance_changed'|'withdrawn'|'completed'  $kind
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
        return match ($this->kind) {
            'recorded' => 'Your resignation has been recorded',
            'info_requested' => 'HR needs more information about your resignation',
            'accepted' => 'Your resignation has been accepted',
            'reopened' => 'Your resignation request is back under review',
            'message' => 'HR sent a message about your resignation',
            'exit_date_changed' => 'Your last working day has been updated',
            'clearance_changed' => 'Your clearance status has changed',
            'withdrawn' => 'Your resignation request has been closed',
            'completed' => 'Your resignation is complete',
            default => 'An update on your resignation',
        };
    }

    protected function body(): string
    {
        $lastDay = $this->resignation->exit_date?->format('j M Y');

        return match ($this->kind) {
            'recorded' => 'The HR office recorded your resignation. You can follow it under My employment.',
            'info_requested', 'message' => 'Open My employment to read HR\'s message.',
            'accepted' => $lastDay
                ? "HR confirmed {$lastDay} as your last working day. Clearance comes next."
                : 'Open My employment for your next steps.',
            'exit_date_changed' => $lastDay
                ? "HR recorded {$lastDay} as your last working day."
                : 'Open My employment for details.',
            'clearance_changed' => 'Your clearance is '
                .(self::CLEARANCE_LABELS[$this->resignation->clearance_status] ?? 'updated').'.',
            'withdrawn' => 'Your employment continues. Open My employment for details.',
            'completed' => $lastDay
                ? "Your employment ended on {$lastDay}. Open My employment for the next steps."
                : 'Open My employment for the next steps.',
            default => 'Open My employment for details.',
        };
    }

    protected function link(): ?string
    {
        return '/portal/employment';
    }

    protected function severity(): string
    {
        return $this->kind === 'info_requested' ? 'warning' : 'info';
    }
}
