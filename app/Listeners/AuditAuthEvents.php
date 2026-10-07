<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * Writes sign-in related events to the audit log. Lockouts and forced logouts of deactivated
 * users are logged where they happen (FortifyServiceProvider, EnsureUserIsActive).
 * Never logs passwords, tokens or codes; failed sign-ins record only the attempted email.
 */
class AuditAuthEvents
{
    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            Audit::log('auth.login', $event->user, userId: $event->user->id);
        }
    }

    public function handleFailed(Failed $event): void
    {
        Audit::log('auth.login_failed', $event->user, [
            'email' => mb_substr((string) ($event->credentials['email'] ?? ''), 0, 191),
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            Audit::log('auth.logout', $event->user, userId: $event->user->id);
        }
    }

    public function handlePasswordResetLinkSent(PasswordResetLinkSent $event): void
    {
        // Links an admin sends for someone else are logged as user.password_reset_link_sent instead.
        if (auth()->check() && auth()->id() !== $event->user->getKey()) {
            return;
        }

        if ($event->user instanceof User) {
            Audit::log('auth.password_reset_requested', $event->user, userId: $event->user->id);
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            Audit::log('auth.password_reset_completed', $event->user, userId: $event->user->id);
        }
    }

    public function handlePasswordUpdated(PasswordUpdatedViaController $event): void
    {
        if ($event->user instanceof User) {
            Audit::log('auth.password_changed', $event->user, userId: $event->user->id);
        }
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        Audit::log('auth.two_factor_enabled', $event->user, userId: $event->user->id);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        // Admin resets are logged as user.two_factor_reset_by_admin by the users controller.
        if (auth()->check() && auth()->id() !== $event->user->id) {
            return;
        }

        Audit::log('auth.two_factor_disabled', $event->user, userId: $event->user->id);
    }
}
