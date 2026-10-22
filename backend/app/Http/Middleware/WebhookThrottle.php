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
use Throwable;

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
        $exceptionFromNext = false;
        $downstreamResponse = null;

        // The parent reads the limiter before AND after $next (the post-next
        // read only builds the X-RateLimit headers). A "reached next" flag
        // cannot tell an exception raised inside the downstream stack apart
        // from that post-next header read, so record whether an exception
        // actually escaped $next and keep the response it produced.
        $wrappedNext = function ($request) use ($next, &$exceptionFromNext, &$downstreamResponse) {
            try {
                return $downstreamResponse = $next($request);
            } catch (Throwable $e) {
                $exceptionFromNext = true;

                throw $e;
            }
        };

        try {
            // Forward the received arguments as-is: the parent's named-limiter
            // branch requires the middleware to be invoked with exactly three
            // arguments ('throttle.webhook' passes only the limiter name).
            return parent::handle($request, $wrappedNext, ...$options);
        } catch (PredisException|ConnectionException|RedisException $e) {
            // A Redis exception that escaped the downstream stack belongs to
            // the rest of the stack — rethrow it unchanged.
            if ($exceptionFromNext) {
                throw $e;
            }

            app(RedisHealthGate::class)->markDown();
            app(RedisFallbackSwitch::class)->apply();

            Log::warning('Redis blip on webhook throttle - failing open');

            // Before $next: pass the request through without counting it.
            // After $next the event is already dispatched and only the
            // header read failed, so return the response we captured.
            return $downstreamResponse ?? $next($request);
        }
    }
}
