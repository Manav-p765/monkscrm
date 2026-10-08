<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Access\Response;

/**
 * User management. Admins manage users; managers see a read-only list; sales see nothing.
 * Safety rules: admins can't deactivate, demote or turn off two-step sign-in for themselves;
 * there is always at least one active admin; users are never deleted (there is no delete ability).
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return Permissions::allows($actor, 'view-users');
    }

    public function create(User $actor): bool
    {
        return Permissions::allows($actor, 'manage-users');
    }

    public function update(User $actor, User $target): bool
    {
        return Permissions::allows($actor, 'manage-users');
    }

    public function changeRole(User $actor, User $target, Role $newRole): Response
    {
        if (! Permissions::allows($actor, 'manage-users')) {
            return Response::deny();
        }

        if ($target->role === Role::Admin && $newRole !== Role::Admin) {
            if ($target->is($actor)) {
                return Response::deny('You can’t remove your own admin role. Ask another admin to do it.');
            }
            if ($target->is_active && ! User::otherActiveAdminsExist($target)) {
                return Response::deny('There must always be at least one active admin.');
            }
        }

        return Response::allow();
    }

    public function deactivate(User $actor, User $target): Response
    {
        if (! Permissions::allows($actor, 'manage-users') || ! $target->is_active) {
            return Response::deny();
        }
        if ($target->is($actor)) {
            return Response::deny('You can’t deactivate your own account.');
        }
        if ($target->role === Role::Admin && ! User::otherActiveAdminsExist($target)) {
            return Response::deny('There must always be at least one active admin.');
        }

        return Response::allow();
    }

    public function reactivate(User $actor, User $target): bool
    {
        return Permissions::allows($actor, 'manage-users') && ! $target->is_active;
    }

    public function resendInvite(User $actor, User $target): bool
    {
        return Permissions::allows($actor, 'manage-users') && $target->is_active && $target->isInvitePending();
    }

    public function sendPasswordReset(User $actor, User $target): bool
    {
        return Permissions::allows($actor, 'manage-users') && $target->is_active;
    }

    public function disableTwoFactor(User $actor, User $target): Response
    {
        if (! Permissions::allows($actor, 'manage-users') || ! $target->hasTwoFactorEnabled()) {
            return Response::deny();
        }
        if ($target->is($actor)) {
            return Response::deny('You can’t turn off your own two-step sign-in here. Use My Profile.');
        }

        return Response::allow();
    }

    public function revokeSessions(User $actor, User $target): bool
    {
        return Permissions::allows($actor, 'manage-users');
    }
}
