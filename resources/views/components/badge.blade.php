@if ($emitStyle)
    <style nonce="{{ Vite::cspNonce() }}">.{{ $colorClass }}{--badge-color:{{ $hex }}}</style>
@endif
@if ($hex)
    <span {{ $attributes->class([
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap text-slate-800 ring-1 ring-inset',
        'bg-[color-mix(in_srgb,var(--badge-color)_12%,white)] ring-[color-mix(in_srgb,var(--badge-color)_40%,white)]',
        $colorClass,
    ]) }}>
        <span class="size-2 shrink-0 rounded-full bg-(--badge-color)" aria-hidden="true"></span>
        {{ $slot }}
    </span>
@else
    <span {{ $attributes->class([
        'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
        $toneClasses(),
    ]) }}>{{ $slot }}</span>
@endif
