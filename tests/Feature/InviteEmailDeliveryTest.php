<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Invites, resent invites and admin-sent reset links are emailed inside the request, so the credentials
 * card can say truthfully whether the email went out. The link is created and shown either way.
 */
class InviteEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const SENT = "We've also emailed it to them.";

    private const FAILED = "We couldn't send the email.";

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    /** Every send fails like Resend does for an unverified domain. */
    private function failingMailer(): void
    {
        Mail::extend('failing', fn () => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('The hybridmonks.com domain is not verified. Sent to new.person@example.com');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
    }

    private function invite(): User
    {
        $this->actingAs($this->admin)->post('/users', [
            'name' => 'New Person', 'email' => 'new.person@example.com', 'role' => 'sales',
        ])->assertRedirect(route('users.index'));

        return User::where('email', 'new.person@example.com')->firstOrFail();
    }

    public function test_when_the_email_is_sent_the_card_says_so(): void
    {
        $this->invite();

        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
        $this->get('/users')->assertOk()
            ->assertSee('Invite sent to New Person')
            ->assertSee(self::SENT, false)
            ->assertDontSee(self::FAILED, false);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.invite_email_failed']);
    }

    public function test_when_the_invite_email_fails_the_invite_still_exists_and_the_card_warns(): void
    {
        $this->failingMailer();
        Log::spy();

        $user = $this->invite();

        $this->assertNotNull($user->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.invited', 'subject_id' => $user->id]);
        $page = $this->get('/users')->assertOk()
            ->assertSee('Invite created for New Person')
            ->assertSee(self::FAILED, false)
            ->assertSee('Copy the link above and share it privately.')
            ->assertDontSee(self::SENT, false);
        $page->assertSee('/invite/', false); // the link is still on the card to copy

        $this->assertFailureRecorded($user, 'invite');
    }

    public function test_resend_invite_and_reset_link_warn_when_the_email_fails(): void
    {
        $user = User::factory()->sales()->create(['name' => 'Old Person', 'email' => 'old.person@example.com']);
        $this->failingMailer();
        Log::spy();

        $this->actingAs($this->admin)->post("/users/{$user->id}/resend-invite")->assertRedirect();
        $this->get('/users')->assertSee('New invite created for Old Person')->assertSee(self::FAILED, false)->assertDontSee(self::SENT, false);

        $this->post("/users/{$user->id}/password-reset")->assertRedirect();
        $this->get('/users')->assertSee('Password reset link created for Old Person')->assertSee(self::FAILED, false)->assertDontSee(self::SENT, false);

        $this->assertSame(['invite', 'reset'], AuditLog::where('action', 'user.invite_email_failed')->orderBy('id')->get()->pluck('meta.kind')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.invite_resent', 'subject_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset_link_sent', 'subject_id' => $user->id]);
    }

    private function assertFailureRecorded(User $user, string $kind): void
    {
        $audit = AuditLog::where('action', 'user.invite_email_failed')->where('subject_id', $user->id)->firstOrFail();
        $this->assertSame($kind, $audit->meta['kind']);
        $this->assertSame('TransportException', $audit->meta['error']);

        // The log line says why, without the link, token or email address.
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($user) {
            $flat = $message.' '.json_encode($context);

            return $context['user_id'] === $user->id
                && str_contains($flat, 'domain is not verified')
                && ! str_contains($flat, '/invite/')
                && ! str_contains($flat, 'token')
                && ! str_contains($flat, '@example.com');
        });
    }
}
