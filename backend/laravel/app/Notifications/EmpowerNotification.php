<?php

namespace App\Notifications;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Base class for every in-app notification in EMPOWER.
 *
 * All notifications share one payload shape so the client can render any of them
 * with a single component: a category for the icon, a title, a body, and an
 * optional link to the record it concerns. Subclasses supply the wording.
 *
 * Only the database channel is used. Email would depend on applicants having
 * working addresses, which is not reliable for the agency's walk-in intake, and
 * an alert nobody receives is worse than none.
 */
abstract class EmpowerNotification extends Notification
{
    use Queueable;

    /**
     * Honours the recipient's notification preferences.
     *
     * Returning an empty array is how Laravel is told to send nothing, so a
     * muted category simply never produces a row — the badge and the panel then
     * agree with the preference automatically, because both read the same rows.
     *
     * The notifiable is not always the user. Lifecycle notifications are
     * addressed to the applicant or employee record, since that is the thing
     * the event happened to, so the preference is resolved through the account
     * linked to it.
     *
     * Muting suppresses the alert and nothing else. The document is still
     * verified, the placement is still recorded, and no part of the recruitment
     * process depends on a notification having been delivered — which is what
     * makes this safe to let people switch off.
     */
    public function via(object $notifiable): array
    {
        $user = match (true) {
            $notifiable instanceof User => $notifiable,
            default => User::query()
                ->when(
                    $notifiable instanceof Applicant,
                    fn ($q) => $q->where('applicant_id', $notifiable->getKey()),
                    fn ($q) => $q->where('employee_id', $notifiable->getKey())
                )
                ->first(),
        };

        // Nobody to ask means nobody has opted out. A record with no portal
        // account still accrues its notifications, which is what makes them
        // visible the day an account is created for it.
        if ($user && ! $user->wantsNotification($this->category())) {
            return [];
        }

        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->title(),
            'body' => $this->body(),
            'link' => $this->link(),
            'severity' => $this->severity(),
        ];
    }

    /** Groups the notification for filtering and icon selection. */
    abstract protected function category(): string;

    abstract protected function title(): string;

    abstract protected function body(): string;

    /** In-app route the notification points at, or null if it is informational. */
    protected function link(): ?string
    {
        return null;
    }

    /**
     * info | success | warning. Drives colour only; the title always carries the
     * meaning in words as well, so nothing depends on colour alone.
     */
    protected function severity(): string
    {
        return 'info';
    }
}
