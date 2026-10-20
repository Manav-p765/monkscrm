<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\RequireTwoFactorForAdmins;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return ['sales' => ['sales'], 'manager' => ['manager'], 'admin' => ['admin']];
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->{$role}()->create(['name' => 'Old Name', 'job_title' => null, 'phone' => null]);
    }

    #[DataProvider('roles')]
    public function test_every_role_edits_their_own_name_job_title_and_phone(string $role): void
    {
        $user = $this->userWithRole($role);

        $this->actingAs($user)->put('/profile', [
            'name' => 'New Name', 'job_title' => 'Account Executive', 'phone' => '+91 98765 43210',
        ])->assertRedirect(route('profile.show'))->assertSessionHas('status', 'Profile saved');

        $user->refresh();
        $this->assertSame(['New Name', 'Account Executive', '+91 98765 43210'], [$user->name, $user->job_title, $user->phone]);
        $this->assertSame(['fields' => ['name', 'job_title', 'phone']], AuditLog::where('action', 'profile.updated')->sole()->meta);
    }

    public function test_email_role_and_team_cannot_be_changed_from_my_profile(): void
    {
        $user = $this->userWithRole('sales');
        $team = Team::create(['name' => 'Inbound']);

        $this->actingAs($user)->put('/profile', [
            'name' => 'Old Name', 'email' => 'someone.else@example.com', 'role' => 'admin', 'team_id' => $team->id,
            'is_active' => false,
        ])->assertRedirect(route('profile.show'));

        $fresh = $user->fresh();
        $this->assertSame($user->email, $fresh->email);
        $this->assertSame(Role::Sales, $fresh->role);
        $this->assertNull($fresh->team_id);
        $this->assertTrue($fresh->is_active);
        $this->assertSame(0, AuditLog::where('action', 'profile.updated')->count(), 'Nothing changed, nothing audited');
    }

    public function test_invalid_input_shows_plain_errors_and_saves_nothing(): void
    {
        $user = $this->userWithRole('sales');

        $this->actingAs($user)->from('/profile')->put('/profile', ['name' => ' ', 'phone' => 'call me <maybe>'])
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('updateProfile', [
                'name' => 'Enter your full name.',
                'phone' => 'Use only digits, spaces and + - ( ) in the phone number.',
            ]);

        $this->assertSame('Old Name', $user->fresh()->name);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->put('/profile', ['name' => 'X'])->assertRedirect('/login');
    }

    public function test_admin_without_two_step_sign_in_must_set_it_up_first(): void
    {
        $admin = User::factory()->admin()->withoutTwoFactor()->create(['name' => 'Old Name']);

        $this->actingAs($admin)->put('/profile', ['name' => 'New Name'])
            ->assertRedirect('/profile')->assertSessionHas('error', RequireTwoFactorForAdmins::MESSAGE);

        $this->assertSame('Old Name', $admin->fresh()->name);
    }

    public function test_profile_page_shows_the_form_with_current_values(): void
    {
        $user = User::factory()->sales()->create(['name' => 'Priya Patel', 'job_title' => 'Account Executive']);

        $this->actingAs($user)->get('/profile')->assertOk()
            ->assertSee('Update Profile')
            ->assertSee('value="Priya Patel"', false)
            ->assertSee('value="Account Executive"', false)
            ->assertDontSee('To change your name or email address');
    }
}
