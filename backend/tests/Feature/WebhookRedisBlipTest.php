<?php

namespace Tests\Feature;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\User;
use App\Support\QueueRouter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
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

    public function test_limiter_over_limit_still_returns_429(): void
    {
        Queue::fake();
        Log::spy();

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
