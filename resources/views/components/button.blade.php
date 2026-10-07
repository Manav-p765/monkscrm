{{--
    Prototype buttons. Text says exactly what the button does ("Create Lead", never "Submit").
    variant: primary | secondary | danger | danger-soft | success | ghost | text | dark
    size:    md (default) | sm | lg (full-width auth buttons)
    icon:    Lucide icon name shown before the text, e.g. icon="plus"
    href:    renders a link styled as a button
    loading: text shown while the form submits; the button is disabled to prevent double submits
--}}
@props(['variant' => 'primary', 'size' => 'md', 'type' => 'submit', 'href' => null, 'icon' => null, 'loading' => 'Saving…'])

@php
    $variants = [
        'primary' => 'bg-indigo-600 hover:bg-indigo-700 text-white font-semibold shadow-sm',
        'secondary' => 'bg-white hover:bg-slate-50 text-slate-700 font-semibold border border-slate-200 shadow-2xs',
        'danger' => 'bg-rose-600 hover:bg-rose-700 text-white font-semibold shadow-sm',
        'danger-soft' => 'bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold border border-rose-200',
        'success' => 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-semibold border border-emerald-200',
        'ghost' => 'text-slate-600 hover:bg-slate-100 font-medium',
        'text' => 'text-indigo-600 hover:text-indigo-800 font-semibold',
        'dark' => 'bg-slate-800 hover:bg-slate-700 text-white font-medium',
    ];
    $sizes = $variant === 'text'
        ? ['sm' => 'text-xs', 'md' => 'text-xs', 'lg' => 'text-sm']
        : ['sm' => 'px-2.5 py-1 text-xs rounded-md', 'md' => 'py-2 px-3.5 text-xs rounded-lg', 'lg' => 'w-full py-2.5 px-4 text-sm rounded-xl'];
    $classes = 'inline-flex items-center justify-center gap-1.5 transition active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100 '
        .($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
    $iconSize = $size === 'sm' ? 'size-3' : ($size === 'lg' ? 'size-4' : 'size-3.75');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-dynamic-component :component="'lucide-'.$icon" :class="$iconSize" aria-hidden="true" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}"
            @if ($type === 'submit' && $loading) data-loading-text="{{ $loading }}" @endif
            {{ $attributes->class($classes) }}>
        @if ($icon)<x-dynamic-component :component="'lucide-'.$icon" :class="$iconSize" aria-hidden="true" />@endif
        <span>{{ $slot }}</span>
    </button>
@endif
