<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CircuitBreakerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class HealthController extends Controller
{
    public function __construct(
        private CircuitBreakerService $circuitBreaker
    ) {}

    /**
     * Basic health check endpoint (public).
     *
     * Returns simple status for load balancers and uptime monitors.
     */
    public function index(): JsonResponse
    {
        $checks = $this->runHealthChecks();
        $status = $this->determineOverallStatus($checks);

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
        ], $status === 'healthy' ? 200 : 503);
    }

    /**
     * Detailed health check endpoint (authenticated).
     *
     * Returns comprehensive status with latencies and error details.
     */
    public function detailed(): JsonResponse
    {
        $checks = $this->runHealthChecks(detailed: true);
        $status = $this->determineOverallStatus($checks);

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
            'circuit_breakers' => $this->circuitBreaker->getAllStatuses(),
        ], $status === 'healthy' ? 200 : 503);
    }

    /**
     * Run all health checks.
     */
    protected function runHealthChecks(bool $detailed = false): array
    {
        return [
            'database' => $this->checkDatabase($detailed),
            'cache' => $this->checkCache($detailed),
            'queue' => $this->checkQueue($detailed),
        ];
    }

    /**
     * Check database connectivity.
     */
    protected function checkDatabase(bool $detailed = false): array
    {
        $start = microtime(true);
        $result = [
            'status' => 'down',
            'latency_ms' => null,
            'error' => null,
        ];

        try {
            DB::select('SELECT 1');
            $result['status'] = 'up';
            $result['latency_ms'] = round((microtime(true) - $start) * 1000, 2);

            if ($detailed) {
                $result['connection'] = config('database.default');
                $result['driver'] = config('database.connections.'.config('database.default').'.driver');
            }
        } catch (\Throwable $e) {
            $result['latency_ms'] = round((microtime(true) - $start) * 1000, 2);
            $result['error'] = $detailed ? $e->getMessage() : 'Connection failed';

            // Record failure in circuit breaker
            $this->circuitBreaker->recordFailure('database', $e);
        }

        return $result;
    }

    /**
     * Check cache connectivity.
     */
    protected function checkCache(bool $detailed = false): array
    {
        $start = microtime(true);
        $result = [
            'status' => 'down',
            'latency_ms' => null,
            'error' => null,
        ];

        try {
            $testKey = 'health_check_'.uniqid();
            Cache::put($testKey, true, 10);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);

            if ($retrieved === true) {
                $result['status'] = 'up';
            } else {
                $result['error'] = 'Cache read/write mismatch';
            }

            $result['latency_ms'] = round((microtime(true) - $start) * 1000, 2);

            if ($detailed) {
                $result['driver'] = config('cache.default');
            }
        } catch (\Throwable $e) {
            $result['latency_ms'] = round((microtime(true) - $start) * 1000, 2);
            $result['error'] = $detailed ? $e->getMessage() : 'Connection failed';

            // Record failure in circuit breaker
            $this->circuitBreaker->recordFailure('cache', $e);
        }

        return $result;
    }

    /**
     * Check queue status.
     */
    protected function checkQueue(bool $detailed = false): array
    {
        $result = [
            'status' => 'up',
            'pending_jobs' => null,
            'error' => null,
        ];

        try {
            $connection = config('queue.default');

            if ($connection === 'database') {
                // Check pending jobs in database queue
                $pendingJobs = DB::table('jobs')->count();
                $result['pending_jobs'] = $pendingJobs;

                // Check for stuck jobs (available_at in the past, reserved_at is null)
                if ($detailed) {
                    $stuckJobs = DB::table('jobs')
                        ->where('available_at', '<', now()->subMinutes(5)->timestamp)
                        ->whereNull('reserved_at')
                        ->count();

                    $result['stuck_jobs'] = $stuckJobs;
                    $result['connection'] = $connection;

                    // Check failed jobs
                    $failedJobs = DB::table('failed_jobs')->count();
                    $result['failed_jobs'] = $failedJobs;
                }

                // Warn if too many pending jobs
                if ($pendingJobs > 1000) {
                    $result['status'] = 'degraded';
                    $result['warning'] = 'High queue backlog';
                }
            } elseif ($connection === 'sync') {
                $result['status'] = 'up';
                if ($detailed) {
                    $result['connection'] = 'sync';
                    $result['note'] = 'Using synchronous queue (no workers needed)';
                }
            } else {
                if ($detailed) {
                    $result['connection'] = $connection;
                }
            }
        } catch (\Throwable $e) {
            $result['status'] = 'down';
            $result['error'] = $detailed ? $e->getMessage() : 'Check failed';
        }

        return $result;
    }

    /**
     * Determine overall health status based on individual checks.
     */
    protected function determineOverallStatus(array $checks): string
    {
        $hasDown = false;
        $hasDegraded = false;

        foreach ($checks as $check) {
            if ($check['status'] === 'down') {
                $hasDown = true;
            } elseif ($check['status'] === 'degraded') {
                $hasDegraded = true;
            }
        }

        if ($hasDown) {
            return 'unhealthy';
        }

        if ($hasDegraded) {
            return 'degraded';
        }

        return 'healthy';
    }
}
