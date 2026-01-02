<?php

use App\Http\Middleware\CacheHeaders;
use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidateJsonContent;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global middleware applied to all requests
        // CacheHeaders FIRST in prepend = runs LAST on response (final word on cache headers)
        $middleware->prepend([
            CacheHeaders::class,
            TrustProxies::class,
            HandleCors::class,
            SecurityHeaders::class,
        ]);

        // API middleware group additions
        $middleware->api(prepend: [
            ValidateJsonContent::class,
            SanitizeInput::class,
        ]);

        // Compression runs after response is generated
        $middleware->api(append: [
            CompressResponse::class,
        ]);

        // Exclude webhook routes from CSRF protection
        $middleware->validateCsrfTokens(except: [
            'webhook/*',
        ]);

        // Define middleware aliases for route-level usage
        $middleware->alias([
            'throttle.auth' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':auth',
            'throttle.api' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            'throttle.webhook' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':webhook',
            'throttle.bot-test' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':bot-test',
            'throttle.uploads' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':uploads',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry error monitoring integration
        Integration::handles($exceptions);

        // Return JSON 401 for unauthenticated API requests instead of redirecting
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error' => 'Token expired or invalid. Please login again.',
                ], 401);
            }
        });
    })->create();
