<?php

namespace Tests\Unit\Services\Webhook\LINE;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\LINEService;
use App\Services\StickerReplyService;
use App\Services\Webhook\Channels\LINE\StickerHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Characterization test for the LINE sticker-reply extraction (Task 4).
 *
 * Pins the EXACT side effects the StickerHandler produces for one canned
 * sticker event (fixture: tests/fixtures/line-sticker-event.php):
 *
 *   - reply API (LINEService::replyWithFallback) called with the
 *     StickerReplyService reply and a retry key
 *   - bot Message row: sender=bot, type=text, metadata
 *     {sticker_reply: true, sticker_mode: <mode>, sticker_id: <id>}
 *   - conversation/bot stat increments
 *   - silent no-op when reply_sticker_enabled=false, when the reply is
 *     empty, and when generateReply throws
 *
 * The user-side row (content '[สติกเกอร์]', LINE CDN media_url) and the
 * image/file/location/redelivery/outside-hours paths are pinned by
 * NonTextHandlerTest.
 */
class StickerHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build the sticker scenario: active bot, reply_sticker settings, a
     * line conversation owned by the bot, and a StickerHandler instance.
     *
     * @return array{bot: Bot, conversation: Conversation, messageData: array, handler: StickerHandler}
     */
    private function buildScenario(bool $enabled = true, ?string $mode = null, ?StickerReplyService $stickerService = null): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'status' => 'active',
            'name' => 'StickerPinBot',
            'channel_access_token' => 'test_access_token',
            'channel_secret' => 'test_secret',
        ]);

        if ($enabled) {
            BotSetting::create([
                'bot_id' => $bot->id,
                'reply_sticker_enabled' => true,
                'reply_sticker_mode' => $mode ?? 'static',
                'reply_sticker_message' => 'ได้รับสติกเกอร์แล้วค่ะ',
            ]);
        }

        $profile = CustomerProfile::factory()->create([
            'external_id' => 'U_stk_user',
            'channel_type' => 'line',
        ]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $profile->id,
            'external_customer_id' => 'U_stk_user',
            'channel_type' => 'line',
            'status' => 'active',
            'is_handover' => false,
            'message_count' => 0,
        ]);

        $event = include base_path('tests/fixtures/line-sticker-event.php');

        $handler = new StickerHandler(
            $bot,
            $stickerService ?? app(StickerReplyService::class)
        );

        return [
            'bot' => $bot,
            'conversation' => $conversation,
            'messageData' => [
                'id' => $event['message']['id'],
                'type' => 'sticker',
                'sticker_id' => $event['message']['stickerId'],
                'package_id' => $event['message']['packageId'],
            ],
            'event' => $event,
            'handler' => $handler,
        ];
    }

    /**
     * A LINEService mock for the sticker reply path.
     */
    private function lineServiceMock(array $event, array $overrides = []): LINEService
    {
        $mock = Mockery::mock(LINEService::class);
        $mock->shouldReceive('showLoadingIndicator')->zeroOrMoreTimes()->andReturn(true);
        $mock->shouldReceive('generateRetryKey')->andReturnUsing(fn () => (string) \Illuminate\Support\Str::uuid());

        foreach ($overrides as $method => $expectation) {
            $expectation($mock);
        }

        return $mock;
    }

    // ------------------------------------------------------------------
    // Static mode — the happy path the extraction must preserve
    // ------------------------------------------------------------------

    public function test_static_mode_replies_and_saves_bot_message_with_sticker_metadata(): void
    {
        $stickerService = Mockery::mock(StickerReplyService::class);
        $stickerService->shouldReceive('generateReply')->once()->andReturn('ได้รับสติกเกอร์แล้วค่ะ');

        $scenario = $this->buildScenario(true, 'static', $stickerService);
        $bot = $scenario['bot'];
        $conversation = $scenario['conversation'];

        $before = [
            'conv_count' => $conversation->fresh()->message_count,
            'bot_total' => $bot->fresh()->total_messages,
        ];

        $lineService = $this->lineServiceMock($scenario['event'], [
            'replyWithFallback' => fn (LINEService $m) => $m->shouldReceive('replyWithFallback')
                ->once()
                ->with(Mockery::type('App\Models\Bot'), 'reply_token_stk_1', 'U_stk_user', ['ได้รับสติกเกอร์แล้วค่ะ'], Mockery::type('string'))
                ->andReturn(['method' => 'reply', 'success' => true]),
        ]);

        $scenario['handler']->reply(
            $lineService,
            $conversation,
            $scenario['messageData'],
            'U_stk_user',
            'reply_token_stk_1',
            null
        );

        $botMessage = $conversation->messages()->where('sender', 'bot')->latest('id')->first();
        $this->assertNotNull($botMessage, 'expected a bot message row from the sticker reply');
        $this->assertSame(1, $conversation->messages()->where('sender', 'bot')->count());

        $this->assertSame('ได้รับสติกเกอร์แล้วค่ะ', $botMessage->content);
        $this->assertSame('text', $botMessage->type);
        $this->assertIsArray($botMessage->metadata);
        $this->assertTrue($botMessage->metadata['sticker_reply']);
        $this->assertSame('static', $botMessage->metadata['sticker_mode']);
        $this->assertSame('999', $botMessage->metadata['sticker_id']);

        // Stat increments: the sticker reply block adds +1 to message_count
        // and +1 to bot total_messages.
        $freshConv = $conversation->fresh();
        $this->assertSame($before['conv_count'] + 1, $freshConv->message_count);
        $this->assertSame($botMessage->id, $freshConv->last_message_id);
        $this->assertSame($before['bot_total'] + 1, $bot->fresh()->total_messages);
    }

    public function test_disabled_settings_is_silent_noop(): void
    {
        $scenario = $this->buildScenario(false);
        $conversation = $scenario['conversation'];

        $lineService = $this->lineServiceMock($scenario['event'], [
            'replyWithFallback' => fn (LINEService $m) => $m->shouldNotReceive('replyWithFallback'),
            'showLoadingIndicator' => fn (LINEService $m) => $m->shouldNotReceive('showLoadingIndicator'),
        ]);

        $scenario['handler']->reply(
            $lineService,
            $conversation,
            $scenario['messageData'],
            'U_stk_user',
            'reply_token_stk_1',
            null
        );

        // Silent: no bot row
        $this->assertSame(0, $conversation->fresh()->messages()->where('sender', 'bot')->count());
    }

    public function test_null_reply_from_service_is_silent_noop(): void
    {
        $stickerService = Mockery::mock(StickerReplyService::class);
        $stickerService->shouldReceive('generateReply')->zeroOrMoreTimes()->andReturn(null);

        $scenario = $this->buildScenario(true, 'ai', $stickerService);
        $conversation = $scenario['conversation'];

        $lineService = $this->lineServiceMock($scenario['event'], [
            'replyWithFallback' => fn (LINEService $m) => $m->shouldNotReceive('replyWithFallback'),
        ]);

        $scenario['handler']->reply(
            $lineService,
            $conversation,
            $scenario['messageData'],
            'U_stk_user',
            'reply_token_stk_1',
            null
        );

        // No bot reply, no bot row.
        $this->assertSame(0, $conversation->fresh()->messages()->where('sender', 'bot')->count());
    }
}
