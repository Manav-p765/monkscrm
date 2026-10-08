<x-layouts.guest title="Set Your Password" intro="Choose the password you'll use to sign in to {{ config('app.name') }}.">
    <form method="POST" action="{{ route('invite.store') }}" class="space-y-4" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-input name="email" type="email" label="Email Address" size="lg" icon="mail" :value="$email" required autocomplete="username" />
        <x-input name="password" type="password" label="New Password" size="lg" icon="lock" reveal required autofocus autocomplete="new-password"
                 hint="At least 12 characters, with upper and lower case letters and a number." />
        <x-input name="password_confirmation" type="password" label="Type the New Password Again" size="lg" icon="lock" required autocomplete="new-password" />

        <div class="pt-2">
            <x-button size="lg" loading="Saving…">
                Set Password
                <x-lucide-arrow-right class="size-4" aria-hidden="true" />
            </x-button>
        </div>
    </form>
</x-layouts.guest>
