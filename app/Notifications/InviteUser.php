<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Short, plain invite email with a single-use set-password link (expires in 48 hours).
 */
class InviteUser extends Notification
{
    public function __construct(#[\SensitiveParameter] private string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');

        return (new MailMessage)
            ->subject("You're invited to {$app}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You now have an account on {$app}. Set your password to sign in.")
            ->action('Set Your Password', $this->url)
            ->line('This link works once and expires in 48 hours. If it has expired, ask your administrator to send a new one.')
            ->line("If you weren't expecting this email, you can ignore it.");
    }
}
