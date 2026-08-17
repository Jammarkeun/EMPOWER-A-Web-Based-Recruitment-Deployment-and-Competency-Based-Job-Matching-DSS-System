<?php

namespace App\Notifications;

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

    public function via(object $notifiable): array
    {
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
