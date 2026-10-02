{{--
    Internal wrapper shared by <x-input>, <x-select> and <x-textarea>:
    label above the field, "Required" marker, hint, and the error next to the field.
    Not for direct use on pages.
--}}
@props(['id', 'label', 'required' => false, 'hint' => null, 'error' => null])

<div>
    <label for="{{ $id }}" class="mb-1.5 flex items-baseline gap-2 text-sm font-medium text-slate-800">
        {{ $label }}
        @if ($required)
            <span class="text-xs font-normal text-slate-500">Required</span>
        @endif
    </label>
    {{ $slot }}
    @if ($hint)
        <p id="{{ $id }}-hint" class="mt-1.5 text-xs text-slate-500">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="mt-1.5 text-sm text-red-700">{{ $error }}</p>
    @endif
</div>
