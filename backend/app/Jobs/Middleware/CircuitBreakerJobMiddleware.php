<?php

namespace App\Jobs\Middleware;

use App\Exceptions\CircuitOpenException;
use App\Services\CircuitBreakerService;
use Closure;
use Illuminate\Support\Facades\Log;

class CircuitBreakerJobMiddleware
{
    public function __construct(protected CircuitBreakerService $circuitBreaker) {}

    /**
     * Wrap the job pipeline in circuit-breaker protection.
     *
     * When the circuit is open the exception propagates out of execute() and
     * is handled here: log a warning and run the job's channel-specific
     * fallback (DB-independent).
     */
    public function handle(object $job, Closure $next): void
    {
        try {
            $this->circuitBreaker->execute('database', fn () => $next($job), null);
        } catch (CircuitOpenException $e) {
            Log::warning('Webhook circuit breaker open', [
                'bot_id' => $job->bot->id ?? null,
                'service' => $e->getService(),
            ]);
            $job->circuitFallback();
        }
    }
}
