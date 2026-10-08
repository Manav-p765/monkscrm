<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\MailTest as MailTestNotification;
use App\Support\Audit;
use Illuminate\Console\Command;
use Throwable;

class MailTest extends Command
{
    protected $signature = 'crm:mail-test {email : An active admin\'s email address}';

    protected $description = 'Send a test email to an active admin to confirm email delivery (run after each deploy)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        // Admins only, so this can't be used to send mail to anyone else.
        $admin = User::query()->where('email', $email)->where('is_active', true)->first();
        if (! $admin?->isAdmin()) {
            $this->error('No active admin has that email address. Test emails only go to admins.');

            return self::FAILURE;
        }

        $this->line('Mailer: '.config('mail.default').'; from: '.config('mail.from.address'));

        try {
            // Sent now (not deferred) so a delivery error shows here.
            $admin->notifyNow(new MailTestNotification);
        } catch (Throwable $e) {
            // The class and message only: no stack trace, no settings.
            $this->error('Sending failed: '.class_basename($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        Audit::log('mail.test_sent', $admin, ['mailer' => config('mail.default')], userId: $admin->id);
        $this->info("Test email sent to admin #{$admin->id}. Check the inbox (and spam), then Resend > Emails for its delivery status.");

        return self::SUCCESS;
    }
}
