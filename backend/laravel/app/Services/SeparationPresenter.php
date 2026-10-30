<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Resignation;
use App\Models\SeparationEvent;
use App\Models\Termination;
use App\Models\User;
use App\Notifications\ResignationUpdate;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Separation cases as each side is allowed to see them.
 *
 * The employee's view is built field by field from an allow-list, not by
 * removing things from HR's: the termination reason, HR's remarks, supporting
 * documents, who filed or finalised a case, and any case that never became a
 * decision are simply never read into it. A field added to the tables later
 * stays internal until somebody adds it here on purpose.
 */
class SeparationPresenter
{
    // ------------------------------------------------------------ the employee

    /**
     * Whether this person may send a resignation request now, and if not, why.
     *
     * @return array{can_request: bool, reason: ?string}
     */
    public function eligibility(?Employee $employee): array
    {
        $refuse = fn (string $reason) => ['can_request' => false, 'reason' => $reason];

        if (! $employee) {
            return $refuse('Only deployed employees can request to resign.');
        }

        if (! $employee->isActive()) {
            return $refuse('Your employment has already ended, so there is nothing to resign from.');
        }

        if ($employee->resignations()->whereIn('status', Resignation::OPEN_STATUSES)->exists()) {
            return $refuse('You already have a resignation request in progress.');
        }

        if (! $employee->activeDeployment()) {
            return $refuse('Your record shows no current assignment. Please contact the HR office.');
        }

        return ['can_request' => true, 'reason' => null];
    }

    /**
     * Everything the portal's employment page shows about separation.
     */
    public function forPortal(Employee $employee, User $viewer): array
    {
        $resignations = $employee->resignations()->with('events')->latest('id')->get();
        $current = $resignations->first(fn (Resignation $r) => $r->isOpen()) ?? $resignations->first();

        return [
            'eligibility' => $this->eligibility($employee),
            'notice_days' => (int) config('empower.separation.notice_days'),
            'resignation' => $current ? $this->portalResignation($current, $viewer) : null,
            'past_resignations' => $resignations
                ->reject(fn (Resignation $r) => $current && $r->is($current))
                ->map(fn (Resignation $r) => [
                    'status_label' => $this->resignationLabel($r, $viewer),
                    'submitted_on' => $r->filing_date?->toDateString(),
                    'closed_on' => ($r->withdrawn_at ?? $r->updated_at)?->toDateString(),
                ])->values(),
            'notice' => $this->portalNotice($this->finalisedTermination($employee)),
        ];
    }

    /**
     * The one line the portal's front page shows, if there is anything to say.
     */
    public function portalSummary(?Employee $employee, User $viewer): ?array
    {
        if (! $employee) {
            return null;
        }

        if ($termination = $this->finalisedTermination($employee)) {
            return [
                'kind' => 'notice',
                'title' => 'A notice about your employment',
                'text' => 'A formal decision about your employment was recorded on '
                    .$termination->finalized_at?->format('j M Y').'. Please read the notice.',
                'needs_attention' => ! $termination->notice_acknowledged_at,
            ];
        }

        $resignation = $employee->resignations()->latest('id')->first();

        if (! $resignation || $resignation->status === 'withdrawn') {
            return null;
        }

        return [
            'kind' => 'resignation',
            'title' => 'Your resignation: '.$this->resignationLabel($resignation, $viewer),
            'text' => $this->resignationSteps($resignation)[0] ?? null,
            'needs_attention' => $resignation->status === 'info_requested',
        ];
    }

    private function portalResignation(Resignation $resignation, User $viewer): array
    {
        $clearance = $resignation->clearance_status;

        return [
            'status' => $resignation->status,
            'status_label' => $this->resignationLabel($resignation, $viewer),
            'is_open' => $resignation->isOpen(),
            'submitted_on' => $resignation->filing_date?->toDateString(),
            'submitted_through' => $resignation->source,
            'reason' => $resignation->reason,
            'remarks' => $resignation->employee_remarks,
            'requested_last_day' => $resignation->requested_exit_date?->toDateString(),
            'confirmed_last_day' => $resignation->exit_date?->toDateString(),
            'hr_message' => $resignation->employee_message,
            // Clearance becomes the employee's concern once HR has accepted.
            'clearance' => in_array($resignation->status, ['accepted', 'completed'], true) ? [
                'status' => $clearance,
                'label' => ucfirst(ResignationUpdate::CLEARANCE_LABELS[$clearance] ?? 'Not recorded'),
            ] : null,
            'has_letter' => filled($resignation->resignation_letter_path),
            'can_withdraw' => $resignation->canBeWithdrawnByEmployee(),
            'can_respond' => $resignation->status === 'info_requested',
            'next_steps' => $this->resignationSteps($resignation),
            'timeline' => $this->portalTimeline($resignation->events),
        ];
    }

    private function portalNotice(?Termination $termination): ?array
    {
        if (! $termination) {
            return null;
        }

        $clearance = $termination->clearance_status;

        return [
            'issued_on' => $termination->finalized_at?->toDateString(),
            'effective_date' => $termination->termination_date?->toDateString(),
            // Written for the employee by the office. The reason and remarks on
            // the termination are the office's, and are not sent.
            'message' => $termination->employee_message,
            'clearance' => [
                'status' => $clearance,
                'label' => $clearance
                    ? ucfirst(ResignationUpdate::CLEARANCE_LABELS[$clearance] ?? $clearance)
                    : 'Ask the HR office',
            ],
            'next_steps' => array_values(array_filter([
                $clearance === 'cleared'
                    ? null
                    : 'Complete your clearance with the HR office: return any company property and have the clearance signed by each office concerned.',
                'Ask the HR office about your last pay and any obligations settled from it.',
                'If you have questions about this decision, contact the HR office.',
            ])),
            'viewed_at' => $termination->notice_viewed_at?->toIso8601String(),
            'acknowledged_at' => $termination->notice_acknowledged_at?->toIso8601String(),
            'office' => [
                'name' => config('empower.organisation.name'),
                'address' => config('empower.organisation.address'),
                'contact' => config('empower.organisation.contact'),
            ],
            'timeline' => $this->portalTimeline($termination->events),
        ];
    }

