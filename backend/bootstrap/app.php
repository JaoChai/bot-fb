<?php

use App\Http\Middleware\AllowOptionsRequests;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidateJsonContent;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

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
        $middleware->prepend([
            TrustProxies::class,
            AllowOptionsRequests::class, // Must run before auth middleware to allow CORS preflight
            HandleCors::class,
            SecurityHeaders::class,
        ]);

        // API middleware group additions
        $middleware->api(prepend: [
            ValidateJsonContent::class,
            SanitizeInput::class,
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
        //
    })->create();
