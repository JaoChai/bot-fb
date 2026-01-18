<?php

use App\Exceptions\CircuitOpenException;
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
use Sentry\Breadcrumb;
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

        // Don't redirect to login - return null to throw AuthenticationException
        // which will be caught by our custom exception handler and return 401 JSON
        // This is an API-only application, no web login page exists
        $middleware->redirectGuestsTo(fn () => null);

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
            'throttle.qa-inspector-read' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':qa-inspector-read',
            'throttle.qa-inspector-write' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':qa-inspector-write',
            'throttle.qa-report-generate' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':qa-report-generate',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry error monitoring integration
        Integration::handles($exceptions);

        // Add breadcrumb for CircuitOpenException to help debug cascading failures
        $exceptions->report(function (CircuitOpenException $e): void {
            if (app()->bound('sentry')) {
                \Sentry\addBreadcrumb(
                    new Breadcrumb(
                        level: Breadcrumb::LEVEL_WARNING,
                        type: 'default',
                        category: 'circuit_breaker',
                        message: "Circuit open for service: {$e->getService()}",
                        metadata: [
                            'service' => $e->getService(),
                            'exception_message' => $e->getMessage(),
                        ],
                        timestamp: time()
                    )
                );
            }
        });

        // Return JSON 401 for unauthenticated API requests instead of redirecting
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error' => 'Token expired or invalid. Please login again.',
                ], 401);
            }
        });

        // Return JSON 503 for CircuitOpenException (Service Unavailable)
        $exceptions->render(function (CircuitOpenException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Service temporarily unavailable.',
                    'error' => 'The service is experiencing issues. Please try again later.',
                    'service' => $e->getService(),
                ], 503);
            }
        });
    })->create();
