{{-- Shown after a plain HTML form post when the integration has no thank-you page. Visitors of our
     sites see it, so it carries no CRM branding. No session here, so not the shared head partial. --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Thank you</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full bg-slate-50 font-sans text-slate-900">
    <main class="flex min-h-screen items-center justify-center px-4">
        <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-2xs">
            <x-lucide-circle-check class="mx-auto size-10 text-emerald-500" aria-hidden="true" />
            <h1 class="mt-3 text-base font-bold text-slate-900">Thank you</h1>
            <p class="mt-1 text-sm text-slate-600">Your details have been sent. We’ll be in touch soon.</p>
        </div>
    </main>
</body>
</html>
