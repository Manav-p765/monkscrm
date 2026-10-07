{{--
    <x-select name="lead_status_id" label="Lead Status" :options="[id => name, ...]" placeholder="Unassigned" required />
--}}
@props(['name', 'label', 'options' => [], 'value' => null, 'placeholder' => null, 'required' => false, 'hint' => null,
    'bag' => 'default', 'id' => null, 'size' => 'md', 'aside' => null])

@php
    $key = str_replace(['[]', '[', ']'], ['', '.', ''], $name);
    $id ??= str_replace('.', '_', $key);
    $error = $errors->getBag($bag)->first($key);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $current = (string) old($key, $value instanceof \BackedEnum ? $value->value : $value);
@endphp

<x-field :id="$id" :label="$label" :required="$required" :hint="$hint" :error="$error" :aside="$aside">
    <select id="{{ $id }}" name="{{ $name }}"
            @if ($required) required @endif
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class(\App\View\FormStyles::control($size, (bool) $error)) }}>
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($current === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
</x-field>
