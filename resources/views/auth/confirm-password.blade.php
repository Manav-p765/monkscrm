<x-layouts.guest title="Confirm Your Password" intro="For your security, please enter your password again to continue.">
    <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-4" novalidate>
        @csrf

        <x-input name="password" type="password" label="Password" size="lg" icon="lock" reveal required autofocus autocomplete="current-password" />

        <div class="space-y-3 pt-2">
            <x-button size="lg" loading="Checking…">Confirm Password</x-button>
            <p class="text-center">
                <a href="{{ route('profile.show') }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">Cancel</a>
            </p>
        </div>
    </form>
</x-layouts.guest>
