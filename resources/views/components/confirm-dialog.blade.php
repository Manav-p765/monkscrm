{{--
    Required for every destructive action (archive, delete, deactivate user, …). The title names the item.

    <x-confirm-dialog trigger="Archive Lead" :action="route('leads.destroy', $lead)" method="DELETE"
                      title="Archive {{ $lead->reference }} ({{ $lead->fullName() }})?" confirm="Archive Lead">
        The lead will be hidden from lists. An admin can restore it.
    </x-confirm-dialog>

    trigger-variant: button style for the opening button (danger | danger-soft | secondary | ghost | text).
    trigger-icon:    optional Lucide icon for the opening button (icon-only when trigger-label-hidden).
--}}
@props(['trigger', 'title', 'action', 'confirm', 'method' => 'POST', 'triggerVariant' => 'danger-soft', 'triggerSize' => 'md',
    'triggerIcon' => null, 'loading' => 'Working…'])

@php($dialogId = 'confirm-'.\Illuminate\Support\Str::random(8))

<div x-data="confirmDialog" {{ $attributes->class('inline-block') }}>
    <x-button type="button" :variant="$triggerVariant" :size="$triggerSize" :icon="$triggerIcon" x-on:click="open" aria-haspopup="dialog">{{ $trigger }}</x-button>

    <dialog x-ref="dialog" aria-labelledby="{{ $dialogId }}-title"
            class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl bg-white p-6 text-left text-slate-900 shadow-2xl">
        <div class="flex items-start gap-3">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-600">
                <x-lucide-alert-triangle class="size-4.5" aria-hidden="true" />
            </div>
            <div class="min-w-0">
                <h2 id="{{ $dialogId }}-title" class="text-sm font-bold text-slate-900">{{ $title }}</h2>
                @if ($slot->isNotEmpty())
                    <div class="mt-1 text-xs leading-relaxed text-slate-600">{{ $slot }}</div>
                @endif
            </div>
        </div>

        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <x-button type="button" variant="ghost" x-on:click="close" autofocus>Cancel</x-button>
            <form method="POST" action="{{ $action }}">
                @csrf
                @if (strtoupper($method) !== 'POST')
                    @method($method)
                @endif
                {{ $fields ?? '' }}
                <x-button variant="danger" :loading="$loading">{{ $confirm }}</x-button>
            </form>
        </div>
    </dialog>
</div>
