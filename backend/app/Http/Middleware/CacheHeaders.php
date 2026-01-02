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
     * Note: paths are WITHOUT the api/ prefix (Laravel strips it)
     */
    private array $cacheRules = [
        // Bot list - no cache (status changes frequently via toggles)
        'bots' => 0,
        // Bot detail - cache briefly (user viewing details)
        'bots/*' => 60,
        // Static/stable data - cache 5 minutes
        'flows' => 300,
        'flows/*' => 300,
        'knowledge-bases' => 300,
        'knowledge-bases/*' => 300,
        'evaluation-personas' => 3600, // 1 hour - rarely changes

        // User-specific data - cache 1 minute
        'auth/user' => 60,
        'dashboard/summary' => 60,
        'settings' => 60,
        'analytics/*' => 60,

        // Real-time data - never cache
        'conversations' => 0,
        'conversations/*' => 0,
        'webhook/*' => 0,
        'health' => 0,
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

        $path = $request->path();

        // Skip non-GET requests (mutations should never be cached)
        if (! $request->isMethod('GET')) {
            return $this->noCache($response, $path);
        }

        // Skip streaming responses
        if ($response instanceof StreamedResponse) {
            return $response;
        }

        // Skip error responses
        if ($response->getStatusCode() >= 400) {
            return $this->noCache($response, $path);
        }

        // Determine cache duration based on route
        $maxAge = $this->getCacheDuration($request);

        if ($maxAge > 0) {
            return $this->withCache($response, $maxAge, $path);
        }

        return $this->noCache($response, $path);
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
    private function withCache(Response $response, int $maxAge, string $path = ''): Response
    {
        // Debug: mark that this middleware ran
        $response->headers->set('X-Cache-Strategy', "max-age-{$maxAge}");
        $response->headers->set('X-Cache-Path', $path);

        // Remove any existing cache headers set by Laravel
        $response->headers->remove('Cache-Control');
        $response->headers->remove('Pragma');
        $response->headers->remove('Expires');

        // Force set cache control with replace=true
        $response->headers->set('Cache-Control', "private, max-age={$maxAge}", true);
        $response->headers->set('Vary', 'Accept, Authorization');

        return $response;
    }

    /**
     * Add no-cache headers to response.
     */
    private function noCache(Response $response, string $path = ''): Response
    {
        // Debug: mark that this middleware ran
        $response->headers->set('X-Cache-Strategy', 'no-cache');
        $response->headers->set('X-Cache-Path', $path);

        // Remove any existing cache headers
        $response->headers->remove('Cache-Control');
        $response->headers->remove('Pragma');
        $response->headers->remove('Expires');

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
