<?php

namespace Tests\Feature;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\User;
use App\Support\QueueRouter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DeadRedisStoreSpy;
use Tests\TestCase;

/**
 * Spec item 4 + review round 1 item 1 (option b): under cache.default=failover
 * the gate-down swap must rebind the RateLimiter singleton onto the database
 * store, so the webhook throttle keeps counting WITHOUT touching the dead
 * Redis store the FailoverStore fronts (measured at head c13082fa: 24s per
 * webhook while Redis was paused, because every limiter op tried Redis first).
 * Throttling must NOT be disabled for the failover config: with a healthy
 * redis-led store and the limiter exceeded, the response is still 429.
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
     * replaced by the given spy store.
     */
    protected function failoverLimiterWithSpyStore(Store $spy): Store
    {
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

    protected function failoverLimiterWithDeadRedis(): DeadRedisStoreSpy
    {
        return $this->failoverLimiterWithSpyStore(new DeadRedisStoreSpy);
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
     * The gate is already down and the limiter is bound to the FailoverStore
     * led by dead Redis: the gate-down swap must rebind the limiter onto the
     * database store, so the REQUEST never touches the dead redis store and
     * still counts — 429 when over the limit. (The seeding hits before the
     * request legitimately attempt redis first through the FailoverStore;
     * what must stay at zero is the dead-store work the request itself does.)
     */
    public function test_gate_down_request_never_touches_dead_redis_and_keeps_counting_429(): void
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
        $touchesBeforeRequest = $spy->touches;

        $response = $this->postValidLineWebhook();

        $response->assertStatus(429);
        $this->assertSame(0, $spy->touches - $touchesBeforeRequest, 'the request must not touch the dead redis store: the gate-down swap must rebind the limiter onto the database store');
        $this->assertGreaterThanOrEqual(
            $limit->maxAttempts,
            app(RateLimiter::class)->attempts($key),
            'the limiter must keep counting while Redis is down'
        );
        Queue::assertNotPushed(ProcessLINEWebhook::class);
        Log::shouldNotHaveReceived('warning', ['Redis blip on webhook throttle - failing open']);
    }

    /**
     * The acceptance case as worded by the reviewer: failover config, Redis
     * UP (the failover store's first store WORKS), limiter genuinely exceeded
     * → 429. Throttling is never blanket-disabled for a failover config.
     */
    public function test_429_when_failover_redis_store_is_healthy_and_limiter_exceeded(): void
    {
        Queue::fake();
        Log::spy();

        $spy = $this->failoverLimiterWithSpyStore(new WorkingRedisLedStoreSpy);
        // Deterministic on every host (CI has no live Redis): the incident
        // premise is "the gate believed Redis was up".
        Cache::store('file')->put('redis_health:up', true, 10);

        $request = Request::create(config('app.url').'/api/webhook/x', 'POST');
        $limit = app(RateLimiter::class)->limiter('webhook')($request);
        $key = md5('webhook'.$limit->key);

        for ($i = 0; $i < $limit->maxAttempts; $i++) {
            app(RateLimiter::class)->hit($key, $limit->decaySeconds);
        }
        $touchesBeforeRequest = $spy->touches;

        $response = $this->postValidLineWebhook();

        $response->assertStatus(429);
        $response->assertJson(['message' => 'Webhook rate limit exceeded.']);
        $this->assertGreaterThan($touchesBeforeRequest, $spy->touches, 'the healthy failover limiter must keep counting through its redis-led store');
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

/**
 * A WORKING stand-in for the app's redis cache store: every access is counted
 * but served (from an in-memory array), like healthy Redis. Used as the FIRST
 * store of the failover cache config so a test can prove the failover
 * limiter genuinely counts through redis while it is up.
 */
class WorkingRedisLedStoreSpy extends ArrayStore
{
    /** Every access attempt, whatever the operation. */
    public int $touches = 0;

    public function get($key)
    {
        $this->touches++;

        return parent::get($key);
    }

    public function many(array $keys)
    {
        $this->touches++;

        return parent::many($keys);
    }

    public function add($key, $value, $seconds)
    {
        $this->touches++;

        // ArrayStore has no native add(); implement add-once semantics here
        // (Repository::add prefers a store-level add when one exists).
        if (parent::get($key) === null) {
            parent::put($key, $value, $seconds);

            return true;
        }

        return false;
    }

    public function put($key, $value, $seconds)
    {
        $this->touches++;

        return parent::put($key, $value, $seconds);
    }

    public function increment($key, $value = 1)
    {
        $this->touches++;

        return parent::increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        $this->touches++;

        return parent::decrement($key, $value);
    }

    public function forever($key, $value)
    {
        $this->touches++;

        return parent::forever($key, $value);
    }

    public function forget($key)
    {
        $this->touches++;

        return parent::forget($key);
    }

    public function getPrefix()
    {
        return '';
    }
}
