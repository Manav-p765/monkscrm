<x-layouts.guest title="Two-Step Sign-In">
    <div x-data="twoFactorChallenge">
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4" novalidate>
            @csrf

            <div x-show="usingCode" class="space-y-4">
                <p class="text-xs leading-relaxed text-slate-600">Open your authenticator app and enter the 6-digit code for {{ config('app.name') }}.</p>
                <x-input name="code" label="6-Digit Code" size="lg" icon="key-round" inputmode="numeric" autocomplete="one-time-code" autofocus />
            </div>

            <div x-cloak x-show="useRecovery" class="space-y-4">
                <p class="text-xs leading-relaxed text-slate-600">Enter one of the recovery codes you saved when you set up two-step sign-in.</p>
                <x-input name="recovery_code" label="Recovery Code" size="lg" icon="key-round" autocomplete="off" />
            </div>

            <div class="space-y-3 pt-2">
                <x-button size="lg" loading="Checking…">
                    Verify and Sign In
                    <x-lucide-arrow-right class="size-4" aria-hidden="true" />
                </x-button>
                <p class="text-center">
                    <button type="button" x-on:click="toggleMode" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">
                        <span x-show="usingCode">Use a recovery code instead</span>
                        <span x-cloak x-show="useRecovery">Use my authenticator app</span>
                    </button>
                </p>
            </div>
        </form>
    </div>
</x-layouts.guest>
