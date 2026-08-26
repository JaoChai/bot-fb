<?php

namespace Tests\Unit\Services\Webhook\LINE;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\User;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\ResponseHoursService;
use App\Services\StickerReplyService;
use App\Services\Webhook\Channels\LINE\NonTextHandler;
use App\Services\Webhook\Channels\LINE\StickerHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Characterization test for the LINE non-text handling extraction (Task 4).
 *
 * Pins the EXACT side effects the NonTextHandler produces for canned events:
 *
 *   location  → user Message row (content '[ตำแหน่ง] ...', type=location,
 *               no media, webhook_event_id persisted), stored silently
 *   sticker   → user Message row (content '[สติกเกอร์]',
 *               media_url = LINE CDN sticker URL) + StickerHandler delegation
 *   file      → user Message row (content '[ไฟล์]', media_url from
 *               downloadAndStoreFile)
 *   image     → user Message row (content '[รูปภาพ]') + VisionHandler
 *               delegation (image branch left to VisionHandler)
 *   redelivery of an already-processed webhook_event_id → no duplicate row
 *   outside response hours → user Message saved + offline reply via
 *               LINEService::replyWithFallback (getOfflineMessage)
 */
class NonTextHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build the scenario: active bot, a line conversation for the given
     * external user, and a NonTextHandler instance.
     *
     * @return array{bot: Bot, conversation: Conversation, handler: NonTextHandler}
     */
    private function buildScenario(string $userId): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'status' => 'active',
            'name' => 'NonTextPinBot',
            'channel_access_token' => 'test_access_token',
            'channel_secret' => 'test_secret',
        ]);

        $profile = CustomerProfile::factory()->create([
            'external_id' => $userId,
            'channel_type' => 'line',
        ]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $profile->id,
            'external_customer_id' => $userId,
            'channel_type' => 'line',
            'status' => 'active',
            'is_handover' => false,
            'message_count' => 0,
        ]);

        $handler = new NonTextHandler(
            $bot,
            app(ResponseHoursService::class),
            app(LeadRecoveryService::class),
            fn (string $uid, LINEService $ls) => $this->createConversation($bot, $uid, $ls),
            fn (Conversation $conv, int $lastMessageId) => $this->updateStats($conv, $lastMessageId),
            new StickerHandler($bot, app(StickerReplyService::class)),
        );

        return [
            'bot' => $bot,
            'conversation' => $conversation,
            'handler' => $handler,
        ];
    }

    /**
     * Inline the job's createNewConversation logic (shared helper, stays in job).
     */
    private function createConversation(Bot $bot, string $userId, LINEService $lineService): Conversation
    {
        $profile = CustomerProfile::updateOrCreate(
            ['external_id' => $userId, 'channel_type' => 'line'],
            ['display_name' => null, 'last_interaction_at' => now()]
        );

        return Conversation::create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $profile->id,
            'external_customer_id' => $userId,
            'channel_type' => 'line',
            'status' => 'active',
            'is_handover' => false,
            'message_count' => 0,
        ]);
    }

    /**
     * Inline the job's updateStatsForUserMessageOnly logic.
     */
    private function updateStats(Conversation $conversation, int $lastMessageId): void
    {
        $conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
            'last_message_id' => $lastMessageId,
        ]);
    }

    /**
     * A LINEService mock preloaded with the canned event's extraction
     * results (the shape extractMessage() returns for the fixture).
     */
    private function lineServiceMock(array $event, array $messageData, array $overrides = []): LINEService
    {
        $mock = Mockery::mock(LINEService::class);
        $mock->shouldReceive('extractUserId')->andReturn($event['source']['userId'] ?? null);
        $mock->shouldReceive('extractReplyToken')->andReturn($event['replyToken'] ?? null);
        $mock->shouldReceive('extractMessage')->andReturn($messageData);
        $mock->shouldReceive('extractWebhookEventId')->andReturn($event['webhookEventId'] ?? null);
        $mock->shouldReceive('extractEventTimestamp')->andReturn(isset($event['timestamp']) ? (int) $event['timestamp'] : null);
        $mock->shouldReceive('isRedelivery')->andReturn($event['deliveryContext']['isRedelivery'] ?? false);
        $mock->shouldReceive('showLoadingIndicator')->zeroOrMoreTimes()->andReturn(true);
        $mock->shouldReceive('generateRetryKey')->andReturnUsing(fn () => (string) Str::uuid());

        foreach ($overrides as $method => $expectation) {
            $expectation($mock);
        }

        return $mock;
    }

    private function responseHoursMock(bool $allowed = true): ResponseHoursService
    {
        $mock = Mockery::mock(ResponseHoursService::class);
        $mock->shouldReceive('checkResponseHours')->andReturn([
            'allowed' => $allowed,
            'status' => $allowed ? 'active' : 'closed',
            'current_time' => now()->toDateTimeString(),
        ]);

        return $mock;
    }

    // ------------------------------------------------------------------
    // Location — stored silently (no AI, no reply)
    // ------------------------------------------------------------------

    public function test_location_event_saves_user_message_and_stays_silent(): void
    {
        $event = include base_path('tests/fixtures/line-location-event.php');
        $messageData = [
            'id' => 'loc_msg_001',
            'type' => 'location',
            'text' => null,
            'content_provider' => null,
            'sticker_id' => null,
            'package_id' => null,
            'latitude' => '13.7563',
            'longitude' => '100.5018',
            'address' => 'Bangkok',
            'duration' => null,
        ];

        $scenario = $this->buildScenario('U_loc_user');
        $lineService = $this->lineServiceMock($event, $messageData, [
            'replyWithFallback' => fn (LINEService $m) => $m->shouldNotReceive('replyWithFallback'),
        ]);

        $scenario['handler']->handle($lineService, $event);

        $msg = $scenario['conversation']->messages()->where('sender', 'user')->first();
        $this->assertNotNull($msg);
        $this->assertSame('[ตำแหน่ง] Bangkok (13.7563, 100.5018)', $msg->content);
        $this->assertSame('location', $msg->type);
        $this->assertNull($msg->media_url);
        $this->assertSame('loc_msg_001', $msg->external_message_id);
        $this->assertSame('webhook_loc_001', $msg->webhook_event_id);
        $this->assertFalse((bool) $msg->is_redelivery);

        // Silent: no bot row, conversation stats reflect the single user message
        $this->assertNull($scenario['conversation']->messages()->where('sender', 'bot')->first());
        $fresh = $scenario['conversation']->fresh();
        $this->assertSame(1, $fresh->message_count);
        $this->assertSame(1, $fresh->unread_count);
    }

    // ------------------------------------------------------------------
    // Sticker — user row with CDN media_url + StickerHandler delegation
    // ------------------------------------------------------------------

    public function test_sticker_event_saves_user_row_with_cdn_url_and_delegates_to_sticker_handler(): void
    {
        $event = include base_path('tests/fixtures/line-sticker-event.php');
        $messageData = [
            'id' => 'stk_msg_001',
            'type' => 'sticker',
            'sticker_id' => '999',
            'package_id' => '777',
        ];

        $scenario = $this->buildScenario('U_stk_user');
        $bot = $scenario['bot'];
        BotSetting::create([
            'bot_id' => $bot->id,
            'reply_sticker_enabled' => true,
            'reply_sticker_mode' => 'static',
            'reply_sticker_message' => 'ได้รับสติกเกอร์แล้วค่ะ',
        ]);

        // Build the handler with the stubbed StickerHandler so the sticker
        // branch (nonTextHandler->stickerHandler) uses the mocked service.
        $stickerService = Mockery::mock(StickerReplyService::class);
        $stickerService->shouldReceive('generateReply')->once()->andReturn('ได้รับสติกเกอร์แล้วค่ะ');
        $stickerHandler = new StickerHandler($bot, $stickerService);

        // Rebuild the scenario handler with the stubbed StickerHandler so the
        // sticker branch uses the mocked service.
        $handler = new NonTextHandler(
            $bot,
            app(ResponseHoursService::class),
            app(LeadRecoveryService::class),
            fn (string $uid, LINEService $ls) => $this->createConversation($bot, $uid, $ls),
            fn (Conversation $conv, int $lastMessageId) => $this->updateStats($conv, $lastMessageId),
            $stickerHandler,
        );

        $lineService = $this->lineServiceMock($event, $messageData, [
            'replyWithFallback' => fn (LINEService $m) => $m->shouldReceive('replyWithFallback')
                ->once()
                ->with(Mockery::type('App\Models\Bot'), 'reply_token_stk_1', 'U_stk_user', ['ได้รับสติกเกอร์แล้วค่ะ'], Mockery::type('string'))
                ->andReturn(['method' => 'reply', 'success' => true]),
        ]);

        $handler->handle($lineService, $event);

        // User row: content placeholder + LINE CDN sticker URL as media
        $userMsg = $scenario['conversation']->messages()->where('sender', 'user')->first();
        $this->assertNotNull($userMsg);
        $this->assertSame('[สติกเกอร์]', $userMsg->content);
        $this->assertSame('sticker', $userMsg->type);
        $this->assertSame('https://stickershop.line-scdn.net/stickershop/v1/sticker/999/android/sticker.png', $userMsg->media_url);
        $this->assertSame('image/png', $userMsg->media_type);

        // Bot reply row with sticker_reply metadata
        $botMsg = $scenario['conversation']->messages()->where('sender', 'bot')->first();
        $this->assertNotNull($botMsg);
        $this->assertSame('ได้รับสติกเกอร์แล้วค่ะ', $botMsg->content);
        $this->assertTrue($botMsg->metadata['sticker_reply']);
        $this->assertSame('static', $botMsg->metadata['sticker_mode']);
    }

    // ------------------------------------------------------------------
    // File — media downloaded and stored
    // ------------------------------------------------------------------

    public function test_file_event_downloads_media_and_saves_row_silently(): void
    {
        $event = [
            'type' => 'message',
            'replyToken' => 'reply_token_file_1',
            'source' => ['type' => 'user', 'userId' => 'U_file_user'],
            'message' => ['id' => 'file_msg_001', 'type' => 'file'],
            'webhookEventId' => 'webhook_file_001',
            'deliveryContext' => ['isRedelivery' => false],
            'timestamp' => 1700000000000,
        ];
        $messageData = ['id' => 'file_msg_001', 'type' => 'file'];

        $scenario = $this->buildScenario('U_file_user');
        $lineService = $this->lineServiceMock($event, $messageData, [
            'downloadAndStoreFile' => fn (LINEService $m) => $m->shouldReceive('downloadAndStoreFile')
                ->once()
                ->andReturn(['url' => 'https://cdn.example.com/file_msg_001.pdf', 'path' => 'line/1/file.pdf', 'mime_type' => 'application/octet-stream']),
            'replyWithFallback' => fn (LINEService $m) => $m->shouldNotReceive('replyWithFallback'),
        ]);

        $scenario['handler']->handle($lineService, $event);

        $msg = $scenario['conversation']->messages()->where('sender', 'user')->first();
        $this->assertNotNull($msg);
        $this->assertSame('[ไฟล์]', $msg->content);
        $this->assertSame('file', $msg->type);
        $this->assertSame('https://cdn.example.com/file_msg_001.pdf', $msg->media_url);
        $this->assertSame('application/octet-stream', $msg->media_type);
        $this->assertNull($scenario['conversation']->messages()->where('sender', 'bot')->first());
    }

    // ------------------------------------------------------------------
    // Image — user row saved + VisionHandler delegation (image branch left)
    // ------------------------------------------------------------------

    public function test_image_event_saves_user_row_and_delegates_to_vision_handler(): void
    {
        $event = include base_path('tests/fixtures/line-image-event.php');
        $messageData = [
            'id' => 'img_msg_001',
            'type' => 'image',
            'text' => null,
        ];

        $scenario = $this->buildScenario('U_img_user');
        $lineService = $this->lineServiceMock($event, $messageData, [
            'downloadAndStoreFile' => fn (LINEService $m) => $m->shouldReceive('downloadAndStoreFile')
                ->once()
                ->andReturn(['url' => 'https://cdn.example.com/img_msg_001.jpg', 'path' => 'line/1/img.jpg', 'mime_type' => 'image/jpeg']),
            'replyWithFallback' => fn (LINEService $m) => $m->shouldNotReceive('replyWithFallback'),
        ]);

        $scenario['handler']->handle($lineService, $event);

        $userMsg = $scenario['conversation']->messages()->where('sender', 'user')->first();
        $this->assertNotNull($userMsg);
        $this->assertSame('[รูปภาพ]', $userMsg->content);
        $this->assertSame('image', $userMsg->type);
        $this->assertSame('https://cdn.example.com/img_msg_001.jpg', $userMsg->media_url);
        $this->assertSame('image/jpeg', $userMsg->media_type);

        // Vision branch: no sticker reply, no other bot row from this path —
        // vision side effects (LLM call) are pinned by VisionHandlerTest.
        $this->assertNull($scenario['conversation']->messages()->where('sender', 'bot')->first());
    }

    // ------------------------------------------------------------------
    // Redelivery — already-processed webhook_event_id is skipped
    // ------------------------------------------------------------------

    public function test_redelivered_event_with_existing_webhook_event_id_is_skipped(): void
    {
        $event = include base_path('tests/fixtures/line-location-event.php');
        $event['deliveryContext'] = ['isRedelivery' => true];
        $messageData = [
            'id' => 'loc_msg_001',
            'type' => 'location',
            'latitude' => '13.7563',
            'longitude' => '100.5018',
            'address' => 'Bangkok',
        ];

        $scenario = $this->buildScenario('U_loc_user');

        // Simulate a previous delivery having persisted this webhook_event_id
        Message::create([
            'conversation_id' => $scenario['conversation']->id,
            'sender' => 'user',
            'content' => '[เดิม]',
            'type' => 'location',
            'webhook_event_id' => 'webhook_loc_001',
        ]);

        $lineService = $this->lineServiceMock($event, $messageData, [
            'replyWithFallback' => fn (LINEService $m) => $m->shouldNotReceive('replyWithFallback'),
        ]);

        $scenario['handler']->handle($lineService, $event);

        $this->assertSame(1, $scenario['conversation']->messages()->count());
    }

    // ------------------------------------------------------------------
    // Outside response hours — message saved, offline reply sent
    // ------------------------------------------------------------------

    public function test_outside_response_hours_saves_message_and_sends_offline_reply(): void
    {
        $event = include base_path('tests/fixtures/line-location-event.php');
        $messageData = [
            'id' => 'loc_msg_001',
            'type' => 'location',
            'latitude' => '13.7563',
            'longitude' => '100.5018',
            'address' => 'Bangkok',
        ];

        $scenario = $this->buildScenario('U_loc_user');
        $bot = $scenario['bot'];
        BotSetting::create([
            'bot_id' => $bot->id,
            'offline_message' => 'จะตอบกลับในเวลานะครับ',
        ]);

        $responseHours = Mockery::mock(ResponseHoursService::class);
        $responseHours->shouldReceive('checkResponseHours')->andReturn([
            'allowed' => false,
            'status' => 'closed',
            'current_time' => now()->toDateTimeString(),
        ]);
        $responseHours->shouldReceive('getOfflineMessage')->andReturn('จะตอบกลับในเวลานะครับ');

        // Rebuild the handler with the mocked response hours service
        $handler = new NonTextHandler(
            $bot,
            $responseHours,
            app(LeadRecoveryService::class),
            fn (string $uid, LINEService $ls) => $this->createConversation($bot, $uid, $ls),
            fn (Conversation $conv, int $lastMessageId) => $this->updateStats($conv, $lastMessageId),
            new StickerHandler($bot, app(StickerReplyService::class)),
        );

        $lineService = $this->lineServiceMock($event, $messageData, [
            'replyWithFallback' => fn (LINEService $m) => $m->shouldReceive('replyWithFallback')
                ->once()
                ->with(Mockery::type('App\Models\Bot'), 'reply_token_loc_1', 'U_loc_user', ['จะตอบกลับในเวลานะครับ'], Mockery::type('string'))
                ->andReturn(['method' => 'reply', 'success' => true]),
        ]);

        $handler->handle($lineService, $event);

        // User message is still saved before the hours gate
        $userMsg = $scenario['conversation']->messages()->where('sender', 'user')->first();
        $this->assertNotNull($userMsg);
        $this->assertSame('[ตำแหน่ง] Bangkok (13.7563, 100.5018)', $userMsg->content);
        $this->assertNull($scenario['conversation']->messages()->where('sender', 'bot')->first());
    }
}
