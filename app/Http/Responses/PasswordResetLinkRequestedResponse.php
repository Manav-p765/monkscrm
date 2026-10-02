<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Used for both the "sent" and "failed" outcomes of a forgot-password request,
 * so the response never reveals whether an account exists (or is throttled).
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = 'If an account exists for that email address, a password reset link has been sent. The link expires in 30 minutes.';

    public function toResponse($request): RedirectResponse
    {
        return redirect()->route('password.request')->with('status', __(self::MESSAGE));
    }
}
