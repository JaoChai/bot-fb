<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to compress API responses using gzip.
 *
 * Benefits:
 * - Reduces bandwidth by 60-80% for JSON responses
 * - Faster load times for users with slow connections
 *
 * Conditions:
 * - Only compresses if client accepts gzip (Accept-Encoding header)
 * - Only compresses text-based content types (JSON, HTML, etc.)
 * - Skips responses smaller than 1KB (compression overhead not worth it)
 */
class CompressResponse
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Skip if response already has encoding
        if ($response->headers->has('Content-Encoding')) {
            return $response;
        }

        // Check if client accepts gzip
        if (! str_contains($request->header('Accept-Encoding', ''), 'gzip')) {
            return $response;
        }

        // Only compress text-based responses
        $contentType = $response->headers->get('Content-Type', '');
        if (! $this->isCompressible($contentType)) {
            return $response;
        }

        // Get content
        $content = $response->getContent();

        // Skip if content is empty or too small
        if ($content === false || strlen($content) < 1024) {
            return $response;
        }

        // Compress with gzip level 6 (good balance of speed vs compression)
        $compressed = gzencode($content, 6);

        if ($compressed !== false) {
            $response->setContent($compressed);
            $response->headers->set('Content-Encoding', 'gzip');
            $response->headers->set('Content-Length', (string) strlen($compressed));
            $response->headers->set('Vary', 'Accept-Encoding');
        }

        return $response;
    }

    /**
     * Check if the content type is compressible.
     */
    private function isCompressible(string $contentType): bool
    {
        $compressibleTypes = [
            'application/json',
            'text/html',
            'text/plain',
            'text/css',
            'text/javascript',
            'application/javascript',
            'application/xml',
            'text/xml',
        ];

        foreach ($compressibleTypes as $type) {
            if (str_contains($contentType, $type)) {
                return true;
            }
        }

        return false;
    }
}
