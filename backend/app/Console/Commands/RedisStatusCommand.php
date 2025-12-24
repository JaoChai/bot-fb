<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class RedisStatusCommand extends Command
{
    protected $signature = 'redis:status';

    protected $description = 'Display Redis connection status and statistics';

    public function handle(): int
    {
        $this->info('🔴 Redis Status');
        $this->newLine();

        // Test connection
        try {
            $pong = Redis::connection()->ping();
            $this->line('  Connection: <fg=green>✓ Connected</>');
        } catch (\Exception $e) {
            $this->line('  Connection: <fg=red>✗ Failed</>');
            $this->error('  Error: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Get Redis info (limited for Upstash)
        try {
            $info = Redis::info();
            $this->line('  Provider: <fg=cyan>Upstash</>');

            if (isset($info['redis_version'])) {
                $this->line("  Version: {$info['redis_version']}");
            }
        } catch (\Exception $e) {
            $this->line('  <fg=yellow>Note: Limited info available (Upstash)</>');
        }

        $this->newLine();
        $this->info('📊 Queue Status');

        // Queue statistics
        $queues = ['default', 'high', 'low'];
        foreach ($queues as $queue) {
            try {
                $count = Redis::llen("queues:{$queue}") ?? 0;
                $icon = $count > 0 ? '📦' : '✓';
                $this->line("  {$icon} {$queue}: {$count} jobs");
            } catch (\Exception $e) {
                $this->line("  {$queue}: <fg=yellow>unavailable</>");
            }
        }

        $this->newLine();
        $this->info('🧪 Cache Test');

        // Test cache operations
        try {
            $testKey = 'redis:status:test:' . time();
            Cache::put($testKey, 'test-value', 10);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);

            if ($retrieved === 'test-value') {
                $this->line('  Cache: <fg=green>✓ Read/Write OK</>');
            } else {
                $this->line('  Cache: <fg=red>✗ Read/Write Failed</>');
            }
        } catch (\Exception $e) {
            $this->line('  Cache: <fg=red>✗ Error: ' . $e->getMessage() . '</>');
        }

        $this->newLine();
        $this->info('⚙️  Configuration');
        $this->line('  Driver: ' . config('cache.default'));
        $this->line('  Session: ' . config('session.driver'));
        $this->line('  Queue: ' . config('queue.default'));
        $this->line('  Host: ' . config('database.redis.default.host'));

        $this->newLine();

        return self::SUCCESS;
    }
}
