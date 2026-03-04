<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateJsonContent
{
    /**
     * Maximum allowed content length (2MB).
     */
    protected int $maxContentLength = 2 * 1024 * 1024;

    /**
     * Maximum JSON depth allowed.
     */
    protected int $maxJsonDepth = 10;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check content length
        $contentLength = $request->header('Content-Length', 0);
        if ($contentLength > $this->maxContentLength) {
            return response()->json([
                'message' => 'Request body too large.',
                'max_size' => $this->formatBytes($this->maxContentLength),
            ], 413);
        }

        // Validate JSON for POST/PUT/PATCH with JSON content type
        if ($request->isJson() && in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            $content = $request->getContent();

            if (! empty($content)) {
                // Check JSON depth
                $decoded = json_decode($content, true, $this->maxJsonDepth);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $error = match (json_last_error()) {
                        JSON_ERROR_DEPTH => 'Maximum JSON depth exceeded.',
                        JSON_ERROR_SYNTAX => 'Invalid JSON syntax.',
                        JSON_ERROR_UTF8 => 'Invalid UTF-8 encoding in JSON.',
                        default => 'Invalid JSON format.',
                    };

                    return response()->json([
                        'message' => $error,
                    ], 400);
                }
            }
        }

        return $next($request);
    }

    /**
     * Format bytes to human-readable format.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(log($bytes) / log(1024));

        return round($bytes / (1024 ** $pow), 2).' '.$units[$pow];
    }
}
