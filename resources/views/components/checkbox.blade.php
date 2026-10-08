{{--
    Prototype checkbox with its label to the right (Settings "Allow Standard Users to view…").
    <x-checkbox name="is_active" label="Enabled" :checked="$status->is_active" hint="…" />
    Sends value "1" when ticked (pair with a hidden "0" input when the field must always be sent).
--}}
@props(['name', 'label', 'checked' => false, 'value' => '1', 'hint' => null, 'bag' => 'default', 'id' => null])

@php
    $key = str_replace(['[]', '[', ']'], ['', '.', ''], $name);
    $id ??= str_replace(['.', '[', ']'], '_', $key).(str_ends_with($name, '[]') ? '_'.$value : '');
    $error = $errors->getBag($bag)->first($key);
    $old = old($key);
    $isChecked = $old !== null ? (is_array($old) ? in_array($value, $old, true) : (bool) $old) : (bool) $checked;
@endphp

<div>
    <label for="{{ $id }}" class="flex cursor-pointer items-start gap-3">
        <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @checked($isChecked)
               @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
               {{ $attributes->class('mt-0.5 size-4 shrink-0 rounded border-slate-300 text-indigo-600 accent-indigo-600') }}>
        <span>
            <span class="block text-xs font-semibold text-slate-800">{{ $label }}</span>
            @if ($hint)
                <span class="block text-2xs text-slate-500">{{ $hint }}</span>
            @endif
        </span>
    </label>
    @if ($error)
        <p id="{{ $id }}-error" class="mt-1 flex items-start gap-1 text-2xs font-medium text-rose-700">
            <x-lucide-alert-circle class="mt-px size-3 shrink-0" aria-hidden="true" />
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
