<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertSecurityHeaders($response): void
    {
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+'/", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_headers_present_on_guest_pages(): void
    {
        $this->assertSecurityHeaders($this->get('/login'));
    }

    public function test_headers_present_on_authenticated_pages_and_no_caching(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $this->assertSecurityHeaders($response);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_headers_present_on_redirects_and_error_pages(): void
    {
        $this->assertSecurityHeaders($this->get('/dashboard'));
        $this->assertSecurityHeaders($this->get('/does-not-exist'));
    }

    public function test_csp_nonce_changes_per_request(): void
    {
        $first = $this->get('/login')->headers->get('Content-Security-Policy');
        $second = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }

    public function test_hsts_is_not_sent_outside_production(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_robots_txt_disallows_everything(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertMatchesRegularExpression('/^User-agent: \*$/m', $robots);
        $this->assertMatchesRegularExpression('/^Disallow: \/$/m', $robots);
    }
}
