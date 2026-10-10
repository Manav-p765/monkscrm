{{--
    Sign-in screens, matching the prototype's AuthView: centred brand header, white card, footer line.
    <x-layouts.guest title="Sign In" heading="Welcome back" intro="…">…form…</x-layouts.guest>
--}}
@props(['title' => null, 'heading' => null, 'intro' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-full bg-slate-100 font-sans text-slate-900">
    <div class="flex min-h-screen flex-col justify-center px-4 py-10 sm:px-6 lg:px-8">
        <div class="text-center sm:mx-auto sm:w-full sm:max-w-md">
            <x-logo class="mx-auto mb-3 size-12 rounded-xl shadow-sm" />
            <p class="text-2xl font-bold tracking-tight text-slate-900">{{ config('app.name') }}</p>
            <p class="mt-1.5 text-sm text-slate-600">Simple, organized lead tracking and follow-up management</p>
        </div>

        <main class="mt-6 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="rounded-2xl border border-slate-200 bg-white px-6 py-8 shadow-sm sm:px-8">
                @if ($heading ?? $title)
                    <h1 class="text-sm font-bold text-slate-900">{{ $heading ?? $title }}</h1>
                @endif
                @if ($intro)
                    <p class="mt-1 text-xs leading-relaxed text-slate-600">{{ $intro }}</p>
                @endif

                <div @class(['space-y-4', 'mt-5' => $heading || $title || $intro])>
                    <x-flash />
                    {{ $slot }}
                </div>
            </div>

            <p class="mt-4 text-center text-xs text-slate-500">{{ config('app.name') }} · Simple, fast, secure lead management</p>
        </main>
    </div>
</body>
</html>
