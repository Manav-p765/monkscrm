{{--
    Prototype banner (Kanban "Moved X to stage Y", sign-in error box). Rendered by the layouts.
    Controllers: ->with('status', 'Lead saved') for success (fades out after a few seconds),
                 ->with('error', 'Could not … Try …') for problems (stays until dismissed).
    Fortify's status keys (e.g. "password-updated") are translated via lang/en.json.
--}}
@php
    $messages = array_filter([
        'success' => session('status') ? __(session('status')) : null,
        'error' => session('error'),
    ]);
@endphp

@foreach ($messages as $type => $message)
    <div x-data="flash" x-show="visible" x-transition.opacity.duration.300ms
         data-auto-dismiss="{{ $type === 'success' ? 'true' : 'false' }}"
         role="{{ $type === 'error' ? 'alert' : 'status' }}"
         @class([
             'flex items-center justify-between gap-3 rounded-xl border p-2.5 px-4 text-xs font-semibold shadow-xs',
             'border-emerald-200 bg-emerald-50 text-emerald-800' => $type === 'success',
             'border-rose-200 bg-rose-50 text-rose-700' => $type === 'error',
         ])>
        <span class="flex items-center gap-2">
            @if ($type === 'success')
                <x-lucide-check-circle-2 class="size-4 shrink-0 text-emerald-600" aria-hidden="true" />
            @else
                <x-lucide-alert-triangle class="size-4 shrink-0 text-rose-600" aria-hidden="true" />
            @endif
            <span>{{ $message }}</span>
        </span>
        <button type="button" x-on:click="dismiss" class="shrink-0 rounded p-0.5 opacity-70 hover:opacity-100">
            <span class="sr-only">Dismiss</span>
            <x-lucide-x class="size-3.5" aria-hidden="true" />
        </button>
    </div>
@endforeach
