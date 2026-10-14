<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class HeadersTest extends TestCase
{
    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Strict-Transport-Security'), 'HSTS só em HTTPS');
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security');
    }

    public function test_api_responses_carry_security_headers_too(): void
    {
        $this->getJson('/api/agent/v1/meta')->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
