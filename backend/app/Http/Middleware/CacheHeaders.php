<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Middleware to add Cache-Control headers to API responses.
 *
 * Reduces redundant API calls by allowing browsers to cache
 * GET responses for appropriate durations based on data volatility.
 */
class CacheHeaders
{
    /**
     * Cache rules: route pattern => max-age in seconds.
     * 0 = no-store (never cache)
     * Note: paths INCLUDE the api/ prefix (from $request->path())
     */
    private array $cacheRules = [
        // Mutation-sensitive — ห้ามแคช (React Query staleTime คุม freshness แทน)
        'api/bots' => 0,
        'api/bots/*' => 0,
        'api/flows' => 0,
        'api/flows/*' => 0,
        'api/knowledge-bases' => 0,
        'api/knowledge-bases/*' => 0,

        // Read-only static data — แคชได้นาน
        'api/evaluation-personas' => 3600, // 1 hour - rarely changes

        // User-specific data — แคชสั้น (ไม่มี mutation ที่ต้อง invalidate)
        'api/auth/user' => 60,
        'api/dashboard/summary' => 60,
        'api/settings' => 60,
        'api/analytics/*' => 60,

        // Real-time data — ไม่แคช
        'api/conversations' => 0,
        'api/conversations/*' => 0,
        'api/webhook/*' => 0,
        'api/health' => 0,
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only apply to API routes
        if (! $request->is('api/*')) {
            return $response;
        }

        // Skip non-GET requests (mutations should never be cached)
        if (! $request->isMethod('GET')) {
            return $this->noCache($response);
        }

        // Skip streaming responses
        if ($response instanceof StreamedResponse) {
            return $response;
        }

        // Skip error responses
        if ($response->getStatusCode() >= 400) {
            return $this->noCache($response);
        }

        // Determine cache duration based on route
        $maxAge = $this->getCacheDuration($request);

        if ($maxAge > 0) {
            return $this->withCache($response, $maxAge);
        }

        return $this->noCache($response);
    }

    /**
     * Get cache duration for the current route.
     */
    private function getCacheDuration(Request $request): int
    {
        $path = $request->path();

        // Check exact matches first
        if (isset($this->cacheRules[$path])) {
            return $this->cacheRules[$path];
        }

        // Check wildcard patterns
        foreach ($this->cacheRules as $pattern => $duration) {
            if (str_contains($pattern, '*')) {
                $regex = '/^'.str_replace(['*', '/'], ['.*', '\/'], $pattern).'$/';
                if (preg_match($regex, $path)) {
                    return $duration;
                }
            }
        }

        // Default: no cache for unknown routes
        return 0;
    }

    /**
     * Add cache headers to response.
     */
    private function withCache(Response $response, int $maxAge): Response
    {
        // Remove any existing cache headers set by Laravel
        $response->headers->remove('Cache-Control');
        $response->headers->remove('Pragma');
        $response->headers->remove('Expires');

        // Set cache control with private (user-specific) and max-age
        $response->headers->set('Cache-Control', "private, max-age={$maxAge}", true);
        $response->headers->set('Vary', 'Accept, Authorization');

        return $response;
    }

    /**
     * Add no-cache headers to response.
     */
    private function noCache(Response $response): Response
    {
        // Remove any existing cache headers
        $response->headers->remove('Cache-Control');
        $response->headers->remove('Pragma');
        $response->headers->remove('Expires');

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
