{{--
    Buttons say exactly what they do ("Save lead", never "Submit").
    variant: primary (max one per page header) | secondary | danger | text (plain text button)
    href:    renders a link styled as a button
    loading: text shown while the form submits; the button is disabled to prevent double submits
--}}
@props(['variant' => 'primary', 'type' => 'submit', 'href' => null, 'loading' => 'Saving…'])

@php
    $classes = match ($variant) {
        'secondary' => 'rounded-md px-4 py-2 bg-white text-slate-800 ring-1 ring-inset ring-slate-300 hover:bg-slate-50',
        'danger' => 'rounded-md px-4 py-2 bg-red-700 text-white hover:bg-red-800',
        'text' => 'rounded-sm text-blue-700 underline-offset-2 hover:underline',
        default => 'rounded-md px-4 py-2 bg-blue-700 text-white hover:bg-blue-800',
    };
    $classes = 'inline-flex items-center justify-center gap-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60 '.$classes;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}"
            @if ($type === 'submit' && $loading) data-loading-text="{{ $loading }}" @endif
            {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
