<?php

namespace App\Http\Controllers;

use App\Actions\Users\DeactivateUser;
use App\Enums\FollowUpStatus;
use App\Enums\LeadStatusType;
use App\Enums\Role;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Http\Requests\DeactivateUserRequest;
use App\Http\Requests\InviteUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UserListRequest;
use App\Models\Team;
use App\Models\User;
use App\Notifications\InviteUser;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Users & Teams (prototype UsersTeamsView). Admins manage users; managers get a read-only list;
 * sales get 403 (route middleware can:view-users). Admins never see or set anyone's password:
 * users are invited and choose their own password through a single-use link.
 */
class UserController extends Controller
{
    public function index(UserListRequest $request): View
    {
        return $this->render($request);
    }

    /** Same page with the edit modal open for one user (its own URL). */
    public function edit(UserListRequest $request, User $user): View
    {
        Gate::authorize('update', $user);

        return $this->render($request, editing: $user);
    }

    /** Same page with the deactivate dialog open for one user. */
    public function confirmDeactivate(UserListRequest $request, User $user): View
    {
        Gate::authorize('deactivate', $user);

        return $this->render($request, deactivating: $user);
    }

    public function store(InviteUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'team_id' => $data['team_id'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'phone' => $data['phone'] ?? null,
            // Nobody knows this password: the user sets their own through the invite link.
            'password' => Hash::make(Str::random(64)),
            'is_active' => true,
        ]);
        $user->save();

        $url = $user->createInviteLink();
        defer(fn () => $user->notify(new InviteUser($url)));

        Audit::log('user.invited', $user, ['role' => $user->role->value, 'team_id' => $user->team_id]);

        return redirect()->route('users.index')
            ->with('status', "Invite sent to {$user->name}")
            ->with('credential_link', $this->sealLink($user, $url, 'invite'));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $oldRole = $user->role;

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'team_id' => $data['team_id'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);
        $changed = array_keys($user->getDirty());
        $user->save();

        if ($user->role !== $oldRole) {
            Audit::log('user.role_changed', $user, ['from' => $oldRole->value, 'to' => $user->role->value]);
        }
        $fields = array_values(array_diff($changed, ['role']));
        if ($fields !== []) {
            Audit::log('user.updated', $user, ['fields' => $fields]);
        }

        return redirect()->route('users.index')->with('status', "{$user->name} saved");
    }

    public function resendInvite(User $user): RedirectResponse
    {
        Gate::authorize('resendInvite', $user);

        $url = $user->createInviteLink();
        defer(fn () => $user->notify(new InviteUser($url)));
        Audit::log('user.invite_resent', $user);

        return redirect()->route('users.index')
            ->with('status', "New invite sent to {$user->name}")
            ->with('credential_link', $this->sealLink($user, $url, 'invite'));
    }

    public function sendPasswordReset(User $user): RedirectResponse
    {
        Gate::authorize('sendPasswordReset', $user);

        $token = Password::broker()->createToken($user);
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);
        $user->sendPasswordResetNotification($token);
        Audit::log('user.password_reset_link_sent', $user);

        return redirect()->route('users.index')
            ->with('status', "Password reset link sent to {$user->name}")
            ->with('credential_link', $this->sealLink($user, $url, 'reset'));
    }

    public function disableTwoFactor(User $user): RedirectResponse
    {
        Gate::authorize('disableTwoFactor', $user);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        Audit::log('user.two_factor_reset_by_admin', $user);

        return redirect()->route('users.index')->with('status', "Two-step sign-in turned off for {$user->name}");
    }

    public function revokeSessions(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('revokeSessions', $user);

        $count = DB::table('sessions')->where('user_id', $user->id)->delete();
        Audit::log('user.sessions_revoked', $user, ['sessions' => $count]);

        if ($user->is($request->user())) {
            return redirect()->route('login');
        }

        return redirect()->route('users.index')->with('status', "{$user->name} has been signed out everywhere");
    }

    public function deactivate(DeactivateUserRequest $request, User $user, DeactivateUser $deactivate): RedirectResponse
    {
        $reassignTo = $request->validated('reassign_to') ? User::find($request->validated('reassign_to')) : null;
        $moved = $deactivate($user, $reassignTo);

        $detail = $moved['leads'] + $moved['follow_ups'] > 0
            ? ($reassignTo
                ? " · {$moved['leads']} leads and {$moved['follow_ups']} follow-ups moved to {$reassignTo->name}"
                : " · {$moved['leads']} leads and {$moved['follow_ups']} follow-ups left unassigned")
            : '';

        return redirect()->route('users.index')->with('status', "{$user->name} deactivated{$detail}");
    }

    public function reactivate(User $user): RedirectResponse
    {
        Gate::authorize('reactivate', $user);

        $user->forceFill(['is_active' => true])->save();
        Audit::log('user.reactivated', $user);

        return redirect()->route('users.index')->with('status', "{$user->name} reactivated");
    }

    private function render(UserListRequest $request, ?User $editing = null, ?User $deactivating = null): View
    {
        $filters = $request->validated();

        $users = User::query()
            ->with('team:id,name')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->search($term, ['name', 'email', 'job_title']))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['team'] ?? null, fn ($q, $team) => $q->where('team_id', $team))
            ->when($filters['status'] ?? null, fn ($q, $status) => match ($status) {
                'active' => $q->where('is_active', true)->whereNotNull('last_login_at'),
                'invited' => $q->where('is_active', true)->whereNull('last_login_at'),
                'deactivated' => $q->where('is_active', false),
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'filters' => $filters,
            'filtersActive' => array_filter($filters) !== [],
            'teams' => Team::query()->ordered()->withCount('users')->get(),
            'roles' => collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => $r->label()]),
            'canManage' => $request->user()->can('create', User::class),
            'editing' => $editing,
            'deactivating' => $deactivating,
            'reassignOptions' => $deactivating
                ? User::query()->where('is_active', true)->whereKeyNot($deactivating->id)->orderBy('name')->pluck('name', 'id')
                : collect(),
            // What the deactivate dialog will move (admin-level counts, not a user-facing lead list).
            'deactivateCounts' => $deactivating ? [
                'leads' => Lead::query()->where('assigned_to', $deactivating->id)
                    ->whereHas('status', fn ($q) => $q->where('type', LeadStatusType::Open))->count(),
                'follow_ups' => FollowUp::query()->where('assigned_to', $deactivating->id)
                    ->where('status', FollowUpStatus::Pending)->count(),
            ] : null,
            'credential' => $this->openLink(session('credential_link')),
        ]);
    }

    /**
     * The set-password / reset link is shown once in the credentials card so an admin can share it
     * privately if email isn't working. It travels encrypted in the flash data (one request only).
     */
    private function sealLink(User $user, string $url, string $kind): string
    {
        return Crypt::encryptString(json_encode(['name' => $user->name, 'email' => $user->email, 'url' => $url, 'kind' => $kind]));
    }

    /**
     * @return array{name: string, email: string, url: string, kind: string}|null
     */
    private function openLink(?string $sealed): ?array
    {
        return $sealed ? json_decode(Crypt::decryptString($sealed), true) : null;
    }
}
