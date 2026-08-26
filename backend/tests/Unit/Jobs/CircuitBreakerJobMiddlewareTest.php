<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Middleware\CircuitBreakerJobMiddleware;
use App\Services\CircuitBreakerService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CircuitBreakerJobMiddlewareTest extends TestCase
{
    public function test_calls_next_when_circuit_closed(): void
    {
        $cb = Mockery::mock(CircuitBreakerService::class);
        $cb->shouldReceive('execute')
            ->once()
            ->with('database', Mockery::on(fn ($op) => is_callable($op)), null)
            ->andReturnUsing(function ($service, $operation, $fallback) {
                return $operation();
            });

        $job = new FakeWebhookJob;
        $middleware = new CircuitBreakerJobMiddleware($cb);

        $middleware->handle($job, function ($j) {
            $j->ran = true;
        });

        $this->assertTrue($job->ran);
    }

    public function test_sends_fallback_on_circuit_open(): void
    {
        Log::shouldReceive('warning')->once()->with(
            'Webhook circuit breaker open',
            Mockery::on(fn ($ctx) => isset($ctx['service']) && $ctx['bot_id'] === 1)
        );

        $cb = Mockery::mock(CircuitBreakerService::class);
        $cb->shouldReceive('execute')->once()->andThrow(new \App\Exceptions\CircuitOpenException('database'));

        $job = new FakeWebhookJob;
        $middleware = new CircuitBreakerJobMiddleware($cb);
        $middleware->handle($job, fn () => null);

        $this->assertTrue($job->fallbackCalled);
    }
}

/**
 * Minimal stand-in for a webhook job. Exposes the surface the middleware
 * relies on: a public circuitFallback() and $bot->id.
 */
class FakeWebhookJob
{
    public $bot;
    public bool $ran = false;
    public bool $fallbackCalled = false;

    public function __construct()
    {
        $this->bot = (object) ['id' => 1];
    }

    public function circuitFallback(): void
    {
        $this->fallbackCalled = true;
    }
}
