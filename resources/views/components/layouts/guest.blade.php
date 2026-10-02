@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="flex min-h-full flex-col items-center justify-center px-4 py-12">
    <div class="w-full max-w-sm">
        <p class="text-xl font-semibold text-slate-900">{{ config('app.name') }}</p>
        @if ($title)
            <h1 class="mt-6 text-2xl font-semibold text-slate-900">{{ $title }}</h1>
        @endif

        <div class="mt-6">
            <x-flash />
            {{ $slot }}
        </div>
    </div>
</body>
</html>
