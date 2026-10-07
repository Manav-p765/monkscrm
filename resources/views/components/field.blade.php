{{--
    Internal wrapper shared by <x-input>, <x-select> and <x-textarea>: prototype label above the field
    ("First Name *"), optional aside next to the label (e.g. "Forgot password?"), hint, and the error
    shown right under the field in plain language. Not for direct use on pages.
    label-hidden: keeps the <label> for screen readers only (filter bars, where the prototype shows none).
--}}
@props(['id', 'label', 'required' => false, 'hint' => null, 'error' => null, 'aside' => null, 'labelHidden' => false])

<div>
    @if ($labelHidden)
        <label for="{{ $id }}" class="sr-only">{{ $label }}</label>
    @else
        <div class="mb-1 flex items-center justify-between gap-2">
            <label for="{{ $id }}" class="block text-xs font-semibold text-slate-700">
                {{ $label }}@if ($required)<span aria-hidden="true"> *</span><span class="sr-only"> (required)</span>@endif
            </label>
            @if ($aside)
                {{ $aside }}
            @endif
        </div>
    @endif
    {{ $slot }}
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-2xs text-slate-500">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="mt-1 flex items-start gap-1 text-2xs font-medium text-rose-700">
            <x-lucide-alert-circle class="mt-px size-3 shrink-0" aria-hidden="true" />
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
