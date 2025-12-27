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
            // Get allowed origins from environment variable
            $allowedOrigins = explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:3000'));
            $origin = $request->header('Origin');
            $allowOrigin = in_array($origin, array_map('trim', $allowedOrigins)) ? $origin : (trim($allowedOrigins[0]) ?? '*');

            return response('', 200)
                ->header('Access-Control-Allow-Origin', $allowOrigin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
                ->header('Access-Control-Max-Age', '3600')
                ->header('Access-Control-Allow-Credentials', 'true');
        }

        return $next($request);
    }
}
