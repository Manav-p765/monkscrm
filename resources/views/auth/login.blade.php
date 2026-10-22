<x-layouts.guest title="Sign In">
    <form method="POST" action="{{ route('login') }}" class="space-y-4" novalidate>
        @csrf

        <x-input name="email" type="email" label="Email Address" size="lg" icon="mail" required autofocus
                 autocomplete="username" placeholder="e.g. priya.patel@example.com" />

        <x-input name="password" type="password" label="Password" size="lg" icon="lock" reveal required
                 autocomplete="current-password" placeholder="Enter your password">
            <x-slot:aside>
                <a href="{{ route('password.request') }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">Forgot password?</a>
            </x-slot:aside>
        </x-input>

        <div class="pt-2">
            <x-button size="lg" loading="Signing in…">
                Sign In to {{ config('app.name') }}
                <x-lucide-arrow-right class="size-4" aria-hidden="true" />
            </x-button>
        </div>
    </form>
</x-layouts.guest>
