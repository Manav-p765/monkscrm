<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins must use two-step sign-in. Until an admin has turned it on (set up and confirmed), every page
 * except the ones needed to set it up, and sign out, sends them to My Profile.
 */
class RequireTwoFactorForAdmins
{
    public const MESSAGE = 'Admins must use two-step sign-in. Set it up below to continue.';

    /** Route names an admin without two-step sign-in may still use. */
    private const ALLOWED = [
        'profile.show',
        'logout',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'user-password.update',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // The demo site's shared admin login cannot use two-step sign-in (BlockInDemo).
        if (! $user || ! $user->isAdmin() || $user->hasTwoFactorEnabled() || config('crm.demo')) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED)) {
            if ($request->routeIs('profile.show') && ! $request->session()->has('error')) {
                $request->session()->now('error', self::MESSAGE);
            }

            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, self::MESSAGE);
        }

        return redirect()->route('profile.show')->with('error', self::MESSAGE);
    }
}
