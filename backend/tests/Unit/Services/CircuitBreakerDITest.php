<?php

namespace Tests\Unit\Services;

use App\Services\CircuitBreakerService;
use Tests\TestCase;

class CircuitBreakerDITest extends TestCase
{
    public function test_constructor_requires_metrics_dependency(): void
    {
        $reflection = new \ReflectionClass(CircuitBreakerService::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        $this->assertCount(1, $params, 'Constructor should have exactly one parameter');
        $param = $params[0];

        $this->assertSame('metrics', $param->getName());
        $this->assertFalse($param->allowsNull(), 'metrics must be non-nullable for Laravel DI auto-resolve');
        $this->assertFalse($param->isOptional(), 'metrics must be required (no default value)');
    }

    public function test_container_resolves_circuit_breaker_with_metrics(): void
    {
        $service = $this->app->make(CircuitBreakerService::class);

        $reflection = new \ReflectionClass($service);
        $metricsProperty = $reflection->getProperty('metrics');
        $metricsProperty->setAccessible(true);
        $metrics = $metricsProperty->getValue($service);

        $this->assertInstanceOf(\App\Services\ResilienceMetricsService::class, $metrics);
    }
}
