<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Security headers to apply to all responses.
     */
    protected array $headers = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        // Add HSTS header for HTTPS connections
        if ($request->secure() || config('app.env') === 'production') {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        // Add Content-Security-Policy for API responses
        if ($request->is('api/*')) {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'"
            );
        }

        return $response;
    }
}
