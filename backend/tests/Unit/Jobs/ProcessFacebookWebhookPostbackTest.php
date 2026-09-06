<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessFacebookWebhook;
use App\Models\Bot;
use App\Models\User;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Behavior test for the postback path of ProcessFacebookWebhook.
 *
 * The messages.type enum is widened with 'postback' for pgsql/mysql (prod)
 * by database/migrations/2026_08_26_000000_add_postback_to_messages_type_enum.php.
 * SQLite cannot alter CHECK constraints in place (same limitation documented
 * in 2025_12_31_150000_add_telegram_to_channel_type_constraints), so the
 * end-to-end save assertions run only on pgsql/mysql; on sqlite they skip
 * (mirroring the repo's established convention) while a companion test
 * verifies the migration itself applies cleanly on every driver.
 */
class ProcessFacebookWebhookPostbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Bot $bot;

    protected array $payload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->active()->facebook()->create([
            'user_id' => $this->user->id,
            'channel_access_token' => 'fb_test_token',
        ]);

        $this->payload = include base_path('tests/fixtures/facebook-postback.php');
    }

    /**
     * Real postback save behavior — requires the widened enum (pgsql/mysql).
     * Skipped on sqlite where the constraint cannot be altered in place.
     */
    public function test_postback_message_saves_with_postback_type_and_increments_stats(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped("messages.type widened only on pgsql/mysql — sqlite can't ALTER CHECK (mirrors 2025_12_31 convention)");
        }

        Http::fake([
            'graph.facebook.com/*' => Http::response(['ok' => true], 200),
        ]);
        Queue::fake();

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('generateAndSaveResponse')->once()->andReturn(null);

        $job = new ProcessFacebookWebhook($this->bot, $this->payload);
        $job->handle($aiService);

        // Postback saved as a user message with type='postback'
        $this->assertDatabaseHas('messages', [
            'sender' => 'user',
            'type' => 'postback',
            'content' => 'ดูเมนู',
        ]);

        // Conversation stats incremented (user message + AI bot reply counted)
        $this->assertDatabaseHas('conversations', [
            'bot_id' => $this->bot->id,
            'message_count' => 2,
            'unread_count' => 1,
        ]);

        // Bot stats incremented
        $this->bot->refresh();
        $this->assertSame(2, (int) $this->bot->total_messages);
        $this->assertNotNull($this->bot->last_active_at);
    }

    /**
     * V2 twin of the stats test above — same payload and assertions, routed
     * through the shared WebhookPipeline (WebhookPipelineV2Flag).
     * Skipped on sqlite (same constraint limitation as above).
     */
    public function test_postback_v2_path_matches_legacy_stats(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped("messages.type widened only on pgsql/mysql — sqlite can't ALTER CHECK (mirrors 2025_12_31 convention)");
        }

        config(['webhook_pipeline_v2.enabled' => true, 'webhook_pipeline_v2.bot_ids' => [(string) $this->bot->id]]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['ok' => true], 200),
        ]);
        Queue::fake();

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('generateAndSaveResponse')->once()->andReturn(null);

        $job = new ProcessFacebookWebhook($this->bot, $this->payload);
        $job->handle($aiService);

        // Postback saved as a user message with type='postback'
        $this->assertDatabaseHas('messages', [
            'sender' => 'user',
            'type' => 'postback',
            'content' => 'ดูเมนู',
        ]);

        // Conversation stats incremented (user message + AI bot reply counted)
        $this->assertDatabaseHas('conversations', [
            'bot_id' => $this->bot->id,
            'message_count' => 2,
            'unread_count' => 1,
        ]);

        // Bot stats incremented
        $this->bot->refresh();
        $this->assertSame(2, (int) $this->bot->total_messages);
        $this->assertNotNull($this->bot->last_active_at);
    }

    /**
     * Inactive bots still save the postback message — no AI reply.
     * Skipped on sqlite (same constraint limitation as above).
     */
    public function test_postback_message_saves_for_inactive_bot_without_ai_response(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped("messages.type widened only on pgsql/mysql — sqlite can't ALTER CHECK (mirrors 2025_12_31 convention)");
        }

        Http::fake([
            'graph.facebook.com/*' => Http::response(['ok' => true], 200),
        ]);
        Queue::fake();

        $this->bot->update(['status' => 'paused']);

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldNotReceive('generateAndSaveResponse');

        $job = new ProcessFacebookWebhook($this->bot, $this->payload);
        $job->handle($aiService);

        // Postback still saves for an inactive bot — just no AI reply
        $this->assertDatabaseHas('messages', [
            'sender' => 'user',
            'type' => 'postback',
            'content' => 'ดูเมนู',
        ]);

        $this->assertDatabaseHas('conversations', [
            'bot_id' => $this->bot->id,
            'message_count' => 1,
        ]);

        $this->bot->refresh();
        $this->assertSame(1, (int) $this->bot->total_messages);
    }

    /**
     * The enum-widening migration must apply (and roll back) cleanly on
     * every driver — a no-op on sqlite, a real ALTER on pgsql/mysql.
     */
    public function test_postback_enum_migration_applies_cleanly(): void
    {
        $migration = require database_path('migrations/2026_08_26_000000_add_postback_to_messages_type_enum.php');

        // The migration runs inside RefreshDatabase's transaction; a no-op
        // (sqlite) or DDL statement (pgsql/mysql) must not throw.
        $migration->up();
        $migration->down();
        $migration->up();

        // If we got here without a QueryException, the migration is sound
        // for this driver. Reversing once more leaves the schema as up().
        $this->addToAssertionCount(1);
    }
}
