{{--
    <x-badge :color="$lead->status->color">{{ $lead->status->name }}</x-badge>   status / tag (dot + tint)
    <x-badge tone="red">Overdue</x-badge>                                        fixed meaning (bold chip)
--}}
@if ($emitStyle)
    <style nonce="{{ Vite::cspNonce() }}">.{{ $colorClass }}{--badge-color:{{ $hex }}}</style>
@endif
@if ($hex)
    <span {{ $attributes->class([
        'inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-2xs font-medium border whitespace-nowrap',
        $palette['badge'] ?? 'text-slate-800 bg-[color-mix(in_srgb,var(--badge-color)_10%,white)] border-[color-mix(in_srgb,var(--badge-color)_35%,white)] '.$colorClass,
    ]) }}>
        @if ($dot)
            <span @class(['w-1.5 h-1.5 shrink-0 rounded-full', $palette['dot'] ?? 'bg-(--badge-color)']) aria-hidden="true"></span>
        @endif
        {{ $slot }}
    </span>
@else
    <span {{ $attributes->class([
        'inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-3xs font-bold border whitespace-nowrap',
        $toneClasses(),
    ]) }}>
        @if ($dot)
            <span class="w-1.5 h-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
        @endif
        {{ $slot }}
    </span>
@endif
