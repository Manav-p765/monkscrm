{{-- Brand logo (public/images/logo.png). Size and corner radius come from the caller: <x-logo class="size-7 rounded-lg" />.
     Decorative: the product name is always shown next to it. --}}
<img src="{{ asset(config('crm.brand_images').'/logo.png') }}" alt="" aria-hidden="true" width="192" height="192" {{ $attributes->class('shrink-0 object-cover') }}>
