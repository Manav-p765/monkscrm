<x-layouts.app title="My profile">
    @php($status = session('status'))

    <div class="divide-y divide-slate-200 [&>section]:py-10 [&>section:first-child]:pt-0">
        <section aria-labelledby="account-heading">
            <h2 id="account-heading" class="text-lg font-semibold text-slate-900">Your account</h2>
            <dl class="mt-5 grid max-w-2xl grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
                <div>
                    <dt class="text-slate-500">Name</dt>
                    <dd class="mt-0.5 font-medium">{{ $user->name }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Email address</dt>
                    <dd class="mt-0.5 font-medium">{{ $user->email }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Role</dt>
                    <dd class="mt-0.5 font-medium">{{ $user->role->label() }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Last signed in</dt>
                    <dd class="mt-0.5 font-medium">{{ $user->last_login_at?->format('j M Y, H:i T') ?? 'Never' }}</dd>
                </div>
            </dl>
            <p class="mt-5 text-slate-500">To change your name or email address, ask an administrator.</p>
        </section>

        <section aria-labelledby="password-heading">
            <h2 id="password-heading" class="text-lg font-semibold text-slate-900">Change password</h2>

            <form method="POST" action="{{ route('user-password.update') }}" class="mt-5 max-w-md space-y-5" novalidate>
                @csrf
                @method('PUT')

                <x-input name="current_password" type="password" label="Current password" bag="updatePassword" required autocomplete="current-password" />
                <x-input name="password" type="password" label="New password" bag="updatePassword" required autocomplete="new-password"
                         hint="At least 12 characters, with upper and lower case letters and a number. Passwords that have appeared in known data leaks are not allowed." />
                <x-input name="password_confirmation" type="password" label="Type the new password again" bag="updatePassword" required autocomplete="new-password" />

                <x-button>Change password</x-button>
            </form>
        </section>

        <section aria-labelledby="two-step-heading">
            <h2 id="two-step-heading" class="flex items-center gap-3 text-lg font-semibold text-slate-900">
                Two-step sign-in
                @if ($user->hasTwoFactorEnabled())
                    <x-badge tone="green">On</x-badge>
                @else
                    <x-badge>Off</x-badge>
                @endif
            </h2>
            <p class="mt-1 max-w-prose text-slate-600">
                When it's on, signing in also asks for a 6-digit code from an authenticator app on your phone
                (for example Google Authenticator or Microsoft Authenticator).
            </p>

            @if ($user->hasTwoFactorEnabled())
                @if (in_array($status, ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true))
                    <div class="mt-6 max-w-md rounded-md bg-amber-50 p-4">
                        <p class="font-medium text-amber-900">Save these recovery codes now</p>
                        <p class="mt-1 text-amber-900">
                            If you lose your phone, each code lets you sign in once. They won't be shown again.
                        </p>
                        <ul class="mt-3 grid grid-cols-2 gap-1 font-mono text-slate-900">
                            @foreach ($user->recoveryCodes() as $code)
                                <li>{{ $code }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-6 flex flex-wrap items-center gap-6">
                    <x-confirm-dialog trigger="Turn off two-step sign-in" trigger-variant="secondary"
                                      :action="route('two-factor.disable')" method="DELETE"
                                      title="Turn off two-step sign-in for {{ $user->email }}?"
                                      confirm="Turn off two-step sign-in" loading="Turning off…">
                        Signing in will only need your password. You can turn it back on at any time.
                    </x-confirm-dialog>

                    <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                        @csrf
                        <x-button variant="text" loading="Creating codes…">Get new recovery codes</x-button>
                    </form>
                </div>
            @elseif ($user->two_factor_secret)
                {{-- Started but not confirmed yet: show setup details and ask for a code. --}}
                <ol class="mt-6 max-w-xl list-decimal space-y-6 pl-5">
                    <li>
                        <p>Open your authenticator app and scan this QR code.</p>
                        <div class="mt-3 inline-block bg-white p-2">
                            {!! $user->twoFactorQrCodeSvg() !!}
                        </div>
                        <p class="mt-2 text-slate-600">
                            Can't scan it? Enter this key instead:
                            <code class="rounded bg-slate-100 px-2 py-0.5 font-mono">{{ decrypt($user->two_factor_secret) }}</code>
                        </p>
                    </li>
                    <li>
                        <form method="POST" action="{{ route('two-factor.confirm') }}" class="max-w-xs space-y-4" novalidate>
                            @csrf
                            <x-input name="code" label="Enter the 6-digit code the app shows" bag="confirmTwoFactorAuthentication"
                                     inputmode="numeric" autocomplete="one-time-code" required />
                            <x-button loading="Checking…">Turn on two-step sign-in</x-button>
                        </form>
                    </li>
                </ol>

                <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-6">
                    @csrf
                    @method('DELETE')
                    <x-button variant="text" loading="Cancelling…">Cancel setup</x-button>
                </form>
            @else
                <form method="POST" action="{{ route('two-factor.enable') }}" class="mt-6">
                    @csrf
                    <x-button loading="Starting…">Set up two-step sign-in</x-button>
                </form>
            @endif
        </section>
    </div>
</x-layouts.app>
