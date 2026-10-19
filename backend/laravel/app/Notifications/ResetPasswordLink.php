<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The emailed link for resetting a password (PasswordResetController).
 *
 * Mail only, and not an EmpowerNotification: it is not an in-app alert, it
 * cannot be muted, and it must never be stored where another person could
 * read it. The link carries the single-use token; the database holds only its
 * hash.
 */
class ResetPasswordLink extends Notification
{
    public function __construct(private readonly string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);
        $url = rtrim((string) config('empower.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return (new MailMessage)
            ->subject('Reset your EMPOWER password')
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line('Someone asked to reset the password for your EMPOWER account at '.config('empower.organisation.name').'.')
            ->action('Choose a new password', $url)
            ->line("The link works once and expires in {$minutes} minutes.")
            ->line('If you did not ask for this, ignore this email. Your password stays as it is.');
    }

    /** For tests: the token this notification carries. */
    public function token(): string
    {
        return $this->token;
    }
}
