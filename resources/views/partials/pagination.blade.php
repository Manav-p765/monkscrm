{{-- Page links in the prototype's style. Used by <x-table> via ->links('partials.pagination'). --}}
@if ($paginator->hasPages())
    <nav aria-label="Pages" class="flex items-center gap-1">
        @if ($paginator->onFirstPage())
            <span class="rounded-md px-2 py-1 text-slate-400" aria-disabled="true">Previous</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="rounded-md px-2 py-1 font-semibold text-slate-600 hover:bg-white hover:text-slate-900">Previous</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="px-1.5 text-slate-500">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span aria-current="page" class="min-w-6 rounded-md bg-slate-900 px-2 py-1 text-center font-semibold text-white">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="min-w-6 rounded-md px-2 py-1 text-center font-medium text-slate-600 hover:bg-white hover:text-slate-900">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="rounded-md px-2 py-1 font-semibold text-slate-600 hover:bg-white hover:text-slate-900">Next</a>
        @else
            <span class="rounded-md px-2 py-1 text-slate-400" aria-disabled="true">Next</span>
        @endif
    </nav>
@endif
