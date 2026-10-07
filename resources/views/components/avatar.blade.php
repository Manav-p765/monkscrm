{{-- Initials circle in place of the prototype's photo avatars. size: xs (16px) | sm (24px) | md (32px) --}}
@props(['user', 'size' => 'sm'])

@php
    $sizes = ['xs' => 'size-4 text-4xs', 'sm' => 'size-6 text-3xs', 'md' => 'size-8 text-2xs'];
@endphp

<span {{ $attributes->class(['inline-flex shrink-0 items-center justify-center rounded-full border border-indigo-200 bg-indigo-50 font-bold text-indigo-700', $sizes[$size] ?? $sizes['sm']]) }}
      aria-hidden="true">{{ $user->initials() }}</span>
