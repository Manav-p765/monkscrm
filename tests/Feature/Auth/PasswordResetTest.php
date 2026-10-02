<?php

namespace Tests\Feature\Auth;

use App\Http\Responses\PasswordResetLinkRequestedResponse;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_page_renders(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_forgot_password_response_is_identical_for_existing_and_unknown_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $existing = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $existingStatus = session('status');
        $existingErrors = session('errors');

        $this->flushSession();

        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.com']);
        $unknownStatus = session('status');
        $unknownErrors = session('errors');

        $this->assertSame($existing->status(), $unknown->status());
        $this->assertSame($existing->headers->get('Location'), $unknown->headers->get('Location'));
        $this->assertSame(__(PasswordResetLinkRequestedResponse::MESSAGE), $existingStatus);
        $this->assertSame($existingStatus, $unknownStatus);
        $this->assertNull($existingErrors);
        $this->assertNull($unknownErrors);

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_repeat_request_within_throttle_window_looks_the_same(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);
        $this->flushSession();
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', __(PasswordResetLinkRequestedResponse::MESSAGE))
            ->assertSessionHasNoErrors();
    }

    public function test_reset_link_expires_after_30_minutes(): void
    {
        $this->assertSame(30, config('auth.passwords.users.expire'));
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();
        // Breach check (HIBP range API) answers "not found" without a network call.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email))->assertOk();

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Brand-New-Passw0rd-xyz',
                'password_confirmation' => 'Brand-New-Passw0rd-xyz',
            ])->assertSessionHasNoErrors()->assertRedirect('/login');

            return true;
        });
    }

    public function test_reset_rejects_weak_password(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'short',
                'password_confirmation' => 'short',
            ])->assertSessionHasErrors('password');

            return true;
        });
    }
}
