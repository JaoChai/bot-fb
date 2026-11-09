<?php

namespace Tests\Feature;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\User;
use App\Support\QueueRouter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DeadRedisStoreSpy;
use Tests\TestCase;

/**
 * Spec item 4: under cache.default=failover the webhook throttle must keep
 * enforcing (and counting) even while Redis is down. The cause: the bound
 * RateLimiter store is a FailoverStore — NOT a RedisStore — so the throttle's
 * skip branch does not apply, and the FailoverStore lands every limiter read
 * and hit on the database store when the redis store throws. Throttling must
 * NOT be disabled for every failover config.
 */
class WebhookThrottleFailoverTest extends TestCase
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

    /**
     * Rebuild the app's RateLimiter singleton on the failover store — what
     * boot does in prod once CACHE_STORE=failover — with the redis sub-store
     * replaced by a spy that throws like dead Redis.
     */
    protected function failoverLimiterWithDeadRedis(): DeadRedisStoreSpy
    {
        $spy = new DeadRedisStoreSpy;

        config(['cache.default' => 'failover']);

        $cacheManager = $this->app['cache'];
        $cacheManager->extend('redis', fn () => new Repository($spy));
        $cacheManager->forgetDriver('failover');

        $boundAtBoot = $this->app->make(RateLimiter::class);

        $this->app->forgetInstance(RateLimiter::class);

        $rebuilt = $this->app->make(RateLimiter::class);
        $rebuilt->for('webhook', $boundAtBoot->limiter('webhook'));

        return $spy;
    }

    protected function gateDown(): void
    {
        Cache::store('file')->put('redis_health:up', false, 10);
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
            'X-Line-Signature' => base64_encode(hash_hmac('sha256', $body, $this->channelSecret, true)),
        ]);
    }

    /**
     * The gate is already down, the limiter is bound to the FailoverStore:
     * the skip branch must NOT apply (FailoverStore is not a RedisStore), so
     * the limiter keeps counting through the database store.
     */
    public function test_throttling_is_not_disabled_for_failover_config_even_when_gate_is_down(): void
    {
        Queue::fake();
        Log::spy();

        $spy = $this->failoverLimiterWithDeadRedis();
        $this->gateDown();

        $request = Request::create(config('app.url').'/api/webhook/x', 'POST');
        $limit = app(RateLimiter::class)->limiter('webhook')($request);
        $key = md5('webhook'.$limit->key);

        for ($i = 0; $i < $limit->maxAttempts; $i++) {
            app(RateLimiter::class)->hit($key, $limit->decaySeconds);
        }

        $response = $this->postValidLineWebhook();

        $response->assertStatus(429);
        $this->assertGreaterThan(0, $spy->touches, 'the dead redis store must be attempted (and failed over), not skipped by config sniffing');
        $this->assertGreaterThanOrEqual(
            $limit->maxAttempts,
            app(RateLimiter::class)->attempts($key),
            'the limiter must keep counting while Redis is down'
        );
        Queue::assertNotPushed(ProcessLINEWebhook::class);
        Log::shouldNotHaveReceived('warning', ['Redis blip on webhook throttle - failing open']);
    }

    /**
     * The blip shape: gate up, redis store dies mid-request — the limiter
     * reads/hits fail over to the database store, the request never throws.
     */
    public function test_failover_webhook_never_throws_and_still_counts_while_redis_throws(): void
    {
        Queue::fake();
        Log::spy();

        $spy = $this->failoverLimiterWithDeadRedis();
        // Deterministic on every host: this machine has a live Redis on
        // 127.0.0.1:6379, CI has none. The incident shape is "the gate
        // believed Redis was up, the store died anyway".
        $this->gateUp();

        $response = $this->postValidLineWebhook();

        $response->assertStatus(200);
        $this->assertGreaterThan(0, $spy->touches, 'the limiter must have attempted the dead redis store');
        Queue::assertPushed(ProcessLINEWebhook::class, 1);
        Queue::assertPushed(ProcessLINEWebhook::class, function ($job) {
            // The queue name is gate-independent. The connection is NOT
            // asserted: under failover the throttle never throws, so it never
            // calls markDown() and the dispatch connection keeps following
            // the gate verdict exactly like on main (null when up,
            // 'database' when down).
            return $job->queue === QueueRouter::llmQueue();
        });
        Log::shouldNotHaveReceived('warning', ['Redis blip on webhook throttle - failing open']);
    }

    protected function gateUp(): void
    {
        Cache::store('file')->put('redis_health:up', true, 10);
    }
}
