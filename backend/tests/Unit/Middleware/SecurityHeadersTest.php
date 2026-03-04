<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    private SecurityHeaders $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new SecurityHeaders;
    }

    public function test_adds_x_content_type_options_header(): void
    {
        $request = Request::create('/api/test', 'GET');
        $response = $this->middleware->handle($request, fn () => new Response('OK'));

        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_adds_x_frame_options_header(): void
    {
        $request = Request::create('/api/test', 'GET');
        $response = $this->middleware->handle($request, fn () => new Response('OK'));

        $this->assertEquals('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_adds_x_xss_protection_header(): void
    {
        $request = Request::create('/api/test', 'GET');
        $response = $this->middleware->handle($request, fn () => new Response('OK'));

        $this->assertEquals('1; mode=block', $response->headers->get('X-XSS-Protection'));
    }

    public function test_adds_referrer_policy_header(): void
    {
        $request = Request::create('/api/test', 'GET');
        $response = $this->middleware->handle($request, fn () => new Response('OK'));

        $this->assertEquals(
            'strict-origin-when-cross-origin',
            $response->headers->get('Referrer-Policy')
        );
    }

    public function test_adds_permissions_policy_header(): void
    {
        $request = Request::create('/api/test', 'GET');
        $response = $this->middleware->handle($request, fn () => new Response('OK'));

        $this->assertEquals(
            'camera=(), microphone=(), geolocation=()',
            $response->headers->get('Permissions-Policy')
        );
    }

    public function test_adds_csp_for_api_routes(): void
    {
        $request = Request::create('/api/test', 'GET');
        $response = $this->middleware->handle($request, fn () => new Response('OK'));

        $this->assertEquals(
            "default-src 'none'; frame-ancestors 'none'",
            $response->headers->get('Content-Security-Policy')
        );
    }

    public function test_does_not_add_csp_for_non_api_routes(): void
    {
        $request = Request::create('/webhook/test', 'POST');
        $response = $this->middleware->handle($request, fn () => new Response('OK'));

        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }
}
