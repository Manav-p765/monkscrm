{{-- Sidebar item (prototype Sidebar.tsx): icon + label, optional count badge (amber when it needs attention). --}}
@props(['href', 'icon', 'active' => false, 'badge' => null, 'highlight' => false])

<a href="{{ $href }}"
   @if ($active) aria-current="page" @endif
   {{ $attributes->class([
       'flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-xs font-medium transition',
       'bg-indigo-50 font-semibold text-indigo-700' => $active,
       'text-slate-600 hover:bg-slate-100/80 hover:text-slate-900' => ! $active,
   ]) }}>
    <span class="flex items-center gap-2.5">
        <x-dynamic-component :component="'lucide-'.$icon" @class(['size-3.75', 'text-indigo-600' => $active, 'text-slate-400' => ! $active]) aria-hidden="true" />
        <span>{{ $slot }}</span>
    </span>
    @if ($badge !== null && $badge !== '')
        <span @class([
            'rounded-full px-1.5 text-3xs font-bold',
            'bg-amber-100 text-amber-800' => $highlight,
            'bg-indigo-100 text-indigo-700' => ! $highlight && $active,
            'bg-slate-100 text-slate-600' => ! $highlight && ! $active,
        ])>{{ $badge }}</span>
    @endif
</a>
