<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary debugging middleware to catch auth:sanctum exceptions.
 * REMOVE THIS AFTER DEBUGGING!
 */
class DebugAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Call the sanctum authentication
            $authMiddleware = app(\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class);

            return $authMiddleware->handle($request, function ($request) use ($next) {
                // After sanctum, check if authenticated
                $user = $request->user('sanctum');

                if (!$user) {
                    return response()->json([
                        'debug' => 'Auth failed - no user after sanctum',
                        'message' => 'Unauthenticated.',
                    ], 401);
                }

                return $next($request);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'debug' => 'Exception in auth middleware',
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(10)->toArray(),
            ], 500);
        }
    }
}
