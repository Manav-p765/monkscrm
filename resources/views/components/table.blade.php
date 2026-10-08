{{--
    Prototype table card (LeadListView). Pages write plain <th>/<td>; styling comes from here.
    Clickable rows: <tr data-href="{{ route('leads.show', $lead) }}"> and put a real link in the
    first cell (keyboard/screen-reader access). Pass :paginator="$leads" (25 per page) for the footer
    ("Showing 1–25 of 240") and page links. <x-slot:footer> replaces the default footer text.
    Use <x-empty-state> inside a single full-width row when there is nothing to show.

    <x-table :paginator="$leads">
        <x-slot:head><tr><th>Lead &amp; Organization</th><th>Status</th></tr></x-slot:head>
        @foreach ($leads as $lead) <tr data-href="...">...</tr> @endforeach
    </x-table>
--}}
@props(['paginator' => null, 'footer' => null, 'title' => null, 'aside' => null])

<div {{ $attributes->class('bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden') }}>
    @if ($title)
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-4">
            <span class="text-xs font-bold text-slate-800">{{ $title }}</span>
            @if ($aside)
                <span class="text-xs text-slate-500">{{ $aside }}</span>
            @endif
        </div>
    @endif
    <div x-data="clickableRows" class="overflow-x-auto">
        <table x-on:click="go" @class([
            'w-full text-left border-collapse text-xs',
            '[&_thead_tr]:bg-slate-50/80 [&_thead_tr]:border-b [&_thead_tr]:border-slate-200 [&_thead_tr]:text-slate-500 [&_thead_tr]:font-semibold',
            '[&_th]:py-3 [&_th]:px-4 [&_th]:whitespace-nowrap [&_th]:font-semibold',
            '[&_td]:py-3.5 [&_td]:px-4 [&_td]:align-middle',
            '[&_tbody]:divide-y [&_tbody]:divide-slate-100 [&_tbody_tr]:transition [&_tbody_tr:hover]:bg-slate-50/80',
            '[&_tr[data-href]]:cursor-pointer',
        ])>
            <thead>{{ $head }}</thead>
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>

    @if ($footer?->isNotEmpty() || $paginator)
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50/60 p-3 px-4 text-2xs text-slate-500">
            @if ($footer?->isNotEmpty())
                {{ $footer }}
            @elseif ($paginator && $paginator->total() > 0)
                <span>Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
            @else
                <span></span>
            @endif
            @if ($paginator && $paginator->hasPages())
                {{ $paginator->onEachSide(1)->links('partials.pagination') }}
            @endif
        </div>
    @endif
</div>
