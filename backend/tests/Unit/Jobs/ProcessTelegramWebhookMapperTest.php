<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessTelegramWebhook;
use App\Models\Bot;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AIService;
use App\Services\TelegramService;
use App\Services\Webhook\Channels\Telegram\TelegramEventMapper;
use App\Services\Webhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Characterization tests for the Telegram job rewired through
 * TelegramEventMapper + WebhookContext (Task 8, fix round).
 *
 * UNIT LEVEL ONLY BY RULING: the full TG job flow cannot run end-to-end on
 * the sqlite test schema — migrations 2025_12_23_145424/145425 define the
 * channel_type CHECK constraint without 'telegram' and
 * 2025_12_31_150000 widens it only for pgsql (the sqlite branch skips), so
 * any flow reaching a channel_type='telegram' insert fails on sqlite while
 * prod (pgsql) is unaffected. Do NOT drive the full job against sqlite for
 * telegram-channel flows here; the media download path deliberately has NO
 * new e2e test (documented pre-existing limitation).
 *
 * What IS covered at unit level:
 *  - the mapper→job boundary that does NOT hit a channel_type insert: the
 *    early-return ignore semantics (mapper map() returns null for
 *    my_chat_member and chat-id-less updates → the job returns before its
 *    conversation lookup/create), exercised through handle() with the real
 *    fixtures from tests/fixtures/telegram-*.php
 *  - the null-return routing: every map() null path (unknown type, no
 *    chat_id) routes to the same early return — Conversation and
 *    CustomerProfile are untouched in both cases
 */
class ProcessTelegramWebhookMapperTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->active()->create([
            'user_id' => $this->user->id,
            'channel_type' => 'telegram',
            'channel_access_token' => 'tg_test_token',
        ]);
    }

    public function test_my_chat_member_update_is_ignored_without_database_writes(): void
    {
        // Fixture update has NO message/edited_message/channel_post →
        // mapper map() returns null (type 'unknown') → the job must return
        // early, before the conversation lookup/create (no DB writes).
        $update = include base_path('tests/fixtures/telegram-my-chat-member.php');

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldNotReceive('generateAndSaveResponse');

        $job = new ProcessTelegramWebhook($this->bot, $update);
        $job->handle(new TelegramService(), $aiService);

        $this->assertSame(0, \App\Models\Conversation::count(), 'Ignored updates must not create conversations');
        $this->assertSame(0, CustomerProfile::count(), 'Ignored updates must not create customer profiles');
    }

    public function test_message_update_without_chat_id_is_ignored_without_database_writes(): void
    {
        // A message-shaped update with an empty chat id: parseUpdate()
        // yields chat_id '' → mapper map() returns null → same early return.
        $update = include base_path('tests/fixtures/telegram-text-message.php');
        $update['update_id'] = 2001;
        $update['message']['message_id'] = 601;
        $update['message']['chat']['id'] = '';

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldNotReceive('generateAndSaveResponse');

        $job = new ProcessTelegramWebhook($this->bot, $update);
        $job->handle(new TelegramService(), $aiService);

        $this->assertSame(0, \App\Models\Conversation::count(), 'Ignored updates must not create conversations');
        $this->assertSame(0, CustomerProfile::count(), 'Ignored updates must not create customer profiles');
    }

    public function test_text_message_maps_to_context_used_by_job(): void
    {
        // Mapper→job integration boundary (no channel_type insert involved):
        // the same map() call the job performs yields a WebhookContext whose
        // values are exactly what the rewired processUpdate() reads from the
        // context (chat_id / message_id / text / message type / user).
        $update = include base_path('tests/fixtures/telegram-text-message.php');

        $ctx = app(TelegramEventMapper::class)->map($update, $this->bot);

        $this->assertInstanceOf(WebhookContext::class, $ctx);
        $this->assertSame($this->bot, $ctx->bot);
        $this->assertSame('telegram', $ctx->channelType);
        $this->assertSame('message', $ctx->eventType());
        $this->assertSame('text', $ctx->messageType());
        $this->assertSame('สวัสดีครับ อยากดูสินค้า', $ctx->text());
        $this->assertSame('777', $ctx->metadata['chat_id']);
        $this->assertSame('777', $ctx->metadata['user_id']);
        $this->assertSame('501', $ctx->metadata['message_id']);
        $this->assertSame(null, $ctx->metadata['reply_to_message_id']);
    }
}
