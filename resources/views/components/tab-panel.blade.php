{{-- One panel of an in-page <x-tabs>. Hidden unless its tab is selected. --}}
@props(['name', 'active' => false])

<div role="tabpanel" id="panel-{{ $name }}" aria-labelledby="tab-{{ $name }}" tabindex="0"
     x-data="tabPanel" data-tab="{{ $name }}" x-show="visible" @unless ($active) x-cloak @endunless {{ $attributes }}>
    {{ $slot }}
</div>
