<x-layouts.guest title="Sign in">
    <form method="POST" action="{{ route('login') }}" class="space-y-5" novalidate>
        @csrf

        <x-input name="email" type="email" label="Email address" required autofocus autocomplete="username" />
        <x-input name="password" type="password" label="Password" required autocomplete="current-password" />

        <div class="flex items-center justify-between gap-4 pt-1">
            <x-button loading="Signing in…">Sign in</x-button>
            <x-button variant="text" href="{{ route('password.request') }}">Forgot your password?</x-button>
        </div>
    </form>
</x-layouts.guest>
