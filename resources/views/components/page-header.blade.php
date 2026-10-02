{{--
    Title top-left; at most ONE primary action top-right; secondary actions in a single "More" menu.
    Rendered by the app layout from its `title`, `actions` and `more` slots. Pages don't use it directly.
--}}
@props(['title', 'actions' => null, 'more' => null])

<div class="mb-8 flex flex-wrap items-center justify-between gap-4">
    <h1 class="text-2xl font-semibold text-slate-900">{{ $title }}</h1>

    @if ($actions?->isNotEmpty() || $more?->isNotEmpty())
        <div class="flex items-center gap-3">
            @if ($more?->isNotEmpty())
                <x-more-menu>{{ $more }}</x-more-menu>
            @endif
            {{ $actions }}
        </div>
    @endif
</div>
