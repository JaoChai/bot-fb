<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ConversationContextService;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\ResponseEnvelope;
use App\Services\LineWebhook\WebhookContext;
use App\Services\ModelCapabilityService;
use App\Services\OpenRouterService;
use App\Services\Payment\SlipVerificationResult;
use App\Services\Payment\SlipVerificationService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vision reply sends the customer to Technical Support → Telegram alert to the
 * owner (same alert family as a Support Router handover).
 *
 * Detection (no new model call): bot.support_router_mode === 'on' AND the vision
 * reply text contains a URL that also appears in that bot's support_handover_message
 * (URLs extracted from the handover message; no URL there = never alert).
 * The alert reuses the router's alert code and never throws: a failed send only
 * logs and the customer reply is unchanged.
 */
class VisionSupportAlertTest extends TestCase
{
    use RefreshDatabase;

    private const HANDOVER_URL = 'https://lin.ee/h5wYpIf';

    private const HANDOVER_MESSAGE = 'กรณีมีปัญหากรุณาติดต่อทีมซัพพอร์ตที่ https://lin.ee/h5wYpIf ครับ';

    private const VISION_REPLY = 'ขออภัยครับ ปัญหานี้ต้องติดต่อทีม Support ที่ https://lin.ee/h5wYpIf เพื่อให้ทีมงานช่วยตรวจสอบครับ';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function makeBot(string $routerMode = 'on', ?string $handoverMessage = self::HANDOVER_MESSAGE): Bot
    {
        $user = User::factory()->create();

        return Bot::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'name' => 'ร้านทดสอบ',
            'primary_chat_model' => 'openai/gpt-4o',
            'fallback_chat_model' => null,
            'llm_temperature' => 0.7,
            'llm_max_tokens' => 1024,
            'system_prompt' => 'You are a helpful assistant.',
            'support_router_mode' => $routerMode,
            'support_handover_message' => $handoverMessage,
        ]);
    }

    private function giveTelegramPlugin(Bot $bot): void
    {
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'support-alert',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tg-token', 'chat_id' => '-100123'],
        ]);
    }

    /**
     * Conversation mock that stubs messages()->create() and captures the created
     * data (metadata assertions) in $created.
     */
    private function makeConversation(Bot $bot, Message $botMessage, ?array &$created): Conversation
    {
        $hasMany = Mockery::mock(HasMany::class);
        $hasMany->shouldReceive('create')->once()->andReturnUsing(function (array $data) use ($botMessage, &$created) {
            $created = $data;

            return $botMessage;
        });
        // For getVisionConversationHistory (whereIn, where, latest, take, get chain)
        $hasMany->shouldReceive('whereIn')->andReturnSelf();
        $hasMany->shouldReceive('where')->andReturnSelf();
        $hasMany->shouldReceive('latest')->andReturnSelf();
        $hasMany->shouldReceive('take')->andReturnSelf();
        $hasMany->shouldReceive('get')->andReturn(collect([]));

        $conversation = Mockery::mock(Conversation::class)->makePartial();
        $conversation->id = 9999;
        $conversation->bot_id = $bot->id;
        $conversation->external_customer_id = 'U_test';
        $conversation->channel_type = 'line';
        $conversation->status = 'active';
        $conversation->is_handover = false;
        $conversation->context_cleared_at = null;
        $conversation->shouldReceive('messages')->andReturn($hasMany);
        $conversation->shouldReceive('update')->andReturn(true);

        return $conversation;
    }

    private function makeImageContext(Bot $bot, Conversation $conversation): WebhookContext
    {
        $userMessage = Message::factory()->make([
            'id' => rand(1, 9999),
            'conversation_id' => $conversation->id ?? 9999,
            'sender' => 'user',
            'content' => '[รูปภาพ]',
            'type' => 'image',
        ]);
        $userMessage->media_url = 'https://example.com/image.jpg';

        $ctx = new WebhookContext($bot, $this->makeImageEvent());
        $ctx->conversation = $conversation;
        $ctx->userMessage = $userMessage;
        $ctx->metadata['should_generate_response'] = true;

        return $ctx;
    }

    private function makeImageEvent(): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'rt_test',
            'source' => ['userId' => 'U_test'],
            'message' => ['type' => 'image', 'id' => 'img_001'],
            'webhookEventId' => 'evt_003',
            'timestamp' => 1700000000000,
            'deliveryContext' => ['isRedelivery' => false],
        ];
    }

    private function visionReturning(string $content): OpenRouterService
    {
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('chatWithVision')
            ->once()
            ->andReturn([
                'content' => $content,
                'model' => 'openai/gpt-4o',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ]);
        $openRouter->shouldReceive('estimateCost')->andReturn(0.001);

        return $openRouter;
    }

    private function makeService(
        ?OpenRouterService $openRouter = null,
        ?SlipVerificationService $slipVerification = null,
    ): LineWebhookResponseService {
        $aiService = Mockery::mock(\App\Services\AIService::class);
        $openRouter ??= Mockery::mock(OpenRouterService::class);
        $stickerReply = Mockery::mock(\App\Services\StickerReplyService::class);

        $contextService = Mockery::mock(ConversationContextService::class);
        $contextService->shouldReceive('autoClearIfIdle')->andReturn(true)->byDefault();

        $modelCapability = Mockery::mock(ModelCapabilityService::class);
        $modelCapability->shouldReceive('supportsVision')->andReturn(true)->byDefault();

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('showLoadingIndicator')->andReturn(true)->byDefault();

        $slipVerification ??= Mockery::mock(SlipVerificationService::class);

        return new LineWebhookResponseService(
            $aiService,
            $openRouter,
            $stickerReply,
            $contextService,
            $modelCapability,
            $line,
            $slipVerification,
        );
    }

    private function fakeTelegramOk(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function telegramCallCount(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'api.telegram.org'))->count();
    }

    // -------------------------------------------------------------------------
    // (a) Vision reply with the handover link, mode on → 1 alert + metadata
    // -------------------------------------------------------------------------

    #[Test]
    public function test_vision_reply_with_handover_link_alerts_once_and_sets_metadata(): void
    {
        $bot = $this->makeBot();
        $this->giveTelegramPlugin($bot);
        $this->fakeTelegramOk();

        $botMessage = Message::factory()->make(['sender' => 'bot', 'content' => self::VISION_REPLY, 'type' => 'text']);
        $created = null;
        $conversation = $this->makeConversation($bot, $botMessage, $created);
        $ctx = $this->makeImageContext($bot, $conversation);

        $svc = $this->makeService(openRouter: $this->visionReturning(self::VISION_REPLY));
        $svc->generate($ctx);

        // exactly 1 Telegram call
        $this->assertSame(1, $this->telegramCallCount());

        // alert text: same header as a router handover, "จากรูปภาพ" instead of the
        // score line, then the AI reply
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }

            $text = $request->data()['text'];

            $this->assertStringContainsString('🛠 <b>ลูกค้าต้องการ Support</b> (ร้านทดสอบ)', $text);
            $this->assertStringContainsString('จากรูปภาพ', $text);
            $this->assertStringContainsString(self::VISION_REPLY, $text);
            $this->assertStringNotContainsString('คะแนน', $text);

            return true;
        });

        // metadata.support_alert recorded on the saved bot message
        $this->assertSame('vision', $created['metadata']['support_alert'] ?? null);
        $this->assertTrue($created['metadata']['vision_analysis'] ?? false);

        // customer reply unchanged
        $this->assertInstanceOf(ResponseEnvelope::class, $ctx->response);
        $this->assertSame('text', $ctx->response->type);
        $this->assertSame(self::VISION_REPLY, $ctx->response->payload);
    }

    // -------------------------------------------------------------------------
    // (b) mode shadow / off → 0 Telegram calls, no metadata
    // -------------------------------------------------------------------------

    public static function nonOnModes(): array
    {
        return [
            'shadow' => ['shadow'],
            'off' => ['off'],
        ];
    }

    #[Test]
    #[DataProvider('nonOnModes')]
    public function test_no_alert_when_router_mode_is_not_on(string $mode): void
    {
        $bot = $this->makeBot(routerMode: $mode);
        $this->giveTelegramPlugin($bot);
        $this->fakeTelegramOk();

        $botMessage = Message::factory()->make(['sender' => 'bot', 'content' => self::VISION_REPLY, 'type' => 'text']);
        $created = null;
        $conversation = $this->makeConversation($bot, $botMessage, $created);
        $ctx = $this->makeImageContext($bot, $conversation);

        $svc = $this->makeService(openRouter: $this->visionReturning(self::VISION_REPLY));
        $svc->generate($ctx);

        $this->assertSame(0, $this->telegramCallCount());
        $this->assertArrayNotHasKey('support_alert', $created['metadata']);
        $this->assertSame(self::VISION_REPLY, $ctx->response->payload);
    }

    // -------------------------------------------------------------------------
    // (c) Vision reply without the handover link → 0 Telegram calls
    // -------------------------------------------------------------------------

    #[Test]
    public function test_no_alert_when_vision_reply_has_no_handover_link(): void
    {
        $bot = $this->makeBot();
        $this->giveTelegramPlugin($bot);
        $this->fakeTelegramOk();

        $reply = 'นี่คือรูปภาพของสินค้าครับ ต้องการสั่งซื้อเพิ่มไหมครับ';

        $botMessage = Message::factory()->make(['sender' => 'bot', 'content' => $reply, 'type' => 'text']);
        $created = null;
        $conversation = $this->makeConversation($bot, $botMessage, $created);
        $ctx = $this->makeImageContext($bot, $conversation);

        $svc = $this->makeService(openRouter: $this->visionReturning($reply));
        $svc->generate($ctx);

        $this->assertSame(0, $this->telegramCallCount());
        $this->assertArrayNotHasKey('support_alert', $created['metadata']);
        $this->assertSame($reply, $ctx->response->payload);
    }

    // -------------------------------------------------------------------------
    // (c guard) Handover message without any URL → never alert
    // -------------------------------------------------------------------------

    #[Test]
    public function test_no_alert_when_handover_message_contains_no_url(): void
    {
        $bot = $this->makeBot(handoverMessage: 'กรณีมีปัญหากรุณาติดต่อทีมซัพพอร์ตครับ');
        $this->giveTelegramPlugin($bot);
        $this->fakeTelegramOk();

        $reply = 'ขออภัยครับ ติดต่อทีม Support ได้ที่ https://example.com/support ครับ';

        $botMessage = Message::factory()->make(['sender' => 'bot', 'content' => $reply, 'type' => 'text']);
        $created = null;
        $conversation = $this->makeConversation($bot, $botMessage, $created);
        $ctx = $this->makeImageContext($bot, $conversation);

        $svc = $this->makeService(openRouter: $this->visionReturning($reply));
        $svc->generate($ctx);

        $this->assertSame(0, $this->telegramCallCount());
        $this->assertArrayNotHasKey('support_alert', $created['metadata']);
    }

    // -------------------------------------------------------------------------
    // (d) Slip image → handled by slip verification, 0 support alerts
    // -------------------------------------------------------------------------

    #[Test]
    public function test_slip_image_sends_no_support_alert(): void
    {
        $bot = $this->makeBot();
        $this->giveTelegramPlugin($bot);
        $this->fakeTelegramOk();
        $bot->setRelation('settings', new BotSetting(['slip_verification_enabled' => true]));

        $slip = Mockery::mock(SlipVerificationService::class);
        $slip->shouldReceive('verify')->once()->andReturn(new SlipVerificationResult(
            isSlip: true,
            passed: false,
            failReason: 'unreadable',
        ));
        $slip->shouldReceive('notifyAdmin')->once()->andReturn(null);

        $botMessage = Message::factory()->make([
            'sender' => 'bot',
            'content' => LineWebhookResponseService::SLIP_FAIL_TEMPLATE,
            'type' => 'text',
        ]);
        $created = null;
        $conversation = $this->makeConversation($bot, $botMessage, $created);
        $ctx = $this->makeImageContext($bot, $conversation);

        // bare OpenRouter mock: chatWithVision must never be reached
        $svc = $this->makeService(slipVerification: $slip);
        $svc->generate($ctx);

        $this->assertSame(0, $this->telegramCallCount());
        $this->assertArrayNotHasKey('support_alert', $created['metadata']);
        $this->assertTrue($created['metadata']['slip_verification'] ?? false);
        $this->assertSame(LineWebhookResponseService::SLIP_FAIL_TEMPLATE, $ctx->response->payload);
    }

    // -------------------------------------------------------------------------
    // (e) Telegram 500 → warning logged, customer reply still sent
    // -------------------------------------------------------------------------

    #[Test]
    public function test_telegram_500_logs_warning_and_customer_reply_is_unchanged(): void
    {
        $bot = $this->makeBot();
        $this->giveTelegramPlugin($bot);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        Log::spy();

        $botMessage = Message::factory()->make(['sender' => 'bot', 'content' => self::VISION_REPLY, 'type' => 'text']);
        $created = null;
        $conversation = $this->makeConversation($bot, $botMessage, $created);
        $ctx = $this->makeImageContext($bot, $conversation);

        $svc = $this->makeService(openRouter: $this->visionReturning(self::VISION_REPLY));
        $svc->generate($ctx);

        // customer reply unchanged despite the failed alert
        $this->assertInstanceOf(ResponseEnvelope::class, $ctx->response);
        $this->assertSame(self::VISION_REPLY, $ctx->response->payload);

        // the failed alert only logs a warning
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            return $message === 'Telegram alert API non-OK response'
                && $context['status'] === 500;
        });
        $this->assertGreaterThanOrEqual(1, $this->telegramCallCount());

        // alert path was taken → metadata recorded
        $this->assertSame('vision', $created['metadata']['support_alert'] ?? null);
    }

    // -------------------------------------------------------------------------
    // (e guard) No telegram plugin on the flow → never throws, reply unchanged
    // -------------------------------------------------------------------------

    #[Test]
    public function test_missing_telegram_plugin_never_throws_and_reply_is_unchanged(): void
    {
        $bot = $this->makeBot();
        $this->fakeTelegramOk();

        $botMessage = Message::factory()->make(['sender' => 'bot', 'content' => self::VISION_REPLY, 'type' => 'text']);
        $created = null;
        $conversation = $this->makeConversation($bot, $botMessage, $created);
        $ctx = $this->makeImageContext($bot, $conversation);

        $svc = $this->makeService(openRouter: $this->visionReturning(self::VISION_REPLY));
        $svc->generate($ctx);

        $this->assertSame(0, $this->telegramCallCount());
        $this->assertSame(self::VISION_REPLY, $ctx->response->payload);
    }

    // -------------------------------------------------------------------------
    // Alert text truncates the AI reply to the first 300 chars
    // -------------------------------------------------------------------------

    #[Test]
    public function test_vision_alert_truncates_reply_to_300_chars(): void
    {
        $bot = $this->makeBot();
        $this->giveTelegramPlugin($bot);
        $this->fakeTelegramOk();

        $longReply = self::HANDOVER_URL."\n".str_repeat('ก', 350).'TAILMARKER';

        $botMessage = Message::factory()->make(['sender' => 'bot', 'content' => $longReply, 'type' => 'text']);
        $created = null;
        $conversation = $this->makeConversation($bot, $botMessage, $created);
        $ctx = $this->makeImageContext($bot, $conversation);

        $svc = $this->makeService(openRouter: $this->visionReturning($longReply));
        $svc->generate($ctx);

        $this->assertSame(1, $this->telegramCallCount());
        Http::assertSent(function ($request) use ($longReply) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }

            $text = $request->data()['text'];

            // first 300 chars present, tail beyond 300 absent
            $this->assertStringContainsString(mb_substr($longReply, 0, 300), $text);
            $this->assertStringNotContainsString('TAILMARKER', $text);

            return true;
        });
    }
}
