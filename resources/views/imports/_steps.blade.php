{{-- Prototype step progress bar (Upload → … → Preview & Import). $current: step key. --}}
@php
    $keys = array_keys(\App\Http\Controllers\ImportController::STEPS);
    $currentIndex = array_search($current, $keys, true);
@endphp
<div class="overflow-x-auto border-b border-slate-200 bg-slate-50/70 p-4">
    <ol class="flex min-w-[650px] items-center justify-between gap-2">
        @foreach (\App\Http\Controllers\ImportController::STEPS as $key => $label)
            @php $i = $loop->index; $past = $i < $currentIndex; $now = $i === $currentIndex; @endphp
            <li class="flex flex-1 items-center gap-2" @if ($now) aria-current="step" @endif>
                <span @class(['flex size-6 items-center justify-center rounded-full text-xs font-bold transition-all',
                    'bg-emerald-600 text-white' => $past, 'bg-indigo-600 text-white ring-2 ring-indigo-200' => $now, 'bg-slate-200 text-slate-500' => ! $past && ! $now])>
                    @if ($past)<x-lucide-check class="size-3.25" aria-hidden="true" />@else{{ $i + 1 }}@endif
                </span>
                <span @class(['text-xs whitespace-nowrap', 'font-bold text-slate-900' => $now, 'font-medium text-slate-700' => $past, 'text-slate-500' => ! $past && ! $now])>{{ $label }}</span>
                @if (! $loop->last)<span @class(['ml-1 h-0.5 flex-1', 'bg-emerald-500' => $past, 'bg-slate-200' => ! $past])></span>@endif
            </li>
        @endforeach
    </ol>
</div>
