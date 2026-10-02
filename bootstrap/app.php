<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so headers are also present on error pages and redirects.
        $middleware->append(SecurityHeaders::class);

        // Runs on every web request; logs out users deactivated mid-session.
        $middleware->web(append: [EnsureUserIsActive::class]);
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
