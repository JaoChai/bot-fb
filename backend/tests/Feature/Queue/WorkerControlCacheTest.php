<?php

namespace Tests\Feature\Queue;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Worker-control cache coherence.
 *
 * Laravel injects the container's `cache.store` repository into
 * WorkCommand (restart signal + pause checks) and RestartCommand
 * (`illuminate:queue:restart`), and QueueManager reads/writes
 * pause state through the default store. With prod's
 * CACHE_STORE=redis, a Redis outage throws out of the worker's
 * daemon loop *before* the RedisFallbackSwitch Looping hook can
 * engage, killing the worker. These tests pin the whole
 * restart/pause control plane to the Redis-independent `database`
 * cache store.
 */
class WorkerControlCacheTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind the default cache store to a store whose every operation throws,
     * the same failure shape as a refused Redis connection in production.
     */
    private function simulateRedisOutage(): void
    {
        $store = new class implements Store
        {
            public function get($key)
            {
                throw new \RuntimeException('simulated-redis-outage');
            }

            public function many(array $keys)
            {
                throw new \RuntimeException('simulated-redis-outage');
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('simulated-redis-outage');
            }

            public function putMany(array $values, $seconds)
            {
                throw new \RuntimeException('simulated-redis-outage');
            }

            public function increment($key, $value = 1)
            {
                throw new \RuntimeException('simulated-redis-outage');
            }

            public function decrement($key, $value = 1)
            {
                throw new \RuntimeException('simulated-redis-outage');
            }

            public function forever($key, $value)
            {
                throw new \RuntimeException('simulated-redis-outage');
            }

            public function touch($key, $seconds)
            {
                throw new \RuntimeException('simulated-redis-outage');
            }

            public function forget($key)
            {
                throw new \RuntimeException('simulated-redis-outage');
            }

            public function flush()
            {
                throw new \RuntimeException('simulated-redis-outage');
            }

            public function getPrefix()
            {
                return 'throwing-redis-testing:';
            }
        };

        $this->app['cache']->extend('throwing-redis', fn () => new CacheRepository($store));
        config([
            'cache.stores.throwing-redis' => ['driver' => 'throwing-redis'],
            'cache.default' => 'throwing-redis',
        ]);
    }

    public function test_queue_restart_writes_signal_to_database_store_while_default_cache_throws(): void
    {
        $this->simulateRedisOutage();

        $this->artisan('queue:restart')->assertExitCode(0);

        $this->assertNotNull(
            Cache::store('database')->get('illuminate:queue:restart'),
            'queue:restart must write its signal to the redis-independent database store.'
        );
    }

    public function test_queue_pause_and_resume_round_trip_via_control_store_while_default_cache_throws(): void
    {
        $this->simulateRedisOutage();

        $this->artisan('queue:pause', ['--all' => true])->assertExitCode(0);

        $this->assertTrue(
            (bool) Cache::store('database')->get('illuminate:queues:paused'),
            'queue:pause must record paused state in the database store.'
        );
        $this->assertTrue(app('queue')->isPaused('database', 'default'));
        $this->assertSame(['default'], app('queue')->getPausedQueues('database', ['default']));

        $this->artisan('queue:resume', ['--all' => true])->assertExitCode(0);

        $this->assertNull(Cache::store('database')->get('illuminate:queues:paused'));
        $this->assertFalse(app('queue')->isPaused('database', 'default'));
        $this->assertSame([], app('queue')->getPausedQueues('database', ['default']));
    }

    public function test_control_store_writes_do_not_touch_the_default_cache_store(): void
    {
        $this->artisan('queue:restart')->assertExitCode(0);

        $this->assertNull(
            Cache::get('illuminate:queue:restart'),
            'worker-control keys must not leak into the default (business) cache store.'
        );
        $this->assertNotNull(Cache::store('database')->get('illuminate:queue:restart'));
        $this->assertSame(
            Cache::store('database'),
            $this->app['cache.store'],
            'the cache.store repository Laravel injects into queue commands must be the database store.'
        );
        $this->assertSame('array', config('cache.default'));
    }

    /**
     * Real-process check: a live `queue:work database` daemon with the default
     * cache pointing at a refused Redis (CACHE_STORE=redis, nothing listening)
     * must keep processing database-connection jobs, and a `queue:restart`
     * issued while Redis is down must stop it cleanly (exit code 0).
     */
    public function test_real_worker_process_runs_database_queue_and_stops_on_queue_restart_while_redis_is_down(): void
    {
        $base = base_path();
        $php = PHP_BINARY;
        $dir = sys_get_temp_dir().'/worker-control-'.uniqid();
        mkdir($dir, 0777, true);
        $db = $dir.'/queue.sqlite';
        $marker = $dir.'/job-processed.marker';
        $this->tempDir = $dir;

        // Nothing listens on this port: every Redis connect is refused, the
        // same way production behaves during a Redis outage.
        $redisPort = $this->findUnboundLocalPort();

        $env = $this->subprocessEnv([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $db,
            'CACHE_STORE' => 'redis',
            'REDIS_CLIENT' => 'predis',
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => (string) $redisPort,
            'QUEUE_CONNECTION' => 'database',
            'SESSION_DRIVER' => 'file',
        ]);

        [$migrateExit, $migrateOut] = $this->runProcess(
            array_merge($env, ['CACHE_STORE' => 'array']),
            [$php, $base.'/artisan', 'migrate', '--force'],
            $base
        );
        $this->assertSame(0, $migrateExit, "migrate failed:\n".$migrateOut);

        $dispatch = $dir.'/dispatch-probe.php';
        file_put_contents($dispatch, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['queue']->connection('database')->push(function () use ($argv) {
    file_put_contents($argv[2], 'processed');
});
echo "dispatched\n";
PHP);

        [, $dispatchOut] = $this->runProcess($env, [$php, $dispatch, $base, $marker], $base);
        $this->assertStringContainsString('dispatched', $dispatchOut, "dispatch probe failed:\n".$dispatchOut);
        $this->assertFileDoesNotExist($marker);

        $workerOut = $dir.'/worker.out';
        $workerErr = $dir.'/worker.err';
        $worker = proc_open(
            [$php, $base.'/artisan', 'queue:work', 'database', '--sleep=1', '--tries=1', '--verbose'],
            [1 => ['file', $workerOut, 'w'], 2 => ['file', $workerErr, 'w']],
            $pipes,
            $base,
            $env
        );
        $this->assertIsResource($worker, 'failed to start queue:work subprocess');

        // The worker must survive with Redis down and process the queued job.
        $deadline = microtime(true) + 60;
        do {
            usleep(200_000);
            $running = proc_get_status($worker)['running'] ?? false;
        } while (! file_exists($marker) && $running && microtime(true) < $deadline);

        $diagnostics = "worker.out:\n".file_get_contents($workerOut)."\nworker.err:\n".file_get_contents($workerErr);
        $this->assertTrue($running, "worker process died while Redis was down:\n".$diagnostics);
        $this->assertFileExists($marker, "worker never processed the dispatched job:\n".$diagnostics);

        // queue:restart must succeed while Redis is down...
        [$restartExit, $restartOut] = $this->runProcess($env, [$php, $base.'/artisan', 'queue:restart'], $base);
        $this->assertSame(0, $restartExit, "queue:restart failed while Redis was down:\n".$restartOut);

        // ...and the running worker must see the signal and exit cleanly.
        $stopDeadline = microtime(true) + 30;
        do {
            usleep(200_000);
            $status = proc_get_status($worker);
        } while ($status['running'] && microtime(true) < $stopDeadline);

        proc_close($worker);
        $this->assertFalse($status['running'], "worker ignored the queue:restart signal:\n".$diagnostics);
        $this->assertSame(0, $status['exitcode'], "worker did not exit cleanly:\n".$diagnostics);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    private function findUnboundLocalPort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($sock, 'could not bind an ephemeral port: '.$errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private function subprocessEnv(array $overrides): array
    {
        return array_merge(getenv(), $overrides);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runProcess(array $env, array $command, string $cwd): array
    {
        $out = tempnam(sys_get_temp_dir(), 'wcc-out-');
        $process = proc_open(
            $command,
            [1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']],
            $pipes,
            $cwd,
            $env
        );
        $this->assertIsResource($process, 'failed to start subprocess: '.implode(' ', $command));
        $exit = proc_close($process);

        return [$exit, (string) file_get_contents($out)];
    }

    private ?string $tempDir = null;
}
