<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\BotHITLSettings;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Services\FacebookService;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\OpenRouterService;
use App\Services\ResponseHoursService;
use App\Services\TelegramService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * B1 retirement — the old autonomous picker never runs a query again.
 *
 * The retired findEligibleConversations must return an empty collection with
 * zero queries, zero model hydration and zero network, even with rooms that
 * the old picker would have matched and the legacy toggle on. The performance
 * proof for the new picker moves to B2.
 */
class LeadRecoveryServiceEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    private LeadRecoveryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeadRecoveryService(
            Mockery::mock(LINEService::class),
            Mockery::mock(TelegramService::class),
            Mockery::mock(FacebookService::class),
            Mockery::mock(ResponseHoursService::class),
            Mockery::mock(OpenRouterService::class),
        );

        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'graph.facebook.com/*' => Http::response(['ok' => true]),
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        // Rollback the DB transaction BEFORE Mockery verifies: a violated
        // expectation must not abort the RefreshDatabase rollback.
        parent::tearDown();
        Mockery::close();
    }

    public function test_find_eligible_conversations_returns_empty_without_any_query_or_network(): void
    {
        $bot = Bot::factory()->create();

        // Settings chain with the legacy toggle ON — the retired picker must
        // not even look at it.
        $botSetting = BotSetting::create(['bot_id' => $bot->id]);
        BotHITLSettings::create([
            'bot_setting_id' => $botSetting->id,
            'lead_recovery_enabled' => true,
        ]);

        // Three rooms the old needsRecovery() scope would have matched:
        //   status='active', is_handover=false, recovery_attempts=0,
        //   last_message_at older than the timeout, last_recovery_at=null.
        Conversation::factory()->count(3)->create([
            'bot_id' => $bot->id,
            'status' => 'active',
            'is_handover' => false,
            'last_message_at' => now()->subHours(25),
            'last_recovery_at' => null,
            'recovery_attempts' => 0,
        ]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $results = $this->service->findEligibleConversations($bot);

        $this->assertCount(0, $results, 'the retired picker must return an empty collection');

        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertSame(
            0,
            $queries,
            "Expected zero queries from the retired picker, got $queries"
        );

        Http::assertNothingSent('the retired picker must not touch the network');
    }
}
