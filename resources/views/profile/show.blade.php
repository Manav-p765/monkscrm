<x-layouts.app title="My Profile" subtitle="Your account details, password and two-step sign-in" width="5xl">
    @php($status = session('status'))

    {{-- Account card (prototype MyProfileModal header + details) --}}
    <section aria-labelledby="account-heading" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs">
        <div class="flex items-center gap-3 border-b border-slate-100 pb-4">
            <x-avatar :user="$user" size="md" class="size-10 text-xs" />
            <div>
                <h2 id="account-heading" class="text-sm font-bold text-slate-900">{{ $user->name }}</h2>
                <span class="rounded border border-indigo-200 bg-indigo-50 px-1.5 text-3xs font-bold tracking-wider text-indigo-700 uppercase">{{ $user->role->value }}</span>
            </div>
        </div>
        <dl class="grid grid-cols-1 gap-x-8 gap-y-4 text-xs sm:grid-cols-2">
            <div>
                <dt class="text-3xs font-semibold text-slate-500 uppercase">Full Name</dt>
                <dd class="mt-0.5 font-medium text-slate-800">{{ $user->name }}</dd>
            </div>
            <div>
                <dt class="text-3xs font-semibold text-slate-500 uppercase">Email (Account Identity)</dt>
                <dd class="mt-0.5 font-medium text-slate-800">{{ $user->email }}</dd>
            </div>
            <div>
                <dt class="text-3xs font-semibold text-slate-500 uppercase">Role</dt>
                <dd class="mt-0.5 font-medium text-slate-800">{{ $user->role->label() }}</dd>
            </div>
            <div>
                <dt class="text-3xs font-semibold text-slate-500 uppercase">Last Signed In</dt>
                <dd class="mt-0.5 font-medium text-slate-800">{{ $user->last_login_at?->format('j M Y, H:i T') ?? 'Never' }}</dd>
            </div>
        </dl>
        <p class="text-xs text-slate-500">To change your name or email address, ask an administrator.</p>
    </section>

    {{-- Change password --}}
    <section aria-labelledby="password-heading" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs">
        <div>
            <h2 id="password-heading" class="text-xs font-bold tracking-wider text-slate-900 uppercase">Change Password</h2>
            <p class="mt-0.5 text-xs text-slate-500">At least 12 characters, with upper and lower case letters and a number.</p>
        </div>

        <form method="POST" action="{{ route('user-password.update') }}" class="max-w-md space-y-3.5" novalidate>
            @csrf
            @method('PUT')

            <x-input name="current_password" type="password" label="Current Password" bag="updatePassword" reveal required autocomplete="current-password" />
            <x-input name="password" type="password" label="New Password" bag="updatePassword" reveal required autocomplete="new-password"
                     hint="Passwords that have appeared in known data leaks are not allowed." />
            <x-input name="password_confirmation" type="password" label="Type the New Password Again" bag="updatePassword" required autocomplete="new-password" />

            <div class="pt-1">
                <x-button icon="key-round">Change Password</x-button>
            </div>
        </form>
    </section>

    {{-- Two-step sign-in --}}
    <section aria-labelledby="two-step-heading" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xs">
        <div>
            <h2 id="two-step-heading" class="flex items-center gap-2 text-xs font-bold tracking-wider text-slate-900 uppercase">
                Two-Step Sign-In
                @if ($user->hasTwoFactorEnabled())
                    <x-badge tone="green">On</x-badge>
                @else
                    <x-badge>Off</x-badge>
                @endif
            </h2>
            <p class="mt-0.5 max-w-prose text-xs text-slate-500">
                When it's on, signing in also asks for a 6-digit code from an authenticator app on your phone
                (for example Google Authenticator or Microsoft Authenticator).
            </p>
        </div>

        @if ($user->hasTwoFactorEnabled())
            @if (in_array($status, ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true))
                <div class="max-w-md space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs">
                    <p class="font-bold text-amber-900">Save these recovery codes now</p>
                    <p class="text-amber-900">If you lose your phone, each code lets you sign in once. They won't be shown again.</p>
                    <ul class="grid grid-cols-2 gap-1 pt-1 font-mono text-slate-900">
                        @foreach ($user->recoveryCodes() as $code)
                            <li>{{ $code }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-4">
                <x-confirm-dialog trigger="Turn Off Two-Step Sign-In" trigger-variant="danger-soft"
                                  :action="route('two-factor.disable')" method="DELETE"
                                  title="Turn off two-step sign-in for {{ $user->email }}?"
                                  confirm="Turn Off Two-Step Sign-In" loading="Turning off…">
                    Signing in will only need your password. You can turn it back on at any time.
                </x-confirm-dialog>

                <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                    @csrf
                    <x-button variant="text" loading="Creating codes…">Get New Recovery Codes</x-button>
                </form>
            </div>
        @elseif ($user->two_factor_secret)
            {{-- Started but not confirmed yet: show setup details and ask for a code. --}}
            <ol class="max-w-xl list-decimal space-y-5 pl-5 text-xs text-slate-700">
                <li>
                    <p>Open your authenticator app and scan this QR code.</p>
                    <div class="mt-3 inline-block rounded-xl border border-slate-200 bg-white p-2">
                        {!! $user->twoFactorQrCodeSvg() !!}
                    </div>
                    <p class="mt-2 text-slate-600">
                        Can't scan it? Enter this key instead:
                        <code class="rounded border border-slate-200 bg-slate-50 px-2 py-0.5 font-mono">{{ decrypt($user->two_factor_secret) }}</code>
                    </p>
                </li>
                <li>
                    <form method="POST" action="{{ route('two-factor.confirm') }}" class="max-w-xs space-y-3" novalidate>
                        @csrf
                        <x-input name="code" label="Enter the 6-Digit Code the App Shows" bag="confirmTwoFactorAuthentication"
                                 inputmode="numeric" autocomplete="one-time-code" required />
                        <x-button loading="Checking…" icon="shield-check">Turn On Two-Step Sign-In</x-button>
                    </form>
                </li>
            </ol>

            <form method="POST" action="{{ route('two-factor.disable') }}">
                @csrf
                @method('DELETE')
                <x-button variant="text" loading="Cancelling…">Cancel Setup</x-button>
            </form>
        @else
            <form method="POST" action="{{ route('two-factor.enable') }}">
                @csrf
                <x-button loading="Starting…" icon="shield-check">Set Up Two-Step Sign-In</x-button>
            </form>
        @endif
    </section>
</x-layouts.app>
