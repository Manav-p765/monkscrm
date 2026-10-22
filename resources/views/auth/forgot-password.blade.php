<x-layouts.guest title="Password Assistance"
                  intro="Enter your work email address and we'll email you a link to choose a new password.">
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4" novalidate>
        @csrf

        <x-input name="email" type="email" label="Email Address" size="lg" icon="mail" required autofocus autocomplete="username"
                 placeholder="e.g. priya.patel@example.com" />

        <div class="space-y-3 pt-2">
            <x-button size="lg" loading="Sending…">
                Email Me a Reset Link
                <x-lucide-arrow-right class="size-4" aria-hidden="true" />
            </x-button>
            <p class="text-center">
                <a href="{{ route('login') }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">Back to Sign In</a>
            </p>
        </div>
    </form>
</x-layouts.guest>
