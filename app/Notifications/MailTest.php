<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent by `php artisan crm:mail-test` to confirm production email works. Same layout as the invite email.
 */
class MailTest extends Notification
{
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
            ->subject("Test email from {$app}")
            ->greeting("Hello {$notifiable->name},")
            ->line("This is a test to confirm that {$app} can send email. Invites and password resets use the same route.")
            ->action('Open '.$app, url('/login'))
            ->line('No action is needed.');
    }
}
