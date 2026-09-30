<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Confirms a password change in the user's in-app notification feed. */
class PasswordChanged extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category' => 'general',
            'title' => 'Password changed',
            'body' => 'Your EMPOWER password was changed successfully. If you did not do this, contact an administrator immediately.',
            'link' => '/settings',
            'severity' => 'warning',
        ];
    }
}
