{{-- Prototype priority (getPriorityBadge): dot + label. Only High (amber) and Urgent (rose) are coloured. --}}
@props(['value' => null, 'size' => 'sm'])

@php
    $styles = [
        'urgent' => ['text-rose-700 font-semibold', 'bg-rose-600'],
        'high' => ['text-amber-700 font-medium', 'bg-amber-500'],
        'medium' => ['text-slate-600', 'bg-slate-400'],
        'low' => ['text-slate-500', 'bg-slate-300'],
    ];
    $v = $value instanceof \App\Enums\LeadPriority ? $value->value : $value;
@endphp

@if ($v)
    <span {{ $attributes->class(['inline-flex items-center gap-1.5 whitespace-nowrap', $size === 'xs' ? 'text-3xs' : 'text-2xs']) }}>
        <span class="size-1.5 rounded-full {{ $styles[$v][1] }}" aria-hidden="true"></span>
        <span class="{{ $styles[$v][0] }}">{{ ucfirst($v) }}</span>
    </span>
@else
    <span {{ $attributes->class('text-2xs text-slate-500') }}>—</span>
@endif
