<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must not depend on built frontend assets.
        $this->withoutVite();
        // Run defer() callbacks (e.g. password reset emails) immediately.
        $this->withoutDefer();
        // No real outbound HTTP from tests (e.g. the breached-password check).
        Http::preventStrayRequests();
    }
}
