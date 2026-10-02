{{--
    Short message at the top of the page after an action.
    Controllers: ->with('status', 'Lead saved') for success (fades out after a few seconds),
                 ->with('error', 'Could not ... Try ...') for problems (stays until dismissed).
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
             'mb-6 flex items-start justify-between gap-4 rounded-md px-4 py-3 text-sm',
             'bg-green-50 text-green-900' => $type === 'success',
             'bg-red-50 text-red-900' => $type === 'error',
         ])>
        <p>{{ $message }}</p>
        <button type="button" x-on:click="dismiss" class="shrink-0 rounded-sm font-medium underline-offset-2 hover:underline">
            Dismiss
        </button>
    </div>
@endforeach
