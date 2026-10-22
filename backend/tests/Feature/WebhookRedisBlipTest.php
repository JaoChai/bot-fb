<?php

namespace Tests\Feature;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\User;
use App\Support\QueueRouter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\Resource\Exception\StreamInitException;
use RuntimeException;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-22 Redis blip: a short Redis connection
 * failure must not turn webhook POSTs into 500s (LINE closes the connection
 * after ~1.9 s) — the throttle fails open and the event still gets dispatched.
 */
class WebhookRedisBlipTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Bot $bot;

    protected string $channelSecret = 'test_channel_secret_12345';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create([
            'user_id' => $this->user->id,
            'channel_type' => 'line',
            'channel_secret' => $this->channelSecret,
            'channel_access_token' => 'test_access_token',
            'webhook_url' => config('app.url').'/api/webhook/test_webhook_token_123',
        ]);

        Cache::store('file')->forget('redis_health:up');
    }

    protected function tearDown(): void
    {
        Cache::store('file')->forget('redis_health:up');

        parent::tearDown();
    }

    protected function generateSignature(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, $this->channelSecret, true));
    }

    /**
     * The health gate reports "up" from its file cache (the incident shape:
     * the last probe succeeded, Redis died between probes).
     */
    protected function gateUpFromCachedProbe(): void
    {
        Cache::store('file')->put('redis_health:up', true, 10);
    }

    /**
     * Make the limiter's cache read throw, like Cache\RedisStore::get() did
     * during the incident. A real RateLimiter keeps all its logic (including
     * the registered 'webhook' named limiter); only the cache repository's
     * reads throw.
     */
    protected function rateLimiterCacheThrows(\Throwable $exception): void
    {
        $real = $this->app->make(RateLimiter::class);

        $cache = Mockery::mock(Repository::class);
        $cache->shouldIgnoreMissing();
        $cache->shouldReceive('get')->andThrow($exception);

        $limiter = new RateLimiter($cache);
        $limiter->for('webhook', $real->limiter('webhook'));

        $this->app->instance(RateLimiter::class, $limiter);
    }

    /**
     * The incident's mid-request shape (reviewer probe on b04ca788): the
     * limiter's pre-controller read succeeds, Redis dies during the
     * controller, and the post-controller header read throws.
     */
    protected function rateLimiterCacheReadsOkThenThrows(int $okReads, \Throwable $exception): void
    {
        $real = $this->app->make(RateLimiter::class);

        $cache = Mockery::mock(Repository::class);
        $cache->shouldIgnoreMissing();
        $cache->shouldReceive('add')->andReturn(true);
        $cache->shouldReceive('get')->times($okReads)->andReturn(0);
        $cache->shouldReceive('get')->andThrow($exception);

        $limiter = new RateLimiter($cache);
        $limiter->for('webhook', $real->limiter('webhook'));

        $this->app->instance(RateLimiter::class, $limiter);
    }

    protected function postValidLineWebhook()
    {
        $body = json_encode([
            'destination' => 'U1234567890',
            'events' => [
                [
                    'type' => 'message',
                    'replyToken' => 'reply_token_123',
                    'source' => ['type' => 'user', 'userId' => 'U_user_123'],
                    'message' => ['id' => 'msg_123', 'type' => 'text', 'text' => 'Hello!'],
                ],
            ],
        ]);

        return $this->postJson('/api/webhook/test_webhook_token_123', json_decode($body, true), [
            'X-Line-Signature' => $this->generateSignature($body),
        ]);
    }

    public function test_stream_init_exception_on_limiter_read_fails_open_and_dispatches_to_database(): void
    {
        Queue::fake();
        Log::spy();
        $this->gateUpFromCachedProbe();
        $this->rateLimiterCacheThrows(new StreamInitException(
            'php_network_getaddresses: getaddrinfo for redis.railway.internal failed: Try again'
        ));

        $response = $this->postValidLineWebhook();

        $response->assertStatus(200);
        Queue::assertPushed(ProcessLINEWebhook::class, 1);
        Queue::assertPushed(ProcessLINEWebhook::class, function ($job) {
            return $job->connection === 'database'
                && $job->queue === QueueRouter::llmQueue();
        });
        Log::shouldHaveReceived('warning')->once()->with('Redis blip on webhook throttle - failing open');
    }

    public function test_connection_exception_on_limiter_read_fails_open_and_dispatches_to_database(): void
    {
        Queue::fake();
        Log::spy();
        $this->gateUpFromCachedProbe();
        $this->rateLimiterCacheThrows(new ConnectionException(
            Mockery::mock(NodeConnectionInterface::class),
            'Connection refused'
        ));

        $response = $this->postValidLineWebhook();

        $response->assertStatus(200);
        Queue::assertPushed(ProcessLINEWebhook::class, 1);
        Queue::assertPushed(ProcessLINEWebhook::class, function ($job) {
            return $job->connection === 'database' && $job->queue === 'webhooks';
        });
        Log::shouldHaveReceived('warning')->once()->with('Redis blip on webhook throttle - failing open');
    }

    public function test_redis_dying_during_the_controller_still_returns_200(): void
    {
        Queue::fake();
        Log::spy();
        $this->gateUpFromCachedProbe();
        $this->rateLimiterCacheReadsOkThenThrows(1, new StreamInitException(
            'php_network_getaddresses: getaddrinfo for redis.railway.internal failed: Try again'
        ));

        $response = $this->postValidLineWebhook();

        $response->assertStatus(200);
        Queue::assertPushed(ProcessLINEWebhook::class, 1);
        Log::shouldHaveReceived('warning')->once()->with('Redis blip on webhook throttle - failing open');
    }

    public function test_non_redis_exception_on_limiter_read_is_not_swallowed(): void
    {
        Queue::fake();
        Log::spy();
        $this->gateUpFromCachedProbe();
        $this->rateLimiterCacheThrows(new RuntimeException('something else broke'));

        $response = $this->postValidLineWebhook();

        $response->assertStatus(500);
        Queue::assertNotPushed(ProcessLINEWebhook::class);
        Log::shouldNotHaveReceived('warning', ['Redis blip on webhook throttle - failing open']);
    }

    /**
     * Rebuild the app's own RateLimiter singleton on the redis cache store —
     * what boot does in prod, where cache.default is redis — with that store
     * replaced by a spy that counts every access and throws like dead Redis.
     * The real RateLimiter keeps all its logic (including the registered
     * 'webhook' named limiter). Returns the spy so a test can assert how many
     * reads happened.
     */
    protected function redisCacheStoreThrowsOnAnyAccess(): ThrowingRedisStoreSpy
    {
        $spy = new ThrowingRedisStoreSpy;

        $this->app['config']->set('cache.default', 'redis');

        $cacheManager = $this->app['cache'];
        $cacheManager->extend('redis', fn () => new \Illuminate\Cache\Repository($spy));
        $cacheManager->forgetDriver('redis');

        $boundAtBoot = $this->app->make(RateLimiter::class);

        $this->app->forgetInstance(RateLimiter::class);

        $rebuilt = $this->app->make(RateLimiter::class);
        $rebuilt->for('webhook', $boundAtBoot->limiter('webhook'));

        return $spy;
    }

    /**
     * The second-request shape of the incident (QA repro, 15/17 PASS with one
     * FAIL): request 1 hit dead Redis and marked the gate down, request 2
     * arrives inside the 10 s TTL. The RateLimiter singleton keeps the redis
     * store it was built with at boot (the fallback switch's config change
     * cannot rebind it), so the throttle must skip the limiter entirely —
     * zero reads on the dead store, zero extra warnings, event still queued.
     */
    public function test_gate_already_down_skips_the_limiter_entirely(): void
    {
        Queue::fake();
        Log::spy();

        $redis = $this->redisCacheStoreThrowsOnAnyAccess();

        // Request 1 wrote the down verdict to the file store the gate reads.
        Cache::store('file')->put('redis_health:up', false, 10);

        $response = $this->postValidLineWebhook();

        $response->assertStatus(200);
        $this->assertSame(0, $redis->reads, 'the limiter read the dead redis store while the gate was down');
        $this->assertSame(0, $redis->touches, 'something touched the dead redis store while the gate was down');
        Queue::assertPushed(ProcessLINEWebhook::class, 1);
        Queue::assertPushed(ProcessLINEWebhook::class, function ($job) {
            return $job->connection === 'database'
                && $job->queue === QueueRouter::llmQueue();
        });
        Log::shouldNotHaveReceived('warning', ['Redis blip on webhook throttle - failing open']);
    }

    public function test_limiter_over_limit_still_returns_429(): void
    {
        Queue::fake();
        Log::spy();
        // The gate must be UP for the limiter to run at all (the throttle skips
        // it while the gate is down). Seed the cached "up" verdict so this
        // tests the real 429 on any host, with or without a live Redis.
        $this->gateUpFromCachedProbe();

        // Derive the key/limit exactly like ThrottleRequests does for named
        // limiters: md5($limiterName.$limit->key) with the registered closure.
        $request = Request::create(config('app.url').'/api/webhook/x', 'POST');
        $limit = app(RateLimiter::class)->limiter('webhook')($request);
        $key = md5('webhook'.$limit->key);
        for ($i = 0; $i < $limit->maxAttempts; $i++) {
            app(RateLimiter::class)->hit($key, $limit->decaySeconds);
        }

        $response = $this->postValidLineWebhook();

        $response->assertStatus(429);
        $response->assertJson(['message' => 'Webhook rate limit exceeded.']);
        Queue::assertNotPushed(ProcessLINEWebhook::class);
        Log::shouldNotHaveReceived('warning', ['Redis blip on webhook throttle - failing open']);
    }
}

/**
 * Stands in for the app's redis cache store during an outage: counts every
 * access attempt and, like real dead Redis, throws a Predis StreamInitException
 * on any of them. Tests assert the counters stayed at zero.
 */
class ThrowingRedisStoreSpy implements Store
{
    /** Cache reads attempted on the store (get/many). */
    public int $reads = 0;

    /** Every access attempt, whatever the operation. */
    public int $touches = 0;

    public function get($key)
    {
        $this->touches++;
        $this->reads++;

        throw $this->deadRedis();
    }

    public function many(array $keys)
    {
        $this->touches++;
        $this->reads++;

        throw $this->deadRedis();
    }

    public function put($key, $value, $seconds)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function putMany(array $values, $seconds)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function increment($key, $value = 1)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function decrement($key, $value = 1)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function forever($key, $value)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function forget($key)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function touch($key, $seconds)
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function flush()
    {
        $this->touches++;

        throw $this->deadRedis();
    }

    public function getPrefix()
    {
        return '';
    }

    private function deadRedis(): StreamInitException
    {
        return new StreamInitException(
            'php_network_getaddresses: getaddrinfo for redis.railway.internal failed: Try again'
        );
    }
}
