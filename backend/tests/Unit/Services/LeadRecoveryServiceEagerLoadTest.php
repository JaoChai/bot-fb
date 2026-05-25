<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\FacebookService;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\OpenRouterService;
use App\Services\ResponseHoursService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Regression test for Sprint 1 #11 — findEligibleConversations must eager-load
 * the bot relation, otherwise processRecovery triggers a lazy-load per row.
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
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_find_eligible_conversations_eager_loads_bot(): void
    {
        $bot = Bot::factory()->create();

        // Create 3 conversations that satisfy needsRecovery():
        //   status='active', is_handover=false, recovery_attempts=0 (DB default),
        //   last_message_at older than the default 24h timeout,
        //   last_recovery_at=null.
        Conversation::factory()->count(3)->create([
            'bot_id' => $bot->id,
            'last_message_at' => now()->subHours(25),
            'last_recovery_at' => null,
        ]);

        $results = $this->service->findEligibleConversations($bot);

        // Sanity: result set must be non-empty, otherwise the test would
        // trivially pass without proving anything.
        $this->assertCount(3, $results, 'Expected 3 eligible conversations; factory setup may be wrong.');

        // Register the listener AFTER the initial fetch so we only capture
        // queries fired while iterating the already-fetched collection.
        $queriesAfterFetch = 0;
        DB::listen(function () use (&$queriesAfterFetch) {
            $queriesAfterFetch++;
        });

        foreach ($results as $conversation) {
            // Touch the relation — must already be loaded (no new query).
            $_ = $conversation->bot?->id;
        }

        $this->assertSame(
            0,
            $queriesAfterFetch,
            "Expected zero queries when iterating eager-loaded bot relation, got $queriesAfterFetch"
        );
    }
}
