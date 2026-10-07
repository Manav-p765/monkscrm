{{--
    Prototype tabs. Three looks, all from the prototype:
    - underline: lead detail tabs ("Activity Timeline 3 · Internal Notes 1 …")
    - pills:     settings sub-tabs and quick filters (dark pill = active)
    - segmented: Table / Pipeline toggle, Import Wizard / Past Imports

    Link tabs (server-side, e.g. settings sections or list filters): give each tab an `href`.
    In-page tabs: omit `href` and put matching <x-tab-panel name="…"> elements inside the slot.

    <x-tabs variant="underline" active="timeline" :tabs="[
        'timeline' => ['label' => 'Activity Timeline', 'count' => 3],
        'notes' => ['label' => 'Internal Notes', 'count' => 1],
    ]">
        <x-tab-panel name="timeline">…</x-tab-panel>
        <x-tab-panel name="notes">…</x-tab-panel>
    </x-tabs>
    Tab item keys: label, href?, count?, icon?, active? (for link tabs).
--}}
@props(['tabs', 'active' => null, 'variant' => 'underline', 'label' => 'Sections'])

@php
    $styles = [
        'underline' => [
            'list' => 'flex items-center gap-4 overflow-x-auto border-b border-slate-200 px-6 select-none',
            'tab' => 'flex items-center gap-1.5 whitespace-nowrap border-b-2 py-3 text-xs font-semibold transition',
            'on' => 'border-indigo-600 text-indigo-600',
            'off' => 'border-transparent text-slate-500 hover:text-slate-800',
            'countOn' => 'bg-indigo-100 text-indigo-700',
            'countOff' => 'bg-slate-100 text-slate-600',
        ],
        'pills' => [
            'list' => 'flex items-center gap-2 overflow-x-auto pb-1',
            'tab' => 'flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-medium transition',
            'on' => 'bg-slate-900 text-white font-semibold shadow-2xs',
            'off' => 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50',
            'countOn' => 'bg-white/20 text-white',
            'countOff' => 'bg-slate-100 text-slate-600',
        ],
        'segmented' => [
            'list' => 'inline-flex items-center rounded-lg border border-slate-200 bg-slate-100 p-1',
            'tab' => 'flex items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-1.5 text-xs font-medium transition',
            'on' => 'bg-white text-slate-900 font-semibold shadow-xs',
            'off' => 'text-slate-600 hover:text-slate-900',
            'countOn' => 'bg-slate-200 text-slate-700',
            'countOff' => 'bg-slate-200 text-slate-600',
        ],
    ][$variant] ?? [];
    $isLinks = collect($tabs)->contains(fn ($t) => isset($t['href']));
    $active ??= array_key_first($tabs);
@endphp

<div @unless ($isLinks) x-data="tabs" data-active="{{ $active }}" @endunless {{ $attributes }}>
    <div @if ($isLinks) role="navigation" aria-label="{{ $label }}" @else role="tablist" aria-label="{{ $label }}" @endif class="{{ $styles['list'] }}">
        @foreach ($tabs as $key => $tab)
            @php
                $on = $isLinks ? (bool) ($tab['active'] ?? false) : $key === $active;
                $tag = $isLinks ? 'a' : 'button';
            @endphp
            <{{ $tag }}
                @if ($isLinks)
                    href="{{ $tab['href'] }}" @if ($on) aria-current="page" @endif
                @else
                    type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}"
                    x-data="tabButton" data-tab="{{ $key }}" data-on="{{ $styles['on'] }}" data-off="{{ $styles['off'] }}"
                    x-on:click="select" x-bind:aria-selected="selected" x-bind:class="stateClasses"
                    aria-selected="{{ $on ? 'true' : 'false' }}"
                @endif
                @class([$styles['tab'], $on ? $styles['on'] : $styles['off']])>
                @isset($tab['icon'])<x-dynamic-component :component="'lucide-'.$tab['icon']" class="size-3.5" aria-hidden="true" />@endisset
                <span>{{ $tab['label'] }}</span>
                @isset($tab['count'])
                    <span class="rounded-full px-1.5 text-3xs font-semibold {{ $on ? $styles['countOn'] : $styles['countOff'] }}">{{ $tab['count'] }}</span>
                @endisset
            </{{ $tag }}>
        @endforeach
    </div>

    {{ $slot }}
</div>
