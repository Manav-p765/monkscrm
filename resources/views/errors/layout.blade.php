{{-- Self-contained on purpose: no Vite, no database, no user data, never any exception details.
     Styled like the prototype's cards (slate-100 canvas, white rounded-2xl card, indigo accent). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('code') · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('images/favicon-32.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
               background: #f1f5f9; color: #0f172a; padding: 1rem; }
        main { max-width: 28rem; width: 100%; text-align: center; background: #fff; border: 1px solid #e2e8f0;
               border-radius: 1rem; padding: 2rem 1.5rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); }
        .tile { display: block; margin: 0 auto; width: 3rem; height: 3rem; border-radius: .75rem; }
        .code { margin-top: 1rem; font-size: .625rem; font-weight: 700; color: #64748b; letter-spacing: .05em; text-transform: uppercase; }
        h1 { font-size: 1.25rem; font-weight: 700; margin: .5rem 0; letter-spacing: -0.01em; }
        p { color: #475569; line-height: 1.5; font-size: .75rem; margin: 0; }
        a { display: inline-block; margin-top: 1.25rem; padding: .5rem .875rem; border-radius: .5rem; background: #4f46e5;
            color: #fff; font-weight: 600; font-size: .75rem; text-decoration: none; }
        a:hover { background: #4338ca; }
        a:focus-visible { outline: 2px solid #4f46e5; outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <img class="tile" src="{{ asset('images/logo.png') }}" alt="" aria-hidden="true" width="192" height="192">
        <p class="code">Error @yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a href="{{ url('/') }}">Go to the Dashboard</a>
    </main>
</body>
</html>
