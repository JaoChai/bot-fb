<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allow OPTIONS (preflight) requests to pass through without authentication.
 * This is necessary for CORS preflight requests to work properly.
 */
class AllowOptionsRequests
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Allow OPTIONS requests without authentication
        // CORS preflight requests use OPTIONS method
        if ($request->isMethod('OPTIONS')) {
            try {
                // Get allowed origins from environment variable
                $corsEnv = env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:3000');
                $allowedOrigins = array_map('trim', explode(',', $corsEnv));

                // Get request origin header
                $origin = $request->header('Origin', '');

                // Check if origin is allowed
                $isAllowed = !empty($origin) && in_array($origin, $allowedOrigins, true);
                $allowOrigin = $isAllowed ? $origin : (reset($allowedOrigins) ?: '*');

                return response('', 200)
                    ->header('Access-Control-Allow-Origin', $allowOrigin)
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
                    ->header('Access-Control-Max-Age', '3600')
                    ->header('Access-Control-Allow-Credentials', 'true');
            } catch (\Exception $e) {
                // If something goes wrong, allow the request to continue
                // Better to let it fail in the app than crash the middleware
                return response('', 200)
                    ->header('Access-Control-Allow-Origin', '*')
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            }
        }

        return $next($request);
    }
}
