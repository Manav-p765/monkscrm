{{--
    Required for every destructive action (archive, delete, deactivate user, ...).
    The title must name the item.

    <x-confirm-dialog trigger="Archive lead" :action="route('leads.destroy', $lead)" method="DELETE"
                      title="Archive {{ $lead->reference }} ({{ $lead->fullName() }})?" confirm="Archive lead">
        The lead will be hidden from lists. An admin can restore it.
    </x-confirm-dialog>

    trigger-variant: button style for the opening button (danger | secondary | text).
--}}
@props(['trigger', 'title', 'action', 'confirm', 'method' => 'POST', 'triggerVariant' => 'danger', 'loading' => 'Working…'])

@php($dialogId = 'confirm-'.\Illuminate\Support\Str::random(8))

<div x-data="confirmDialog" {{ $attributes->class('inline-block') }}>
    <x-button type="button" :variant="$triggerVariant" x-on:click="open" aria-haspopup="dialog">{{ $trigger }}</x-button>

    <dialog x-ref="dialog" aria-labelledby="{{ $dialogId }}-title"
            class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg bg-white p-6 text-slate-900">
        <h2 id="{{ $dialogId }}-title" class="text-lg font-semibold">{{ $title }}</h2>
        @if ($slot->isNotEmpty())
            <div class="mt-2 text-slate-600">{{ $slot }}</div>
        @endif

        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <x-button type="button" variant="secondary" x-on:click="close" autofocus>Cancel</x-button>
            <form method="POST" action="{{ $action }}">
                @csrf
                @if (strtoupper($method) !== 'POST')
                    @method($method)
                @endif
                <x-button variant="danger" :loading="$loading">{{ $confirm }}</x-button>
            </form>
        </div>
    </dialog>
</div>
