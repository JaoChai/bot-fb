<?php

namespace Tests\Unit\Services\Webhook\Steps;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AutoAssignmentService;
use App\Services\LINEService;
use App\Services\ProfilePictureService;
use App\Services\TelegramService;
use App\Services\Webhook\Steps\ResolveConversationStep;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * ResolveConversationStep delegates to per-channel find-or-create logic
 * extracted verbatim from the three webhook jobs.
 *
 * sqlite test schema note (same documented limitation as Task 8):
 * migrations 2025_12_23_145424/145425 define the channel_type CHECK without
 * 'telegram' and 2025_12_31_150000 widens it only for pgsql (the sqlite
 * branch skips), so any flow that INSERTs a channel_type='telegram' row
 * fails on sqlite while prod (pgsql) is unaffected. The telegram path is
 * therefore covered at unit level via a pre-seeded conversation (the lookup
 * only issues string-param queries, so no telegram INSERT is hit) rather
 * than a full end-to-end insert.
 */
class ResolveConversationStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function bindAutoAssignmentNoAssign(): void
    {
        $autoAssignment = Mockery::mock(AutoAssignmentService::class);
        $autoAssignment->shouldReceive('assignConversation')->never();
        $this->app->instance(AutoAssignmentService::class, $autoAssignment);
    }

    /**
     * Ensure the Facebook Graph API fetch is a no-op. The existing-profile
     * branch returns before the fetch, so this guards against accidental
     * network/HTTP calls in the test.
     */
    private function mockHttpNoProfileFetch(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([], 404),
        ]);
    }

    /**
     * LINE: existing conversation is found (by external_customer_id) and
     * reused — no new conversation row, no profile fetch, no auto-assign.
     */
    public function test_line_reuses_existing_conversation(): void
    {
        $bot = Bot::factory()->active()->line()->create(['auto_handover' => false]);
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('getProfile')->never();
        $this->bindAutoAssignmentNoAssign();

        Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_existing',
            'status' => 'active',
            'is_handover' => false,
        ]);

        $ctx = new WebhookContext($bot, [
            'type' => 'message',
            'source' => ['userId' => 'U_existing'],
            'message' => ['type' => 'text', 'text' => 'hi'],
        ], 'line');

        $step = new ResolveConversationStep($lineService);
        $step->handle($ctx, fn () => null);

        $this->assertNotNull($ctx->conversation);
        $this->assertSame('U_existing', $ctx->conversation->external_customer_id);
        $this->assertFalse($ctx->metadata['is_new_conversation']);
    }

    /**
     * LINE: no existing conversation → one is created, profile is upserted
     * (getProfile is called), auto-assignment runs, and it is flagged new.
     */
    public function test_line_creates_new_conversation_with_profile_and_assignment(): void
    {
        $bot = Bot::factory()->active()->line()->create(['auto_handover' => false, 'default_flow_id' => null]);
        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('getProfile')
            ->once()
            ->andReturn(['displayName' => 'LINE User', 'pictureUrl' => null]);
        $profilePicture = Mockery::mock(ProfilePictureService::class);
        $profilePicture->shouldReceive('downloadAndStore')->once()->andReturn(null);
        $this->app->instance(ProfilePictureService::class, $profilePicture);

        $assignedUser = User::factory()->create();
        $autoAssignment = Mockery::mock(AutoAssignmentService::class);
        $autoAssignment->shouldReceive('assignConversation')->once()->andReturn($assignedUser);
        $this->app->instance(AutoAssignmentService::class, $autoAssignment);

        $ctx = new WebhookContext($bot, [
            'type' => 'message',
            'source' => ['userId' => 'U_new_line'],
            'message' => ['type' => 'text', 'text' => 'hi'],
        ], 'line');

        $step = new ResolveConversationStep($lineService);
        $step->handle($ctx, fn () => null);

        $this->assertNotNull($ctx->conversation);
        $this->assertTrue($ctx->metadata['is_new_conversation']);
        $this->assertSame($assignedUser->id, $ctx->conversation->assigned_user_id);
        $this->assertNotNull($ctx->profile);
        $this->assertSame('LINE User', $ctx->profile->display_name);
    }

    /**
     * Facebook: no existing conversation → a new one is created AND the
     * existing customer profile (matched by external_id + channel_type) is
     * found and its interaction counter bumped (findOrCreateCustomerProfile's
     * existing-profile branch — no HTTP fetch, no new profile row).
     */
    public function test_facebook_creates_conversation_and_bumps_existing_profile(): void
    {
        $bot = Bot::factory()->active()->facebook()->create(['auto_handover' => false]);
        // The create path calls assignConversation exactly once.
        $autoAssignment = Mockery::mock(AutoAssignmentService::class);
        $autoAssignment->shouldReceive('assignConversation')->once()->andReturn(null);
        $this->app->instance(AutoAssignmentService::class, $autoAssignment);

        // Pre-existing profile for the sender (no conversation yet).
        $profile = CustomerProfile::factory()->create([
            'external_id' => 'psid_1',
            'channel_type' => 'facebook',
            'interaction_count' => 4,
        ]);

        // Block the Graph API fetch (the existing-profile branch returns
        // before it, so it must not be called).
        $this->mockHttpNoProfileFetch();

        $ctx = new WebhookContext($bot, [
            'type' => 'message',
            'sender' => ['id' => 'psid_1'],
            'message' => ['text' => 'hi'],
        ], 'facebook');
        $ctx->metadata['sender_id'] = 'psid_1';

        $step = new ResolveConversationStep;
        $step->handle($ctx, fn () => null);

        $this->assertNotNull($ctx->conversation);
        $this->assertTrue($ctx->metadata['is_new_conversation']);
        $this->assertSame('psid_1', $ctx->conversation->external_customer_id);
        // The existing profile was reused and its interaction counter bumped.
        $profile->refresh();
        $this->assertSame(5, $profile->interaction_count);
        $this->assertNotNull($ctx->profile);
        $this->assertSame($profile->id, $ctx->profile->id);
    }

    /**
     * Telegram: a pre-seeded conversation is found and reused — the lookup
     * issues only string-param queries (no telegram INSERT), so the sqlite
     * channel_type constraint is not hit. Proves the step routes to the
     * telegram branch and reuses the existing conversation.
     *
     * The telegram CREATE path (which inserts a telegram conversation +
     * profile) is a documented pre-existing sqlite limitation (see class
     * docblock) and is not exercised end-to-end here.
     */
    public function test_telegram_reuses_existing_conversation(): void
    {
        $bot = Bot::factory()->active()->create(['channel_type' => 'demo', 'auto_handover' => false]);
        $telegramService = Mockery::mock(TelegramService::class);
        $telegramService->shouldReceive('getUserProfilePhoto')->never();
        $this->bindAutoAssignmentNoAssign();

        // Pre-seed a telegram conversation via raw SQL. The conversations
        // table has NO channel_type CHECK constraint on sqlite (only
        // customer_profiles does), so a telegram conversation row is insertable
        // directly — this lets the step's telegram-branch lookup match and
        // reuse it without hitting the customer_profiles constraint.
        DB::table('conversations')->insert([
            'bot_id' => $bot->id,
            'customer_profile_id' => null,
            'external_customer_id' => 'chat_9',
            'channel_type' => 'telegram',
            'status' => 'active',
            'is_handover' => 0,
            'message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ctx = new WebhookContext($bot, [
            'type' => 'message',
            'message' => ['text' => 'hi'],
        ], 'telegram');
        $ctx->metadata = [
            'chat_id' => 'chat_9',
            'chat_type' => 'private',
            'chat_title' => null,
            'user_id' => 'tg_user_7',
            'first_name' => 'Tg',
            'last_name' => 'User',
            'username' => 'tguser',
        ];

        $step = new ResolveConversationStep(null, $telegramService);
        $step->handle($ctx, fn () => null);

        // The step found the conversation by external_customer_id (chat_9)
        // and reused it — flagged as not new.
        $this->assertNotNull($ctx->conversation);
        $this->assertSame('chat_9', $ctx->conversation->external_customer_id);
        $this->assertFalse($ctx->metadata['is_new_conversation']);
        $this->assertFalse($ctx->metadata['is_handover']);
    }
}
