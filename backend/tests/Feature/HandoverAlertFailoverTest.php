<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\User;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\DeadRedisStoreSpy;
use Tests\TestCase;

/**
 * The 2026-11-09 Sentry incident: `handover:alert-unanswered` (scheduled
 * every minute, output to /dev/null) died on `Cache::get($cacheKey)` when
 * Redis stalled. With cache.default=failover and a throwing redis store the
 * command must exit 0 and read/write the dedupe key through the database
 * store.
 */
class HandoverAlertFailoverTest extends TestCase
{
    use RefreshDatabase;

    private const CACHE_KEY = 'handover_alert:rf';

    public function test_handover_alert_command_exits_zero_and_dedupes_via_database_when_failover_redis_throws(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 777],
        ])]);

        $spy = new DeadRedisStoreSpy;
        $cacheManager = $this->app['cache'];
        $cacheManager->extend('redis', fn () => new Repository($spy));
        $cacheManager->forgetDriver('failover');
        config(['cache.default' => 'failover']);

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id, 'is_default' => true]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งเตือน',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'TOK', 'chat_id' => '999'],
        ]);

        $conversation = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'is_handover' => true,
            'current_flow_id' => $flow->id,
        ]);
        // Unanswered customer message, past the default 15-minute threshold,
        // inside the 24-hour lookback window.
        $message = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'created_at' => now()->subMinutes(20),
        ]);

        $this->artisan('handover:alert-unanswered')
            ->expectsOutputToContain('handover alerts sent: 1')
            ->assertExitCode(0);

        $this->assertGreaterThanOrEqual(2, $spy->touches, 'the dead redis store must be attempted before failing over');

        // The dedupe stamp must live in the DATABASE store, not silently in
        // redis: the next run must see it and not re-alert.
        $this->assertSame(
            $message->id,
            Cache::store('database')->get("handover_alert:{$conversation->id}"),
            'the dedupe key must be written through the database store'
        );

        $this->artisan('handover:alert-unanswered')
            ->expectsOutputToContain('handover alerts sent: 0')
            ->assertExitCode(0);
    }
}
