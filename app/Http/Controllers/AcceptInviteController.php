<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Public "set your password" page for invite links (password broker "invites": single use,
 * 48 hours, separate token table from password resets). Rate-limited in routes/web.php.
 */
class AcceptInviteController extends Controller
{
    use PasswordValidationRules;

    public function show(Request $request, string $token): View
    {
        return view('auth.accept-invite', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => $this->passwordRules(),
        ], [
            'password.confirmed' => 'The two passwords don’t match. Type the same password twice.',
        ]);

        $status = Password::broker('invites')->reset(
            [...$request->only('email', 'password', 'password_confirmation', 'token'), 'email' => Str::lower((string) $request->input('email'))],
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'This invite link has expired or was already used. Ask your administrator to send a new one.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Password set. You can sign in now.');
    }
}
