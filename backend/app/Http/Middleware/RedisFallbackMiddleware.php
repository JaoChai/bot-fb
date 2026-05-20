<?php

namespace App\Http\Middleware;

use App\Services\RedisHealthGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RedisFallbackMiddleware
{
    public function __construct(private RedisHealthGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('cache.default') === 'redis' && ! $this->gate->isRedisUp()) {
            config([
                'cache.default' => 'database',
                'session.driver' => 'database',
            ]);

            Log::warning('Redis unavailable — falling back to database for cache/session');
        }

        return $next($request);
    }
}
