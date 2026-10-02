<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds security headers to every response (registered as global middleware).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generates a per-request nonce; @vite tags and our inline tags use it.
        Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy(Vite::cspNonce()));
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');

        if (app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Pages behind auth contain client data: keep them out of browser/proxy caches,
        // so "Back" after logout cannot show them.
        if ($request->user()) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $style = ["'self'", "'nonce-{$nonce}'"];
        $connect = ["'self'"];

        // Local development with `npm run dev`: allow the Vite dev server and its HMR websocket.
        if (! app()->isProduction() && Vite::isRunningHot()) {
            $hot = rtrim(file_get_contents(Vite::hotFile()));
            $script[] = $hot;
            $style[] = $hot;
            $connect[] = $hot;
            $connect[] = preg_replace('/^http/', 'ws', $hot);
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            "img-src 'self' data:",
            "font-src 'self'",
            'connect-src '.implode(' ', $connect),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
