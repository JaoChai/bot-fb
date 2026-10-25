<?php

namespace App\Console\Commands;

use App\Services\Health\RealtimeHealthProbe;
use App\Services\Health\RealtimeHealthSnapshotStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class CheckRealtimeHealth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'health:check-realtime';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Probe Reverb liveness and queue backlog, then write the snapshot /api/health/realtime serves (Redis-independent)';

    /**
     * Queues sampled for backlog on the queue connection.
     *
     * @var list<string>
     */
    private const QUEUE_NAMES = ['llm', 'webhooks', 'default', 'low'];

    /**
     * Execute the console command.
     */
    public function handle(RealtimeHealthProbe $probe, RealtimeHealthSnapshotStore $store): int
    {
        $probeResult = $probe->check();

        $store->write([
            'checked_at' => now()->toIso8601String(),
            'broadcasting' => [
                'driver' => config('broadcasting.default'),
                'live' => $probeResult['live'],
                'error_class' => $probeResult['error_class'],
                'latency_ms' => $probeResult['latency_ms'],
            ],
            'queue' => [
                'backlog' => $this->sampleBacklog(),
                'failed_recent_5m' => (int) DB::table('failed_jobs')
                    ->where('failed_at', '>=', now()->subMinutes(5))
                    ->count(),
                'failed_total' => (int) DB::table('failed_jobs')->count(),
            ],
        ]);

        $this->info(sprintf(
            'health:check-realtime live=%s latency=%dms failed_recent_5m written to %s',
            $probeResult['live'] ? 'true' : 'false',
            $probeResult['latency_ms'],
            $store->path(),
        ));

        return self::SUCCESS;
    }

    /**
     * Sample queue backlog. Any single sampling failure (e.g. Redis down)
     * degrades that entry to null instead of breaking the snapshot — the
     * endpoint must still be able to report what it knows.
     *
     * @return array<string, mixed>
     */
    private function sampleBacklog(): array
    {
        $connection = (string) config('queue.default');

        $sizes = [];
        foreach (self::QUEUE_NAMES as $queue) {
            try {
                $sizes[$queue] = Queue::connection($connection)->size($queue);
            } catch (\Throwable) {
                $sizes[$queue] = null;
            }
        }

        try {
            $database = ['ready' => (int) DB::table('jobs')
                ->where('available_at', '<=', now()->timestamp)
                ->count()];
        } catch (\Throwable) {
            $database = ['ready' => null];
        }

        return [
            $connection => $sizes,
            'database' => $database,
        ];
    }
}
