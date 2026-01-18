<?php

namespace App\Services;

use App\Exceptions\CircuitOpenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CircuitBreakerService
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    protected string $cachePrefix;
    protected bool $enabled;
    protected ?ResilienceMetricsService $metrics;

    public function __construct(?ResilienceMetricsService $metrics = null)
    {
        $this->cachePrefix = config('circuit-breaker.cache_prefix', 'circuit_breaker');
        $this->enabled = config('circuit-breaker.enabled', true);
        $this->metrics = $metrics;
    }

    /**
     * Execute an operation with circuit breaker protection.
     *
     * @param string $service Service identifier (e.g., 'database', 'cache')
     * @param callable $operation The operation to execute
     * @param callable|null $fallback Fallback to execute if circuit is open or operation fails
     * @return mixed Result from operation or fallback
     *
     * @throws CircuitOpenException When circuit is open and no fallback provided
     * @throws \Throwable When operation fails and no fallback provided
     */
    public function execute(string $service, callable $operation, ?callable $fallback = null): mixed
    {
        // If circuit breaker is disabled, just run the operation
        if (! $this->enabled) {
            return $operation();
        }

        // Check if circuit allows the request
        if (! $this->canAttempt($service)) {
            Log::warning('Circuit breaker open, using fallback', [
                'service' => $service,
                'state' => $this->getState($service),
            ]);

            if ($fallback !== null) {
                return $fallback();
            }

            throw new CircuitOpenException($service);
        }

        try {
            $result = $operation();
            $this->recordSuccess($service);

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($service, $e);

            if ($fallback !== null) {
                Log::warning('Circuit breaker operation failed, using fallback', [
                    'service' => $service,
                    'error' => $e->getMessage(),
                ]);

                return $fallback();
            }

            throw $e;
        }
    }

    /**
     * Check if the circuit allows an attempt.
     */
    public function canAttempt(string $service): bool
    {
        $state = $this->getState($service);

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            // Check if recovery timeout has passed
            if ($this->hasRecoveryTimeoutPassed($service)) {
                $this->transitionToHalfOpen($service);

                return true;
            }

            return false;
        }

        // HALF_OPEN: allow limited attempts
        return true;
    }

    /**
     * Check if the circuit is currently open.
     */
    public function isOpen(string $service): bool
    {
        return $this->getState($service) === self::STATE_OPEN;
    }

    /**
     * Record a successful operation.
     */
    public function recordSuccess(string $service): void
    {
        $state = $this->getState($service);

        if ($state === self::STATE_HALF_OPEN) {
            $successCount = $this->incrementSuccessCount($service);
            $threshold = $this->getConfig($service, 'success_threshold', 2);

            if ($successCount >= $threshold) {
                $this->transitionToClosed($service);
            }
        } elseif ($state === self::STATE_OPEN) {
            // Shouldn't happen, but reset if it does
            $this->transitionToClosed($service);
        }

        // In CLOSED state, success is the normal case - no action needed
    }

    /**
     * Record a failed operation.
     */
    public function recordFailure(string $service, ?\Throwable $exception = null): void
    {
        $state = $this->getState($service);

        if ($state === self::STATE_HALF_OPEN) {
            // Any failure in half-open immediately opens the circuit
            $this->transitionToOpen($service);
            Log::warning('Circuit breaker reopened from half-open state', [
                'service' => $service,
                'error' => $exception?->getMessage(),
            ]);

            return;
        }

        // CLOSED state: increment failure count
        $failureCount = $this->incrementFailureCount($service);
        $threshold = $this->getConfig($service, 'failure_threshold', 5);

        if ($failureCount >= $threshold) {
            $this->transitionToOpen($service);
            Log::error('Circuit breaker opened', [
                'service' => $service,
                'failure_count' => $failureCount,
                'threshold' => $threshold,
                'error' => $exception?->getMessage(),
            ]);
        }
    }

    /**
     * Get the current state of a circuit.
     */
    public function getState(string $service): string
    {
        try {
            return $this->cacheGet("{$service}:state", self::STATE_CLOSED);
        } catch (\Throwable $e) {
            // If cache fails, assume closed (allow operations)
            Log::warning('Circuit breaker cache read failed, assuming closed', [
                'service' => $service,
                'error' => $e->getMessage(),
            ]);

            return self::STATE_CLOSED;
        }
    }

    /**
     * Manually reset a circuit to closed state.
     */
    public function reset(string $service): void
    {
        $this->transitionToClosed($service);
        Log::info('Circuit breaker manually reset', ['service' => $service]);
    }

    /**
     * Get circuit breaker status for monitoring.
     */
    public function getStatus(string $service): array
    {
        return [
            'service' => $service,
            'state' => $this->getState($service),
            'failure_count' => (int) $this->cacheGet("{$service}:failures", 0),
            'success_count' => (int) $this->cacheGet("{$service}:successes", 0),
            'last_failure_at' => $this->cacheGet("{$service}:last_failure_at"),
            'opened_at' => $this->cacheGet("{$service}:opened_at"),
            'config' => [
                'failure_threshold' => $this->getConfig($service, 'failure_threshold', 5),
                'recovery_timeout' => $this->getConfig($service, 'recovery_timeout', 30),
                'success_threshold' => $this->getConfig($service, 'success_threshold', 2),
            ],
        ];
    }

    /**
     * Get all configured services and their statuses.
     */
    public function getAllStatuses(): array
    {
        $services = array_keys(config('circuit-breaker.services', []));
        $statuses = [];

        foreach ($services as $service) {
            $statuses[$service] = $this->getStatus($service);
        }

        return $statuses;
    }

    // -------------------------------------------------------------------------
    // Protected Methods
    // -------------------------------------------------------------------------

    protected function transitionToOpen(string $service): void
    {
        $previousState = $this->getState($service);
        $recoveryTimeout = $this->getConfig($service, 'recovery_timeout', 30);

        $this->cacheSet("{$service}:state", self::STATE_OPEN, $recoveryTimeout + 60);
        $this->cacheSet("{$service}:opened_at", now()->toIso8601String(), $recoveryTimeout + 60);
        $this->cacheForget("{$service}:failures");
        $this->cacheForget("{$service}:successes");

        // Record metrics for monitoring
        $this->metrics?->recordCircuitStateChange($service, $previousState, self::STATE_OPEN);
    }

    protected function transitionToHalfOpen(string $service): void
    {
        $this->cacheSet("{$service}:state", self::STATE_HALF_OPEN, 300);
        $this->cacheForget("{$service}:successes");

        Log::info('Circuit breaker transitioned to half-open', ['service' => $service]);

        // Record metrics for monitoring
        $this->metrics?->recordCircuitStateChange($service, self::STATE_OPEN, self::STATE_HALF_OPEN);
    }

    protected function transitionToClosed(string $service): void
    {
        $previousState = $this->getState($service);

        $this->cacheForget("{$service}:state");
        $this->cacheForget("{$service}:failures");
        $this->cacheForget("{$service}:successes");
        $this->cacheForget("{$service}:opened_at");
        $this->cacheForget("{$service}:last_failure_at");

        Log::info('Circuit breaker closed', ['service' => $service]);

        // Record metrics for monitoring (only if transitioning from a different state)
        if ($previousState !== self::STATE_CLOSED) {
            $this->metrics?->recordCircuitStateChange($service, $previousState, self::STATE_CLOSED);
        }
    }

    protected function hasRecoveryTimeoutPassed(string $service): bool
    {
        $openedAt = $this->cacheGet("{$service}:opened_at");

        if (! $openedAt) {
            return true;
        }

        $recoveryTimeout = $this->getConfig($service, 'recovery_timeout', 30);
        $openedTime = \Carbon\Carbon::parse($openedAt);

        // Use absolute value since diffInSeconds can be negative when comparing past times
        return abs(now()->diffInSeconds($openedTime)) >= $recoveryTimeout;
    }

    protected function incrementFailureCount(string $service): int
    {
        $key = $this->getCacheKey("{$service}:failures");
        $ttl = 300; // 5 minutes window for counting failures

        try {
            if (Cache::has($key)) {
                return Cache::increment($key);
            }

            Cache::put($key, 1, $ttl);
            $this->cacheSet("{$service}:last_failure_at", now()->toIso8601String(), $ttl);

            return 1;
        } catch (\Throwable $e) {
            Log::warning('Circuit breaker cache increment failed', [
                'service' => $service,
                'error' => $e->getMessage(),
            ]);

            return 1;
        }
    }

    protected function incrementSuccessCount(string $service): int
    {
        $key = $this->getCacheKey("{$service}:successes");

        try {
            if (Cache::has($key)) {
                return Cache::increment($key);
            }

            Cache::put($key, 1, 300);

            return 1;
        } catch (\Throwable $e) {
            return 1;
        }
    }

    protected function getConfig(string $service, string $key, mixed $default = null): mixed
    {
        return config("circuit-breaker.services.{$service}.{$key}", $default);
    }

    protected function getCacheKey(string $key): string
    {
        return "{$this->cachePrefix}:{$key}";
    }

    protected function cacheGet(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::get($this->getCacheKey($key), $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    protected function cacheSet(string $key, mixed $value, int $ttl = 3600): void
    {
        try {
            Cache::put($this->getCacheKey($key), $value, $ttl);
        } catch (\Throwable $e) {
            Log::warning('Circuit breaker cache write failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function cacheForget(string $key): void
    {
        try {
            Cache::forget($this->getCacheKey($key));
        } catch (\Throwable $e) {
            // Ignore cache delete failures
        }
    }
}
