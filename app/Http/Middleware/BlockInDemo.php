<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo site only (DEMO_MODE=true): visitors share the demo logins, so nothing that could lock others out,
 * take over an account or send email is allowed. Everything else (leads, pipeline, settings) works and is
 * reset by re-seeding.
 */
class BlockInDemo
{
    public const MESSAGE = 'This is turned off in the demo.';

    /** Route names blocked on the demo site. */
    private const BLOCKED = [
        // Own account: password and two-step sign-in.
        'user-password.update',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.regenerate-recovery-codes',
        // Password reset emails (forgot password and the reset form).
        'password.email',
        'password.update',
        // Users: invites and changes to the shared demo accounts.
        'users.store',
        'users.update',
        'users.resend-invite',
        'users.password-reset',
        'users.two-factor.disable',
        'users.sessions.revoke',
        'users.deactivate',
        'users.reactivate',
        'invite.store',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('crm.demo') || ! $request->routeIs(...self::BLOCKED)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, self::MESSAGE);
        }

        return back()->with('error', self::MESSAGE);
    }
}
