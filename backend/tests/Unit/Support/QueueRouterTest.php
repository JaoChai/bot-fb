<?php

namespace Tests\Unit\Support;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\User;
use App\Services\RedisHealthGate;
use App\Support\QueueRouter;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Predis\Connection\Resource\Exception\StreamInitException;
use Tests\TestCase;

class QueueRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('file')->forget('redis_health:up');
        config(['queue.llm_split_enabled' => false]);
    }

    protected function tearDown(): void
    {
        Cache::store('file')->forget('redis_health:up');

        parent::tearDown();
    }

    public function test_returns_webhooks_when_split_disabled(): void
    {
        config(['queue.llm_split_enabled' => false]);

        $this->assertSame('webhooks', QueueRouter::llmQueue());
    }

    public function test_returns_llm_when_split_enabled(): void
    {
        config(['queue.llm_split_enabled' => true]);

        $this->assertSame('llm', QueueRouter::llmQueue());
    }

    public function test_default_config_routes_to_webhooks(): void
    {
        $this->assertFalse(config('queue.llm_split_enabled'));
        $this->assertSame('webhooks', QueueRouter::llmQueue());
    }

    /**
     * Seed the file-cache health key so isRedisUp() answers "up" without probing.
     */
    private function gateUp(): void
    {
        Cache::store('file')->put('redis_health:up', true, 10);
    }

    private function job(): ProcessLINEWebhook
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);

        return new ProcessLINEWebhook($bot, [
            'type' => 'message',
            'replyToken' => 'reply_token_123',
            'message' => ['id' => 'msg_123', 'type' => 'text', 'text' => 'Hello!'],
        ]);
    }

    public function test_dispatch_llm_healthy_path_dispatches_exactly_once_to_the_default_connection(): void
    {
        Queue::fake();
        $this->gateUp();

        $job = $this->job();

        QueueRouter::dispatchLlm($job);

        Queue::assertPushed(ProcessLINEWebhook::class, 1);
        Queue::assertPushed(ProcessLINEWebhook::class, function ($pushed) use ($job) {
            return $pushed === $job
                && $pushed->connection === null // default connection
                && $pushed->queue === QueueRouter::llmQueue();
        });
    }

    public function test_dispatch_llm_falls_back_to_database_exactly_once_and_marks_down_when_dispatch_throws(): void
    {
        $this->gateUp();

        // A queue connection that fails exactly like the 2026-10-22 incident:
        // a real Queue implementation whose push() dies with StreamInitException.
        config(['queue.connections.blip' => ['driver' => 'blip']]);
        $this->app['queue']->extend('blip', fn () => new class
        {
            public function connect(array $config): object
            {
                return new class(app(ConnectionResolverInterface::class)->connection(), 'jobs') extends DatabaseQueue
                {
                    public function push($job, $data = '', $queue = null)
                    {
                        throw new StreamInitException(
                            'php_network_getaddresses: getaddrinfo for redis.railway.internal failed: Try again'
                        );
                    }
                };
            }
        });
        config(['queue.default' => 'blip']);

        $job = $this->job();

        QueueRouter::dispatchLlm($job); // must not throw

        $this->assertDatabaseCount('jobs', 1);
        $queued = DB::table('jobs')->first();
        $this->assertSame(QueueRouter::llmQueue(), $queued->queue);
        $this->assertStringContainsString('ProcessLINEWebhook', $queued->payload);

        // The helper must mark the gate down so later requests skip the dead Redis.
        $this->assertFalse(app(RedisHealthGate::class)->isRedisUp());
    }
}
