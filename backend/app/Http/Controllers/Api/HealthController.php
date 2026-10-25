<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CircuitBreakerService;
use App\Services\Health\RealtimeHealthSnapshotStore;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class HealthController extends Controller
{
    /** Snapshot older than this = the health pipeline itself is broken. */
    private const SNAPSHOT_MAX_AGE_SECONDS = 180;

    /** Total ready backlog across all queues above this = degraded. */
    private const QUEUE_BACKLOG_THRESHOLD = 100;

    /** Failed jobs in the last 5 minutes above this = degraded. */
    private const QUEUE_RECENT_FAILURE_THRESHOLD = 10;

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
     * Realtime-focused health check (broadcasting + queue).
     *
     * Reads the snapshot written by `health:check-realtime` (scheduled every
     * minute) instead of making outbound calls per request. Falls back to the
     * legacy direct counts when no snapshot exists yet (e.g. right after a
     * deploy before the first scheduler tick), which also keeps the status
     * degraded until fresh data arrives.
     */
    public function realtime(RealtimeHealthSnapshotStore $snapshots): JsonResponse
    {
        $snapshot = $snapshots->read();

        $broadcastDriver = config('broadcasting.default');
        $broadcastOk = $broadcastDriver !== 'null';

        $live = $snapshot['broadcasting']['live'] ?? null;
        if (! is_bool($live)) {
            $live = null;
        }

        $checkedAt = $snapshot['checked_at'] ?? null;
        $ageSeconds = null;
        if (is_string($checkedAt)) {
            try {
                $ageSeconds = max(0, now()->getTimestamp() - Carbon::parse($checkedAt)->getTimestamp());
            } catch (\Throwable) {
                $ageSeconds = null; // unparseable timestamp = cannot prove freshness
            }
        }

        $latencyMs = $snapshot['broadcasting']['latency_ms'] ?? null;
        $backlog = $snapshot['queue']['backlog'] ?? null;
        $backlogTotal = $this->sumBacklog($backlog);

        if ($snapshot === null) {
            // Legacy behaviour: answer from the database directly.
            $failedTotal = (int) DB::table('failed_jobs')->count();
            $failedRecent = (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subMinutes(5))
                ->count();
            $depth = (int) DB::table('jobs')->where('available_at', '<=', now()->timestamp)->count();
            $backlog = ['database' => ['ready' => $depth]];
        } else {
            $failedTotal = (int) ($snapshot['queue']['failed_total'] ?? 0);
            $failedRecent = (int) ($snapshot['queue']['failed_recent_5m'] ?? 0);
            $depth = $backlogTotal ?? 0;
        }

        // Degraded when: probe says Reverb is dead, the snapshot is missing or
        // stale (> 180 s = scheduler/probe itself is broken), or the queues are
        // backing up / failing now. Historical failures alone are NOT an
        // incident — only failures in the last 5 minutes count.
        $staleSnapshot = $snapshot === null || $ageSeconds === null || $ageSeconds > self::SNAPSHOT_MAX_AGE_SECONDS;
        $queueOk = ($backlogTotal ?? 0) < self::QUEUE_BACKLOG_THRESHOLD
            && $failedRecent < self::QUEUE_RECENT_FAILURE_THRESHOLD;
        $status = ($broadcastOk && $queueOk && ! $staleSnapshot && $live !== false)
            ? 'healthy'
            : 'degraded';

        return response()->json([
            'status' => $status,
            'checks' => [
                'broadcasting' => [
                    'ok' => $broadcastOk,
                    'driver' => $broadcastDriver,
                    'live' => $live,
                    'checked_at' => $checkedAt,
                    'age_seconds' => $ageSeconds,
                    'latency_ms' => $latencyMs,
                ],
                'queue' => [
                    'ok' => $queueOk,
                    'depth' => $depth,
                    'failed' => $failedTotal,
                    'connection' => config('queue.default'),
                    'failed_recent_5m' => $failedRecent,
                    'failed_total' => $failedTotal,
                    'backlog' => $backlog,
                ],
            ],
        ]);
    }

    /**
     * Sum every integer count in the backlog tree; null entries (a queue that
     * could not be sampled) contribute nothing instead of hiding the total.
     */
    private function sumBacklog(mixed $backlog): ?int
    {
        if (! is_array($backlog)) {
            return null;
        }

        $total = 0;
        array_walk_recursive($backlog, function ($value) use (&$total): void {
            if (is_int($value) || is_float($value)) {
                $total += $value;
            }
        });

        return $total;
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
