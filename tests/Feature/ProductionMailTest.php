<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\InviteUser;
use App\Notifications\MailTest;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\TestCase;

class ProductionMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_test_sends_to_an_active_admin_and_is_audited(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create(['email' => 'asha@hybridmonks.com']);

        $this->artisan('crm:mail-test', ['email' => ' Asha@HybridMonks.com '])->assertSuccessful();

        Notification::assertSentTo($admin, MailTest::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mail.test_sent', 'subject_id' => $admin->id, 'user_id' => $admin->id]);
    }

    public function test_mail_test_refuses_anyone_but_an_active_admin(): void
    {
        Notification::fake();
        $manager = User::factory()->manager()->create();
        $inactive = User::factory()->admin()->inactive()->create();

        foreach ([$manager->email, $inactive->email, 'someone@example.com'] as $email) {
            $this->artisan('crm:mail-test', ['email' => $email])->assertFailed();
        }

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'mail.test_sent']);
    }

    public function test_mail_test_reports_a_delivery_failure(): void
    {
        $admin = User::factory()->admin()->create();
        $failing = new class implements TransportInterface
        {
            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
            {
                throw new \Symfony\Component\Mailer\Exception\TransportException('Domain is not verified');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        };
        Mail::extend('failing', fn () => $failing);
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);

        $this->artisan('crm:mail-test', ['email' => $admin->email])
            ->expectsOutputToContain('Sending failed: TransportException: Domain is not verified')
            ->assertFailed();
    }

    public function test_the_resend_mailer_is_wired_up_without_network(): void
    {
        config(['mail.default' => 'resend', 'services.resend.key' => 'test-key-placeholder']);

        $transport = Mail::mailer()->getSymfonyTransport();

        $this->assertSame('resend', (string) $transport);
    }

    public function test_an_admin_can_invite_a_second_admin_who_must_then_set_up_two_step_sign_in(): void
    {
        Notification::fake();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/users', [
            'name' => 'Second Admin', 'email' => 'second@hybridmonks.com', 'role' => 'admin',
        ])->assertRedirect(route('users.index'));

        $second = User::where('email', 'second@hybridmonks.com')->firstOrFail();
        $this->assertSame(Role::Admin, $second->role);
        Notification::assertSentTo($second, InviteUser::class);
        $this->post('/logout');

        $token = basename(parse_url($second->createInviteLink(), PHP_URL_PATH));
        $this->post('/invite', ['token' => $token, 'email' => $second->email,
            'password' => 'Second-Admin-Passw0rd', 'password_confirmation' => 'Second-Admin-Passw0rd'])
            ->assertRedirect(route('login'));

        $this->post('/login', ['email' => $second->email, 'password' => 'Second-Admin-Passw0rd'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect('/profile');
    }

    public function test_password_reset_emails_use_the_default_mailer(): void
    {
        Notification::fake();
        $user = User::factory()->sales()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }
}
