{{--
    <x-select name="lead_status_id" label="Status" :options="[id => name, ...]" placeholder="Choose a status" required />
--}}
@props(['name', 'label', 'options' => [], 'value' => null, 'placeholder' => null, 'required' => false, 'hint' => null, 'bag' => 'default', 'id' => null])

@php
    $key = str_replace(['[]', '[', ']'], ['', '.', ''], $name);
    $id ??= str_replace('.', '_', $key);
    $error = $errors->getBag($bag)->first($key);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $current = (string) old($key, $value instanceof \BackedEnum ? $value->value : $value);
@endphp

<x-field :id="$id" :label="$label" :required="$required" :hint="$hint" :error="$error">
    <select id="{{ $id }}" name="{{ $name }}"
            @if ($required) required @endif
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class([
                'block w-full rounded-md border bg-white px-3 py-2 text-sm text-slate-900 focus:ring-1 focus:outline-none',
                'border-slate-300 focus:border-blue-600 focus:ring-blue-600' => ! $error,
                'border-red-600 focus:border-red-600 focus:ring-red-600' => $error,
            ]) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($current === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
</x-field>
