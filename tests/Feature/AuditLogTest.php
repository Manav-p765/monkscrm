<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_helper_records_context_and_strips_sensitive_meta_keys(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $entry = Audit::log('user.updated', $admin, [
            'fields' => ['name'],
            'password' => 'x',
            'new_password_confirmation' => 'x',
            'reset_token' => 'x',
            'two_factor_secret' => 'x',
            'recovery_code' => 'x',
            'nested' => ['Api_Token' => 'x', 'kept' => 'yes'],
        ]);

        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame(['fields' => ['name'], 'nested' => ['kept' => 'yes']], $entry->fresh()->meta);
        $this->assertSame('User', class_basename($entry->subject_type));
    }

    public function test_user_agent_is_capped_at_255_characters(): void
    {
        $this->withHeader('User-Agent', str_repeat('A', 600))->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);

        $this->assertSame(255, mb_strlen(AuditLog::where('action', 'auth.login_failed')->value('user_agent')));
    }

    public function test_sign_in_events_are_logged_without_secrets(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com', 'password' => 'Correct-Horse-9-Battery']);

        $this->post('/login', ['email' => 'sam@example.com', 'password' => 'wrong-password']);
        $failed = AuditLog::where('action', 'auth.login_failed')->first();
        $this->assertSame(['email' => 'sam@example.com'], $failed->meta);

        $this->post('/login', ['email' => 'sam@example.com', 'password' => 'Correct-Horse-9-Battery']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);

        $this->post('/logout');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);

        $everything = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString('Correct-Horse', $everything);
        $this->assertStringNotContainsString('wrong-password', $everything);
    }

    public function test_lockout_is_logged(): void
    {
        foreach (range(1, 6) as $i) {
            $this->post('/login', ['email' => 'locked@example.com', 'password' => 'wrong']);
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.lockout']);
        $this->assertSame(['email' => 'locked@example.com'], AuditLog::where('action', 'auth.lockout')->first()->meta);
    }

    public function test_password_reset_request_and_completion_are_logged(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->post('/forgot-password', ['email' => 'reset@example.com']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset_requested', 'user_id' => $user->id]);

        $token = app('auth.password.broker')->createToken($user);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        $this->post('/reset-password', [
            'token' => $token, 'email' => 'reset@example.com',
            'password' => 'A-Brand-New-Passw0rd', 'password_confirmation' => 'A-Brand-New-Passw0rd',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset_completed', 'user_id' => $user->id]);
        $this->assertStringNotContainsString($token, json_encode(AuditLog::all()->toArray()));
    }

    public function test_password_change_and_two_step_events_are_logged(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        $user = User::factory()->create(['password' => 'Old-Password-123']);
        $this->actingAs($user);

        $this->put('/user/password', [
            'current_password' => 'Old-Password-123',
            'password' => 'New-Password-456x', 'password_confirmation' => 'New-Password-456x',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed', 'user_id' => $user->id]);

        event(new \Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed($user));
        event(new \Laravel\Fortify\Events\TwoFactorAuthenticationDisabled($user));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_enabled', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_disabled', 'user_id' => $user->id]);
    }

    public function test_forced_logout_of_deactivated_user_is_logged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->update(['is_active' => false]);

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.forced_logout_inactive', 'user_id' => $user->id]);
    }

    public function test_only_admins_can_see_the_audit_log(): void
    {
        $entry = Audit::log('auth.login', meta: []);

        foreach ([User::factory()->manager()->create(), User::factory()->sales()->create()] as $user) {
            $this->actingAs($user)->get('/audit-log')->assertForbidden();
            $this->actingAs($user)->get("/audit-log/{$entry->id}")->assertForbidden();
        }

        $this->actingAs(User::factory()->admin()->create())->get('/audit-log')->assertOk()->assertSee('Signed in');
    }

    public function test_page_filters_paginates_and_shows_details(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
        $other = User::factory()->create(['name' => 'Olly Other']);

        Carbon::setTestNow('2026-10-01 06:00:00'); // 11:30 IST on 1 Oct
        Audit::log('auth.login', $other, userId: $other->id);
        Carbon::setTestNow('2026-10-05 06:00:00');
        foreach (range(1, 30) as $i) {
            Audit::log('auth.login_failed', meta: ['email' => "x{$i}@example.com"]);
        }
        Carbon::setTestNow();

        $this->actingAs($admin);

        $page = $this->get('/audit-log')->assertOk();
        $this->assertSame(25, $page->viewData('entries')->count());
        $this->assertSame(31, $page->viewData('entries')->total());

        $byUser = $this->get('/audit-log?user='.$other->id)->assertOk();
        $this->assertSame(1, $byUser->viewData('entries')->total());

        $byAction = $this->get('/audit-log?action=auth.login_failed')->assertOk();
        $this->assertSame(30, $byAction->viewData('entries')->total());

        // Date range is in IST days.
        $byDate = $this->get('/audit-log?from=2026-10-01&to=2026-10-01')->assertOk();
        $this->assertSame(1, $byDate->viewData('entries')->total());

        $this->get('/audit-log?action=not-a-real-action')->assertSessionHasErrors('action');

        $entry = AuditLog::where('action', 'auth.login')->first();
        $this->get("/audit-log/{$entry->id}")->assertOk()->assertSee('Olly Other')->assertSee('auth.login');
    }

    public function test_there_is_no_way_to_change_or_delete_entries(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'audit-log'));

        foreach ($routes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri());
        }
    }
}
