{{--
    Prototype page header: bold title (optional count chip) + grey subtitle top-left; actions top-right
    (as in the prototype, a page may show more than one button). Rendered by the app layout from its
    `title`, `subtitle`, `count`, `badge`, `actions` and `more` props/slots. Pages don't use it directly.
    `badge`: a small chip after the title (e.g. Settings' "Admin Only").
--}}
@props(['title', 'subtitle' => null, 'count' => null, 'badge' => null, 'actions' => null, 'more' => null])

<div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
    <div>
        <h1 class="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900">
            <span>{{ $title }}</span>
            @if ($count !== null && $count !== '')
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{{ $count }}</span>
            @endif
            @if ($badge?->isNotEmpty())
                {{ $badge }}
            @endif
        </h1>
        @if ($subtitle)
            <p class="mt-1 text-xs text-slate-500">{{ $subtitle }}</p>
        @endif
    </div>

    @if ($actions?->isNotEmpty() || $more?->isNotEmpty())
        <div class="flex flex-wrap items-center gap-3">
            {{ $actions }}
            @if ($more?->isNotEmpty())
                <x-more-menu>{{ $more }}</x-more-menu>
            @endif
        </div>
    @endif
</div>
