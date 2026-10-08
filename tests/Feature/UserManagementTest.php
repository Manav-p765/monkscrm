<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Team;
use App\Models\User;
use App\Notifications\InviteUser;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function invite(User $admin, array $overrides = []): User
    {
        $this->actingAs($admin)->post('/users', [
            'name' => 'Nina New', 'email' => 'Nina.New@Example.com', 'role' => 'sales',
            'team_id' => null, 'job_title' => 'Account Executive', 'phone' => '+91 98765 43210', ...$overrides,
        ])->assertRedirect(route('users.index'));

        return User::where('email', mb_strtolower($overrides['email'] ?? 'nina.new@example.com'))->firstOrFail();
    }

    // ---- Role access --------------------------------------------------------------------------

    public static function writeRoutes(): array
    {
        return [
            'invite' => ['POST', '/users'],
            'update' => ['PUT', '/users/{id}'],
            'resend' => ['POST', '/users/{id}/resend-invite'],
            'reset' => ['POST', '/users/{id}/password-reset'],
            '2fa' => ['DELETE', '/users/{id}/two-factor'],
            'sessions' => ['DELETE', '/users/{id}/sessions'],
            'deactivate' => ['POST', '/users/{id}/deactivate'],
            'reactivate' => ['POST', '/users/{id}/reactivate'],
            'edit page' => ['GET', '/users/{id}/edit'],
            'team create' => ['POST', '/teams'],
            'team rename' => ['PUT', '/teams/{team}'],
        ];
    }

    #[DataProvider('writeRoutes')]
    public function test_managers_and_sales_cannot_change_users_or_teams(string $method, string $uri): void
    {
        $target = User::factory()->create();
        $team = Team::create(['name' => 'North']);
        $uri = str_replace(['{id}', '{team}'], [$target->id, $team->id], $uri);

        foreach ([User::factory()->manager()->create(), User::factory()->sales()->create()] as $actor) {
            $this->actingAs($actor)->call($method, $uri, ['name' => 'X', 'email' => 'x@example.com', 'role' => 'admin'])->assertForbidden();
        }
        $this->assertSame(Role::Sales, $target->fresh()->role);
    }

    public function test_manager_sees_read_only_list_and_sales_get_403(): void
    {
        $colleague = User::factory()->create(['name' => 'Cara Colleague', 'email' => 'cara@example.com']);

        $this->actingAs(User::factory()->manager()->create())->get('/users')
            ->assertOk()->assertSee('Team Directory')->assertSee('Cara Colleague')
            ->assertDontSee('cara@example.com')->assertDontSee('Invite Team Member');

        $this->actingAs(User::factory()->sales()->create())->get('/users')->assertForbidden();
    }

    // ---- Invites ------------------------------------------------------------------------------

    public function test_invite_creates_user_without_a_usable_password_and_emails_a_link(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/users')->assertOk()->assertDontSee('name="password"', false);

        $user = $this->invite($admin);

        $this->assertSame('nina.new@example.com', $user->email);
        $this->assertSame('Account Executive', $user->job_title);
        foreach (['password', 'password123', 'Welcome123!', ''] as $guess) {
            $this->assertFalse(Hash::check($guess, $user->password));
        }
        Notification::assertSentTo($user, InviteUser::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.invited', 'subject_id' => $user->id]);

        // The credentials card shows the link once, and only on the next page.
        $this->get('/users')->assertOk()->assertSee('Invite Link Ready to Share');
        $this->get('/users')->assertOk()->assertDontSee('Invite Link Ready to Share');
    }

    public function test_invite_link_is_single_use_expires_after_48_hours_and_never_reaches_the_audit_log(): void
    {
        Notification::fake();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        $admin = User::factory()->admin()->create();
        $user = $this->invite($admin);
        $this->post('/logout');

        $url = $user->createInviteLink();
        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->get($url)->assertOk()->assertSee('Set Your Password');

        $set = fn () => $this->post('/invite', [
            'token' => $token, 'email' => $user->email,
            'password' => 'My-Own-Passw0rd-1', 'password_confirmation' => 'My-Own-Passw0rd-1',
        ]);

        $set()->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('My-Own-Passw0rd-1', $user->fresh()->password));

        // Second use fails.
        $set()->assertSessionHasErrors('email');

        // A fresh link expires after 48 hours.
        $url = $user->createInviteLink();
        $token2 = basename(parse_url($url, PHP_URL_PATH));
        Carbon::setTestNow(now()->addHours(49));
        $this->post('/invite', ['token' => $token2, 'email' => $user->email,
            'password' => 'Another-Passw0rd-2', 'password_confirmation' => 'Another-Passw0rd-2'])->assertSessionHasErrors('email');
        Carbon::setTestNow();

        $log = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString($token, $log);
        $this->assertStringNotContainsString($token2, $log);
        $this->assertStringNotContainsString('/invite/', $log);
    }

    public function test_a_password_reset_token_cannot_be_used_as_an_invite(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        $user = User::factory()->create();
        $resetToken = app('auth.password.broker')->createToken($user);

        $this->post('/invite', ['token' => $resetToken, 'email' => $user->email,
            'password' => 'My-Own-Passw0rd-1', 'password_confirmation' => 'My-Own-Passw0rd-1'])->assertSessionHasErrors('email');
    }

    public function test_resend_invite_and_password_reset_link_show_the_card_and_are_audited(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $user = $this->invite($admin);

        $this->post("/users/{$user->id}/resend-invite")->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.invite_resent', 'subject_id' => $user->id]);

        $this->post("/users/{$user->id}/password-reset")->assertRedirect();
        $this->get('/users')->assertSee('Password Reset Link Ready');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset_link_sent', 'subject_id' => $user->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.password_reset_requested']);
    }

    // ---- Edit, role changes, safety rules ------------------------------------------------------

    public function test_edit_logs_changed_field_names_and_role_change(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->sales()->create(['name' => 'Old Name']);
        $team = Team::create(['name' => 'Inbound']);

        $this->actingAs($admin)->put("/users/{$user->id}", [
            'name' => 'New Name', 'email' => $user->email, 'role' => 'manager', 'team_id' => $team->id,
        ])->assertRedirect(route('users.index'));

        $this->assertSame(['from' => 'sales', 'to' => 'manager'], AuditLog::where('action', 'user.role_changed')->first()->meta);
        $this->assertSame(['fields' => ['name', 'team_id']], AuditLog::where('action', 'user.updated')->first()->meta);
    }

    public function test_admin_cannot_demote_deactivate_or_reset_two_step_for_themselves(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()]);
        User::factory()->admin()->create(); // another admin exists, so only the self-rule applies

        $this->actingAs($admin)->from("/users/{$admin->id}/edit")
            ->put("/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'manager'])
            ->assertSessionHasErrorsIn('editUser', 'role');
        $this->assertSame(Role::Admin, $admin->fresh()->role);

        $this->post("/users/{$admin->id}/deactivate")->assertForbidden();
        $this->delete("/users/{$admin->id}/two-factor")->assertForbidden();
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_there_is_always_at_least_one_active_admin(): void
    {
        $me = User::factory()->admin()->create();
        $onlyOther = User::factory()->admin()->create();

        // Deactivating the other admin is fine while I remain.
        $this->actingAs($me)->post("/users/{$onlyOther->id}/deactivate")->assertRedirect();
        $this->assertFalse($onlyOther->fresh()->is_active);

        // Now I am the last active admin: nobody can demote me (and I can't demote myself).
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($me)->denies('changeRole', [$me, Role::Sales]));
        $policy = new \App\Policies\UserPolicy;
        $other = User::factory()->admin()->create(['is_active' => true]);
        $other->update(['is_active' => true]);
        $onlyOther->forceFill(['is_active' => false])->save();
        // With $other active, $me can be demoted by $other; once $other is inactive, not any more.
        $this->assertTrue($policy->changeRole($other, $me, Role::Sales)->allowed());
        $other->forceFill(['is_active' => false])->save();
        $this->assertTrue($policy->changeRole($other->forceFill(['is_active' => true]), $me, Role::Sales)->denied());
        $this->assertTrue($policy->deactivate($other, $me)->denied());
    }

    public function test_users_are_never_deleted(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'users'));

        foreach ($routes as $route) {
            if (in_array('DELETE', $route->methods(), true)) {
                $this->assertMatchesRegularExpression('#/(two-factor|sessions)$#', $route->uri());
            }
        }
    }

    // ---- Deactivation --------------------------------------------------------------------------

    public function test_deactivation_reassigns_open_leads_and_pending_follow_ups_and_kills_sessions(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->admin()->create();
        $leaving = User::factory()->sales()->create();
        $taker = User::factory()->sales()->create();
        $open = LeadStatus::where('type', 'open')->first();
        $won = LeadStatus::where('type', 'won')->first();

        $openLead = Lead::factory()->create(['assigned_to' => $leaving->id, 'lead_status_id' => $open->id]);
        $wonLead = Lead::factory()->create(['assigned_to' => $leaving->id, 'lead_status_id' => $won->id]);
        $pending = FollowUp::forceCreate(['lead_id' => $openLead->id, 'assigned_to' => $leaving->id, 'created_by' => $admin->id, 'type' => 'call', 'due_at' => now()->addDay(), 'status' => 'pending']);
        $done = FollowUp::forceCreate(['lead_id' => $openLead->id, 'assigned_to' => $leaving->id, 'created_by' => $admin->id, 'type' => 'call', 'due_at' => now()->subDay(), 'status' => 'completed']);
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $leaving->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->actingAs($admin)->get("/users/{$leaving->id}/deactivate")->assertOk()->assertSee('1 open lead');
        $this->post("/users/{$leaving->id}/deactivate", ['reassign_to' => $taker->id])->assertRedirect(route('users.index'));

        $this->assertFalse($leaving->fresh()->is_active);
        $this->assertSame($taker->id, $openLead->fresh()->assigned_to);
        $this->assertSame($leaving->id, $wonLead->fresh()->assigned_to); // closed leads keep their history
        $this->assertSame($taker->id, $pending->fresh()->assigned_to);
        $this->assertSame($leaving->id, $done->fresh()->assigned_to);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $leaving->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'user.deactivated')->first()->meta['leads_reassigned']);

        $this->post("/users/{$leaving->id}/reactivate")->assertRedirect();
        $this->assertTrue($leaving->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.reactivated']);
    }

    public function test_deactivation_can_leave_work_unassigned_and_rolls_back_on_failure(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->admin()->create();
        $leaving = User::factory()->sales()->create();
        $lead = Lead::factory()->create(['assigned_to' => $leaving->id, 'lead_status_id' => LeadStatus::where('type', 'open')->value('id')]);

        $this->actingAs($admin)->post("/users/{$leaving->id}/deactivate", ['reassign_to' => ''])->assertRedirect();
        $this->assertNull($lead->fresh()->assigned_to);

        // Reassigning to a deactivated user is refused and nothing changes.
        $other = User::factory()->sales()->create();
        $lead->forceFill(['assigned_to' => $other->id])->save();
        $this->post("/users/{$other->id}/deactivate", ['reassign_to' => $leaving->id])->assertSessionHasErrorsIn('deactivate', 'reassign_to');
        $this->assertTrue($other->fresh()->is_active);
        $this->assertSame($other->id, $lead->fresh()->assigned_to);

        // A failure inside the transaction leaves everything as it was.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'delete from "sessions"')) {
                throw new \RuntimeException('boom');
            }
        });
        try {
            app(\App\Actions\Users\DeactivateUser::class)($other, null);
        } catch (\RuntimeException) {
        }
        $this->assertTrue($other->fresh()->is_active);
        $this->assertSame($other->id, $lead->fresh()->assigned_to);
    }

    // ---- Two-step reset, sessions, teams -------------------------------------------------------

    public function test_admin_can_turn_off_two_step_and_sign_a_user_out_everywhere(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['two_factor_secret' => encrypt('x'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()]);
        DB::table('sessions')->insert(['id' => 'sess-2', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);

        $this->actingAs($admin)->delete("/users/{$user->id}/two-factor")->assertRedirect();
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.two_factor_reset_by_admin']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.two_factor_disabled']);

        $this->delete("/users/{$user->id}/sessions")->assertRedirect();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.sessions_revoked']);
    }

    public function test_teams_are_unique_ignoring_case_and_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/teams', ['name' => 'Inbound Growth'])->assertRedirect();
        $this->post('/teams', ['name' => 'inbound growth'])->assertSessionHasErrorsIn('team', 'name');
        $this->assertSame(1, Team::count());

        $team = Team::first();
        $this->put("/teams/{$team->id}", ['name' => 'Inbound'])->assertRedirect();
        $this->assertSame('Inbound', $team->fresh()->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'team.created']);
        $this->assertSame(['from' => 'Inbound Growth', 'to' => 'Inbound'], AuditLog::where('action', 'team.updated')->first()->meta);
    }

    public function test_user_email_is_unique_ignoring_case(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create(['email' => 'boss@example.com']);

        $this->actingAs($admin)->post('/users', ['name' => 'Copy', 'email' => 'BOSS@Example.com', 'role' => 'sales'])
            ->assertSessionHasErrorsIn('invite', 'email');
        $this->assertSame(1, User::count());
    }
}
