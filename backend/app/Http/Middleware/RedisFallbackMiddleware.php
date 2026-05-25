<?php

namespace App\Http\Middleware;

use App\Services\RedisFallbackSwitch;
use App\Services\RedisHealthGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedisFallbackMiddleware
{
    public function __construct(private RedisHealthGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        (new RedisFallbackSwitch($this->gate))->apply();

        return $next($request);
    }
}
