<x-layouts.guest title="Reset your password">
    <p class="mb-6 text-slate-600">
        Enter your work email address and we'll email you a link to choose a new password.
    </p>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5" novalidate>
        @csrf

        <x-input name="email" type="email" label="Email address" required autofocus autocomplete="username" />

        <div class="flex items-center justify-between gap-4 pt-1">
            <x-button loading="Sending…">Email me a reset link</x-button>
            <x-button variant="text" href="{{ route('login') }}">Back to sign in</x-button>
        </div>
    </form>
</x-layouts.guest>
