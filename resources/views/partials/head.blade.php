<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex, nofollow">
{{-- Lets Vite's dev server tag injected <style> elements with the CSP nonce. --}}
<meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
<title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
