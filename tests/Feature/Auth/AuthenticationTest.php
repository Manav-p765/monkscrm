<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_user_can_log_in_with_correct_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ], ['REMOTE_ADDR' => '203.0.113.7']);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('203.0.113.7', $user->last_login_ip);
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'person@example.com']);

        $this->post('/login', ['email' => 'Person@Example.com', 'password' => UserFactory::PASSWORD]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'Wrong-Password-123',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_fails_for_unknown_email_with_same_message(): void
    {
        $user = User::factory()->create();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'Wrong-Password-123'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->from('/login')->post('/login', ['email' => 'nobody@example.com', 'password' => 'Wrong-Password-123'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'Wrong-Password-123']);
        }

        // Even the correct password is refused while locked out.
        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_rate_limit_is_per_email_and_ip(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'Wrong-Password-123'], ['REMOTE_ADDR' => '198.51.100.1']);
        }

        // A different IP is not affected by the first IP's lockout.
        $this->post('/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD], ['REMOTE_ADDR' => '198.51.100.2']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_user_deactivated_mid_session_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_remember_me_is_ignored(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
            'remember' => 'on',
        ]);

        $this->assertAuthenticatedAs($user);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertStringStartsNotWith('remember_web_', $cookie->getName());
        }
        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_registration_routes_do_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->assertFalse(app('router')->has('register'));

        $response = $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'Some-Password-123',
            'password_confirmation' => 'Some-Password-123',
        ]);

        // Only the GET 404 fallback matches the path, so a POST is 405; either way nothing is created.
        $this->assertContains($response->status(), [404, 405]);

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
    }
}