    /**
     * What happens next, in the employee's terms.
     *
     * @return list<string>
     */
    private function resignationSteps(Resignation $resignation): array
    {
        $lastDay = $resignation->exit_date?->format('j M Y');

        return match ($resignation->status) {
            'filed' => [
                'HR will review your request and confirm your last working day.',
                'Keep reporting for work as scheduled until HR tells you otherwise.',
                'You can withdraw this request until HR accepts it.',
            ],
            'info_requested' => [
                'HR needs more information before they can continue. Read their message and reply below.',
                'Keep reporting for work as scheduled.',
            ],
            'accepted' => array_values(array_filter([
                $lastDay ? "Your last working day is {$lastDay}. Keep reporting for work until then." : null,
                'Complete your clearance: return any company property and have the clearance signed by each office concerned.',
                'HR completes your resignation once you are cleared.',
                'To change your mind now, contact the HR office.',
            ])),
            'completed' => array_values(array_filter([
                $lastDay ? "Your employment ended on {$lastDay}." : null,
                'Ask the HR office about your last pay and any obligations settled from it.',
            ])),
            'withdrawn' => ['This request is closed. Your employment continues as before.'],
            default => [],
        };
    }

    private function resignationLabel(Resignation $resignation, User $viewer): string
    {
        return match ($resignation->status) {
            'filed' => 'Submitted, awaiting HR review',
            'info_requested' => 'More information needed',
            'accepted' => 'Accepted by HR',
            'completed' => 'Completed',
            'withdrawn' => (int) $resignation->withdrawn_by === (int) $viewer->id ? 'Withdrawn by you' : 'Closed by HR',
            default => ucfirst(str_replace('_', ' ', $resignation->status)),
        };
    }

    /**
     * @param  iterable<SeparationEvent>  $events
     */
    private function portalTimeline(iterable $events): array
    {
        return collect($events)
            ->filter(fn (SeparationEvent $e) => $e->visible_to_employee)
            ->map(fn (SeparationEvent $e) => [
                'label' => $this->portalEventLabel($e),
                'note' => $e->employee_note,
                'by' => $e->actor_role === 'employee' ? 'You' : 'HR office',
                'at' => $e->occurred_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function portalEventLabel(SeparationEvent $event): string
    {
        $mine = $event->actor_role === 'employee';

        return match ($event->event) {
            'filed' => $mine ? 'You submitted your resignation request' : 'HR recorded your resignation',
            'info_requested' => 'HR asked for more information',
            'employee_responded' => 'You replied to HR',
            'accepted' => 'HR accepted your resignation',
            'reopened' => 'Your request went back under review',
            'message' => 'HR sent you a message',
            'exit_date_changed' => 'HR set your last working day to '.$this->day($event->details['to'] ?? null),
            'clearance_changed' => 'Clearance: '.(ResignationUpdate::CLEARANCE_LABELS[$event->details['to'] ?? ''] ?? 'updated'),
            'withdrawn' => $mine ? 'You withdrew this request' : 'HR closed this request',
            'completed' => 'Your resignation was completed',
            'finalised' => 'Notice issued',
            'notice_viewed' => 'You opened this notice',
            'notice_acknowledged' => 'You confirmed receiving this notice',
            default => 'Updated',
        };
    }

    private function day(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('j M Y') : 'a new date';
    }

    // -------------------------------------------------------------------- HR

    /**
     * Every entry in a case's history, with who made it.
     *
     * @param  iterable<SeparationEvent>  $events
     */
    public function staffHistory(iterable $events): array
    {
        return collect($events)->map(fn (SeparationEvent $e) => [
            'event' => $e->event,
            'from_status' => $e->from_status,
            'to_status' => $e->to_status,
            'note' => $e->employee_note,
            'details' => $e->details,
            'visible_to_employee' => $e->visible_to_employee,
            'by' => $e->actor?->full_name,
            'by_employee' => $e->actor_role === 'employee',
            'at' => $e->occurred_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Whether the employee has been told of a finalised termination, kept
     * apart in four steps: the notification was created, it was read, the
     * notice was opened, and the employee acknowledged it.
     */
    public function staffNoticeStatus(Termination $termination): ?array
    {
        if (! $termination->isFinalized()) {
            return null;
        }

        $notification = $termination->notice_notification_id
            ? DatabaseNotification::query()->find($termination->notice_notification_id)
            : null;

        return [
            'issued_at' => $termination->finalized_at?->toIso8601String(),
            'has_portal_account' => (bool) User::portalAccountFor($termination->employee),
            // not_sent: finalised before notices existed. not_delivered: the
            // employee has turned these notifications off; the notice is still
            // on their portal.
            'notification' => match (true) {
                ! $termination->notice_notification_id => 'not_sent',
                ! $notification => 'not_delivered',
                $notification->read_at !== null => 'read',
                default => 'unread',
            },
            'notification_read_at' => $notification?->read_at?->toIso8601String(),
            'viewed_at' => $termination->notice_viewed_at?->toIso8601String(),
            'acknowledged_at' => $termination->notice_acknowledged_at?->toIso8601String(),
        ];
    }

    private function finalisedTermination(Employee $employee): ?Termination
    {
        return $employee->terminations()
            ->where('status', 'finalized')
            ->with('events')
            ->latest('finalized_at')
            ->latest('id')
            ->first();
    }
}
