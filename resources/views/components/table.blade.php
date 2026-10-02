{{--
    Lists and tables. Pages write plain <th>/<td>; styling comes from here.
    Clickable rows: <tr data-href="{{ route('leads.show', $lead) }}"> and put a real link in the
    first cell (keyboard/screen-reader access). Pass :paginator="$leads" (25 per page) for page links.

    <x-table :paginator="$leads">
        <x-slot:head><tr><th>Name</th><th>Status</th></tr></x-slot:head>
        @foreach ($leads as $lead) <tr data-href="...">...</tr> @endforeach
    </x-table>
--}}
@props(['paginator' => null])

<div x-data="clickableRows" class="overflow-x-auto">
    <table x-on:click="go" {{ $attributes->class([
        'min-w-full text-left text-sm',
        '[&_th]:px-3 [&_th]:py-2.5 [&_th]:font-medium [&_th]:whitespace-nowrap [&_th]:text-slate-600',
        '[&_td]:px-3 [&_td]:py-3 [&_td]:align-middle',
        '[&_tbody_tr]:border-t [&_tbody_tr]:border-slate-200 [&_tbody_tr:hover]:bg-slate-50',
        '[&_tr[data-href]]:cursor-pointer',
    ]) }}>
        <thead>{{ $head }}</thead>
        <tbody>{{ $slot }}</tbody>
    </table>
</div>

@if ($paginator && $paginator->hasPages())
    <nav class="mt-6" aria-label="Pages">
        {{ $paginator->onEachSide(1)->links() }}
    </nav>
@endif
