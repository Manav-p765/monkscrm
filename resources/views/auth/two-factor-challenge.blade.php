<x-layouts.guest title="Enter your security code">
    <div x-data="twoFactorChallenge">
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-5" novalidate>
            @csrf

            <div x-show="usingCode" class="space-y-5">
                <p class="text-slate-600">Open your authenticator app and enter the 6-digit code for {{ config('app.name') }}.</p>
                <x-input name="code" label="6-digit code" inputmode="numeric" autocomplete="one-time-code" autofocus />
            </div>

            <div x-cloak x-show="useRecovery" class="space-y-5">
                <p class="text-slate-600">Enter one of the recovery codes you saved when you set up two-step sign-in.</p>
                <x-input name="recovery_code" label="Recovery code" autocomplete="off" />
            </div>

            <div class="flex items-center justify-between gap-4 pt-1">
                <x-button loading="Checking…">Verify and sign in</x-button>
                <x-button type="button" variant="text" x-on:click="toggleMode">
                    <span x-show="usingCode">Use a recovery code instead</span>
                    <span x-cloak x-show="useRecovery">Use my authenticator app</span>
                </x-button>
            </div>
        </form>
    </div>
</x-layouts.guest>
