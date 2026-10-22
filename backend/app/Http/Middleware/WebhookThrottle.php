<?php

namespace App\Http\Middleware;

use App\Services\RedisFallbackSwitch;
use App\Services\RedisHealthGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Predis\Connection\ConnectionException;
use Predis\PredisException;
use RedisException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook rate limiter that fails open on a short Redis connection failure.
 *
 * The plain ThrottleRequests 500s the webhook when the limiter's cache read
 * hits dead Redis (2026-10-22 incident: the platform answered 499 after LINE
 * closed the connection). Here a Redis blip during the limiter check marks
 * the health gate down, applies the cache/session fallback, logs once, and
 * passes the request through without counting it — the event is not lost.
 * Any other exception is rethrown unchanged, and a real 429 still returns 429.
 */
class WebhookThrottle extends ThrottleRequests
{
    public function handle($request, Closure $next, ...$options): Response
    {
        $reachedNext = false;

        try {
            // Forward the received arguments as-is: the parent's named-limiter
            // branch requires the middleware to be invoked with exactly three
            // arguments ('throttle.webhook' passes only the limiter name).
            return parent::handle($request, function ($request) use ($next, &$reachedNext) {
                $reachedNext = true;

                return $next($request);
            }, ...$options);
        } catch (PredisException|ConnectionException|RedisException $e) {
            // Exceptions thrown after the request passed the limiter check
            // belong to the rest of the stack — rethrow them unchanged.
            if ($reachedNext) {
                throw $e;
            }

            app(RedisHealthGate::class)->markDown();
            app(RedisFallbackSwitch::class)->apply();

            Log::warning('Redis blip on webhook throttle - failing open');

            return $next($request);
        }
    }
}
