{{--
    Prototype stat card (dashboard "Total Leads", "Follow-Ups Due"; reports; import validation).

    <x-stat label="Follow-Ups Due" tone="amber" hint="Due today or overdue" :href="route('follow-ups.index')">8</x-stat>
    tone: neutral | blue | amber | emerald | rose | indigo.  href: makes the whole card a link.
--}}
@props(['label', 'tone' => 'neutral', 'hint' => null, 'href' => null])

@php
    $tones = [
        'neutral' => ['hover' => 'hover:border-slate-300', 'value' => 'text-slate-900', 'hint' => 'text-slate-500 group-hover:text-indigo-600'],
        'blue' => ['hover' => 'hover:border-blue-300', 'value' => 'text-slate-900', 'hint' => 'text-blue-600 font-medium'],
        'amber' => ['hover' => 'hover:border-amber-300', 'value' => 'text-amber-800', 'hint' => 'text-amber-700 font-medium'],
        'emerald' => ['hover' => 'hover:border-emerald-300', 'value' => 'text-emerald-800', 'hint' => 'text-emerald-700 font-medium'],
        'rose' => ['hover' => 'hover:border-rose-300', 'value' => 'text-rose-700', 'hint' => 'text-rose-700 font-medium'],
        'indigo' => ['hover' => 'hover:border-indigo-300', 'value' => 'text-indigo-700', 'hint' => 'text-slate-500'],
    ];
    $t = $tones[$tone] ?? $tones['neutral'];
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class(['group block rounded-xl border border-slate-200 bg-white p-5 text-left shadow-2xs transition', $t['hover'] => $href]) }}>
    <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
    <div class="mt-1 text-2xl font-bold {{ $t['value'] }}">{{ $slot }}</div>
    @if ($hint)
        <div class="mt-1 flex items-center gap-1 text-2xs transition {{ $t['hint'] }}">
            <span>{{ $hint }}</span>
            @if ($href && $tone === 'neutral')
                <x-lucide-chevron-right class="size-3" aria-hidden="true" />
            @endif
        </div>
    @endif
</{{ $tag }}>
