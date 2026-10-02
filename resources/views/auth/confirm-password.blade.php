<x-layouts.guest title="Confirm your password">
    <p class="mb-6 text-slate-600">For your security, please enter your password again to continue.</p>

    <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5" novalidate>
        @csrf

        <x-input name="password" type="password" label="Password" required autofocus autocomplete="current-password" />

        <div class="flex items-center justify-between gap-4 pt-1">
            <x-button loading="Checking…">Confirm password</x-button>
            <x-button variant="text" href="{{ route('profile.show') }}">Cancel</x-button>
        </div>
    </form>
</x-layouts.guest>
