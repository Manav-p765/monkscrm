<x-layouts.guest title="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-input name="email" type="email" label="Email address" :value="$request->email" required autocomplete="username" />
        <x-input name="password" type="password" label="New password" required autofocus autocomplete="new-password"
                 hint="At least 12 characters, with upper and lower case letters and a number." />
        <x-input name="password_confirmation" type="password" label="Type the new password again" required autocomplete="new-password" />

        <div class="pt-1">
            <x-button>Save new password</x-button>
        </div>
    </form>
</x-layouts.guest>
