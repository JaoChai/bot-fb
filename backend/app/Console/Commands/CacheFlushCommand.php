<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CacheFlushCommand extends Command
{
    protected $signature = 'cache:flush-redis
                            {--pattern= : Flush keys matching pattern (e.g., "user:*")}
                            {--force : Skip confirmation}';

    protected $description = 'Flush Redis cache with optional pattern matching';

    public function handle(): int
    {
        $pattern = $this->option('pattern');

        if ($pattern) {
            return $this->flushByPattern($pattern);
        }

        return $this->flushAll();
    }

    protected function flushAll(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('⚠️  This will flush ALL cache data. Continue?')) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }
        }

        try {
            Cache::flush();
            $this->info('✓ All cache data has been flushed.');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to flush cache: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    protected function flushByPattern(string $pattern): int
    {
        $prefix = config('database.redis.options.prefix', '');
        $fullPattern = $prefix . $pattern;

        $this->info("Searching for keys matching: {$pattern}");

        try {
            $keys = Redis::keys($fullPattern);

            if (empty($keys)) {
                $this->info('No keys found matching the pattern.');
                return self::SUCCESS;
            }

            $this->info(count($keys) . ' keys found:');
            foreach (array_slice($keys, 0, 10) as $key) {
                $this->line("  - {$key}");
            }

            if (count($keys) > 10) {
                $this->line("  ... and " . (count($keys) - 10) . " more");
            }

            if (!$this->option('force')) {
                if (!$this->confirm('Delete these keys?')) {
                    $this->info('Cancelled.');
                    return self::SUCCESS;
                }
            }

            foreach ($keys as $key) {
                Redis::del($key);
            }

            $this->info('✓ ' . count($keys) . ' keys deleted.');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
