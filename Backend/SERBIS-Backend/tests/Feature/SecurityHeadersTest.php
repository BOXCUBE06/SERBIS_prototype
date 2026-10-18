<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The hardening headers on every kind of answer, and none of the PHP version. */
class SecurityHeadersTest extends TestCase
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    private function assertHardened($response): void
    {
        foreach (self::HEADERS as $name => $value) {
            $response->assertHeader($name, $value);
        }

        $this->assertFalse($response->headers->has('X-Powered-By'));
    }

    public function test_an_api_answer_carries_every_header(): void
    {
        $response = $this->getJson('https://localhost/api/me');

        $response->assertUnauthorized();
        $this->assertHardened($response);
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->getJson('http://localhost/api/me')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_an_unmatched_route_and_a_preflight_are_covered(): void
    {
        $this->assertHardened($this->getJson('/api/no-such-route'));

        $this->assertHardened($this->call('OPTIONS', '/api/barangays', server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]));
    }

    public function test_the_health_page_keeps_its_html_and_gets_no_csp(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $this->assertHardened($response);
        $response->assertHeaderMissing('Content-Security-Policy');
    }
}
