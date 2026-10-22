<?php

use App\Http\Middleware\BlockInDemo;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireTwoFactorForAdmins;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Render's health check (and uptime monitoring). Outside the web group on purpose: no session,
        // cookies, CSRF or database, so it is cheap and says only "the process is serving requests".
        then: function () {
            Route::get('/healthz', fn () => response('ok', 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]))->name('healthz');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so headers are also present on error pages and redirects.
        $middleware->append(SecurityHeaders::class);

        // Runs on every web request; logs out users deactivated mid-session, then holds admins
        // without two-step sign-in on My Profile until they set it up.
        $middleware->web(append: [EnsureUserIsActive::class, RequireTwoFactorForAdmins::class, BlockInDemo::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'code',
            'recovery_code',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson(),
        );
    })->create();
