<?php

namespace App\Console\Commands;

use App\Services\CircuitBreakerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CheckHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'health:check
                            {--detailed : Show detailed status}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check application health status (exit codes: 0=healthy, 1=degraded, 2=unhealthy)';

    /**
     * Execute the console command.
     *
     * @return int 0 for healthy, 1 for degraded, 2 for unhealthy
     */
    public function handle(CircuitBreakerService $circuitBreaker): int
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
        ];

        $status = $this->determineStatus($checks);
        $exitCode = match ($status) {
            'healthy' => 0,
            'degraded' => 1,
            default => 2,
        };

        if ($this->option('json')) {
            $output = [
                'status' => $status,
                'timestamp' => now()->toIso8601String(),
                'checks' => $checks,
            ];

            if ($this->option('detailed')) {
                $output['circuit_breakers'] = $circuitBreaker->getAllStatuses();
            }

            $this->line(json_encode($output, JSON_PRETTY_PRINT));

            return $exitCode;
        }

        // Human-readable output
        $statusEmoji = match ($status) {
            'healthy' => '✅',
            'degraded' => '⚠️',
            default => '❌',
        };

        $this->info("{$statusEmoji} Overall Status: {$status}");
        $this->newLine();

        $this->table(
            ['Component', 'Status', 'Latency (ms)', 'Details'],
            collect($checks)->map(function ($check, $name) {
                $statusIcon = match ($check['status']) {
                    'up' => '✓',
                    'degraded' => '!',
                    default => '✗',
                };

                return [
                    ucfirst($name),
                    "{$statusIcon} {$check['status']}",
                    $check['latency_ms'] ?? '-',
                    $check['error'] ?? $check['warning'] ?? '-',
                ];
            })->toArray()
        );

        if ($this->option('detailed')) {
            $this->newLine();
            $this->info('Circuit Breaker Status:');

            $cbStatuses = $circuitBreaker->getAllStatuses();
            $this->table(
                ['Service', 'State', 'Failures', 'Last Failure'],
                collect($cbStatuses)->map(function ($status, $name) {
                    $stateIcon = match ($status['state']) {
                        'closed' => '🟢',
                        'open' => '🔴',
                        'half_open' => '🟡',
                        default => '⚪',
                    };

                    return [
                        $name,
                        "{$stateIcon} {$status['state']}",
                        $status['failure_count'],
                        $status['last_failure_at'] ?? '-',
                    ];
                })->toArray()
            );
        }

        return $exitCode;
    }

    protected function checkDatabase(): array
    {
        $start = microtime(true);

        try {
            DB::select('SELECT 1');

            return [
                'status' => 'up',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'down',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function checkCache(): array
    {
        $start = microtime(true);

        try {
            $key = 'health_check_cli_'.uniqid();
            Cache::put($key, true, 10);
            $retrieved = Cache::get($key);
            Cache::forget($key);

            if ($retrieved === true) {
                return [
                    'status' => 'up',
                    'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                ];
            }

            return [
                'status' => 'down',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                'error' => 'Read/write mismatch',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'down',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function checkQueue(): array
    {
        try {
            $connection = config('queue.default');

            if ($connection === 'database') {
                $pendingJobs = DB::table('jobs')->count();

                if ($pendingJobs > 1000) {
                    return [
                        'status' => 'degraded',
                        'pending_jobs' => $pendingJobs,
                        'warning' => 'High queue backlog',
                    ];
                }

                return [
                    'status' => 'up',
                    'pending_jobs' => $pendingJobs,
                ];
            }

            return [
                'status' => 'up',
                'connection' => $connection,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'down',
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function determineStatus(array $checks): string
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
