{{-- Self-contained on purpose: no Vite, no database, no user data, never any exception details. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('code') · {{ config('app.name') }}</title>
    <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
               background: #f8fafc; color: #0f172a; padding: 1rem; }
        main { max-width: 28rem; text-align: center; }
        .code { font-size: .875rem; font-weight: 600; color: #475569; letter-spacing: .05em; }
        h1 { font-size: 1.5rem; margin: .5rem 0; }
        p { color: #475569; line-height: 1.5; }
        a { display: inline-block; margin-top: 1rem; color: #1d4ed8; font-weight: 500; }
        a:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <p class="code">Error @yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a href="{{ url('/') }}">Go to the dashboard</a>
    </main>
</body>
</html>
