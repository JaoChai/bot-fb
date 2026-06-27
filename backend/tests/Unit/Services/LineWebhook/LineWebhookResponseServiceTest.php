<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AIService;
use App\Services\Chat\ConversationContextService;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\ResponseEnvelope;
use App\Services\LineWebhook\WebhookContext;
use App\Services\ModelCapabilityService;
use App\Services\OpenRouterService;
use App\Services\StickerReplyService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class LineWebhookResponseServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Event builders
    // -------------------------------------------------------------------------

    private function makeTextEvent(string $userId = 'U_test'): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'rt_test',
            'source' => ['userId' => $userId],
            'message' => ['type' => 'text', 'text' => 'hello', 'id' => 'msg_001'],
            'webhookEventId' => 'evt_001',
            'timestamp' => 1700000000000,
            'deliveryContext' => ['isRedelivery' => false],
        ];
    }

    private function makeStickerEvent(): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'rt_test',
            'source' => ['userId' => 'U_test'],
            'message' => ['type' => 'sticker', 'id' => 'stk_001', 'sticker_id' => '1', 'package_id' => '1'],
            'webhookEventId' => 'evt_002',
            'timestamp' => 1700000000000,
            'deliveryContext' => ['isRedelivery' => false],
        ];
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

    // -------------------------------------------------------------------------
    // Model builders (use DB for read-only, mock writes where service calls create())
    // -------------------------------------------------------------------------

    private function makeBot(array $attrs = []): Bot
    {
        return Bot::factory()->create(array_merge([
            'status' => 'active',
            'primary_chat_model' => 'openai/gpt-4o',
            'fallback_chat_model' => null,
            'decision_model' => null,
            'fallback_decision_model' => null,
            'llm_temperature' => 0.7,
            'llm_max_tokens' => 1024,
            'system_prompt' => 'You are a helpful assistant.',
        ], $attrs));
    }

    /**
     * Build a Conversation mock that does NOT hit the DB when messages()->create() is called.
     * Returns a partial mock with the messages() relation stubbed.
     */
    private function makeConversationMock(Bot $bot, bool $isHandover = false): Conversation
    {
        $conversation = Conversation::factory()->make([
            'id' => 9999,
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_test',
            'channel_type' => 'line',
            'status' => 'active',
            'is_handover' => $isHandover,
            'context_cleared_at' => null,
        ]);

        return $conversation;
    }

    /**
     * Build a Mockery partial for a Conversation that stubs messages()->create().
     */
    private function makeConversationWithMessagesMock(Bot $bot, Message $botMessageToReturn): Conversation
    {
        $hasMany = Mockery::mock(HasMany::class);
        $hasMany->shouldReceive('create')->andReturn($botMessageToReturn);
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
        // update() calls for stats — let pass silently
        $conversation->shouldReceive('update')->andReturn(true);

        return $conversation;
    }

    private function makeMessage(Conversation $conversation, string $sender = 'user', string $content = 'hello'): Message
    {
        return Message::factory()->make([
            'id' => rand(1, 9999),
            'conversation_id' => $conversation->id ?? 9999,
            'sender' => $sender,
            'content' => $content,
            'type' => 'text',
        ]);
    }

    // -------------------------------------------------------------------------
    // Service builder
    // -------------------------------------------------------------------------

    private function makeService(
        ?AIService $aiService = null,
        ?OpenRouterService $openRouter = null,
        ?StickerReplyService $stickerReply = null,
        ?ConversationContextService $contextService = null,
        ?ModelCapabilityService $modelCapability = null,
        ?LINEService $line = null,
    ): LineWebhookResponseService {
        $aiService ??= Mockery::mock(AIService::class);
        $openRouter ??= Mockery::mock(OpenRouterService::class);
        $stickerReply ??= Mockery::mock(StickerReplyService::class);

        if ($contextService === null) {
            $contextService = Mockery::mock(ConversationContextService::class);
            $contextService->shouldReceive('autoClearIfIdle')->andReturn(true)->byDefault();
        }

        $modelCapability ??= Mockery::mock(ModelCapabilityService::class);

        if ($line === null) {
            $line = Mockery::mock(LINEService::class);
            $line->shouldReceive('showLoadingIndicator')->andReturn(true)->byDefault();
        }

        return new LineWebhookResponseService(
            $aiService,
            $openRouter,
            $stickerReply,
            $contextService,
            $modelCapability,
            $line,
        );
    }

    // -------------------------------------------------------------------------
    // Test 1: Text event — AIService called, envelope and metadata set
    // -------------------------------------------------------------------------

    public function test_text_event_calls_ai_service_and_sets_envelope(): void
    {
        $bot = $this->makeBot();
        $conversation = $this->makeConversationMock($bot);
        $userMessage = $this->makeMessage($conversation);
        $botMessage = $this->makeMessage($conversation, 'bot', 'Hello from bot!');

        $contextService = Mockery::mock(ConversationContextService::class);
        $contextService->shouldReceive('autoClearIfIdle')->once()->andReturn(true);

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('generateAndSaveResponse')
            ->once()
            ->with($bot, $conversation, $userMessage)
            ->andReturn($botMessage);

        $ctx = new WebhookContext($bot, $this->makeTextEvent());
        $ctx->conversation = $conversation;
        $ctx->userMessage = $userMessage;
        $ctx->metadata['should_generate_response'] = true;

        $svc = $this->makeService(aiService: $aiService, contextService: $contextService);
        $svc->generate($ctx);

        $this->assertInstanceOf(ResponseEnvelope::class, $ctx->response);
        $this->assertSame('text', $ctx->response->type);
        $this->assertSame('Hello from bot!', $ctx->response->payload);
        $this->assertSame($botMessage, $ctx->metadata['bot_message']);
    }

    // -------------------------------------------------------------------------
    // Test 2: Sticker event — StickerReplyService called, envelope set
    // -------------------------------------------------------------------------

    public function test_sticker_event_calls_sticker_service_and_sets_envelope(): void
    {
        $bot = $this->makeBot();
        $bot->setRelation('settings', new BotSetting([
            'reply_sticker_enabled' => true,
            'reply_sticker_mode' => 'static',
            'reply_sticker_message' => 'สวัสดีค่ะ',
        ]));

        $botMessage = $this->makeMessage($this->makeConversationMock($bot), 'bot', 'สวัสดีค่ะ');
        $conversation = $this->makeConversationWithMessagesMock($bot, $botMessage);

        $stickerReply = Mockery::mock(StickerReplyService::class);
        $stickerReply->shouldReceive('generateReply')
            ->once()
            ->andReturn('สวัสดีค่ะ');

        $ctx = new WebhookContext($bot, $this->makeStickerEvent());
        $ctx->conversation = $conversation;
        $ctx->metadata['should_generate_response'] = true;

        $svc = $this->makeService(stickerReply: $stickerReply);
        $svc->generate($ctx);

        $this->assertInstanceOf(ResponseEnvelope::class, $ctx->response);
        $this->assertSame('text', $ctx->response->type);
        $this->assertSame('สวัสดีค่ะ', $ctx->response->payload);
    }

    // -------------------------------------------------------------------------
    // Test 3: Sticker reply disabled — response null
    // -------------------------------------------------------------------------

    public function test_sticker_service_returns_null_leaves_response_null(): void
    {
        $bot = $this->makeBot();
        $bot->setRelation('settings', new BotSetting([
            'reply_sticker_enabled' => true,
            'reply_sticker_mode' => 'static',
        ]));

        $conversation = $this->makeConversationMock($bot);

        $stickerReply = Mockery::mock(StickerReplyService::class);
        $stickerReply->shouldReceive('generateReply')->once()->andReturn(null);

        $ctx = new WebhookContext($bot, $this->makeStickerEvent());
        $ctx->conversation = $conversation;
        $ctx->metadata['should_generate_response'] = true;

        $svc = $this->makeService(stickerReply: $stickerReply);
        $svc->generate($ctx);

        $this->assertNull($ctx->response);
    }

    // -------------------------------------------------------------------------
    // Test 4: Image event with vision-capable model — vision pipeline runs
    // -------------------------------------------------------------------------

    public function test_image_event_with_vision_model_sets_envelope(): void
    {
        $bot = $this->makeBot();
        $botMessage = $this->makeMessage($this->makeConversationMock($bot), 'bot', 'นี่คือรูปภาพของ...');
        $conversation = $this->makeConversationWithMessagesMock($bot, $botMessage);

        $userMessage = $this->makeMessage($conversation, 'user', '[รูปภาพ]');
        $userMessage->type = 'image';
        $userMessage->media_url = 'https://example.com/image.jpg';

        $modelCapability = Mockery::mock(ModelCapabilityService::class);
        $modelCapability->shouldReceive('supportsVision')->andReturn(true);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('chatWithVision')
            ->once()
            ->andReturn([
                'content' => 'นี่คือรูปภาพของ...',
                'model' => 'openai/gpt-4o',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ]);
        $openRouter->shouldReceive('estimateCost')->andReturn(0.001);

        $ctx = new WebhookContext($bot, $this->makeImageEvent());
        $ctx->conversation = $conversation;
        $ctx->userMessage = $userMessage;
        $ctx->metadata['should_generate_response'] = true;

        $svc = $this->makeService(openRouter: $openRouter, modelCapability: $modelCapability);
        $svc->generate($ctx);

        $this->assertInstanceOf(ResponseEnvelope::class, $ctx->response);
        $this->assertSame('text', $ctx->response->type);
        $this->assertSame('นี่คือรูปภาพของ...', $ctx->response->payload);
        $this->assertNotNull($ctx->metadata['bot_message']);
    }

    // -------------------------------------------------------------------------
    // Test 5: Image event without vision-capable model — response null
    // -------------------------------------------------------------------------

    public function test_image_event_without_vision_model_returns_null(): void
    {
        $bot = $this->makeBot([
            'primary_chat_model' => null,
            'fallback_chat_model' => null,
            'decision_model' => null,
            'fallback_decision_model' => null,
        ]);

        $conversation = $this->makeConversationMock($bot);
        $userMessage = $this->makeMessage($conversation, 'user', '[รูปภาพ]');
        $userMessage->type = 'image';
        $userMessage->media_url = 'https://example.com/image.jpg';

        $modelCapability = Mockery::mock(ModelCapabilityService::class);
        $modelCapability->shouldReceive('supportsVision')->andReturn(false);

        $ctx = new WebhookContext($bot, $this->makeImageEvent());
        $ctx->conversation = $conversation;
        $ctx->userMessage = $userMessage;
        $ctx->metadata['should_generate_response'] = true;

        $svc = $this->makeService(modelCapability: $modelCapability);
        $svc->generate($ctx);

        $this->assertNull($ctx->response);
    }

    // -------------------------------------------------------------------------
    // Test 6: Video/audio/file/location — response null
    // -------------------------------------------------------------------------

    /** @dataProvider nonResponseMessageTypes */
    public function test_non_response_message_types_return_null(string $messageType): void
    {
        $bot = $this->makeBot();
        $conversation = $this->makeConversationMock($bot);

        $event = [
            'type' => 'message',
            'replyToken' => 'rt_test',
            'source' => ['userId' => 'U_test'],
            'message' => ['type' => $messageType, 'id' => 'msg_001'],
            'webhookEventId' => 'evt_005',
            'timestamp' => 1700000000000,
            'deliveryContext' => ['isRedelivery' => false],
        ];

        $ctx = new WebhookContext($bot, $event);
        $ctx->conversation = $conversation;
        $ctx->metadata['should_generate_response'] = true;

        $svc = $this->makeService();
        $svc->generate($ctx);

        $this->assertNull($ctx->response);
    }

    public static function nonResponseMessageTypes(): array
    {
        return [
            'video' => ['video'],
            'audio' => ['audio'],
            'file' => ['file'],
            'location' => ['location'],
        ];
    }

    // -------------------------------------------------------------------------
    // Test 7: bot_inactive metadata — response null
    // -------------------------------------------------------------------------

    public function test_bot_inactive_skips_generation(): void
    {
        $bot = $this->makeBot();
        $conversation = $this->makeConversationMock($bot);

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldNotReceive('generateAndSaveResponse');

        $ctx = new WebhookContext($bot, $this->makeTextEvent());
        $ctx->conversation = $conversation;
        $ctx->metadata['bot_inactive'] = true;

        $svc = $this->makeService(aiService: $aiService);
        $svc->generate($ctx);

        $this->assertNull($ctx->response);
    }

    // -------------------------------------------------------------------------
    // Test 8: handover metadata — response null
    // -------------------------------------------------------------------------

    public function test_handover_skips_generation(): void
    {
        $bot = $this->makeBot();
        $conversation = $this->makeConversationMock($bot);

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldNotReceive('generateAndSaveResponse');

        $ctx = new WebhookContext($bot, $this->makeTextEvent());
        $ctx->conversation = $conversation;
        $ctx->metadata['handover'] = true;

        $svc = $this->makeService(aiService: $aiService);
        $svc->generate($ctx);

        $this->assertNull($ctx->response);
    }

    // -------------------------------------------------------------------------
    // Test 9: Vision detectPendingOrder = true — uses payment-context prompt
    // -------------------------------------------------------------------------

    public function test_image_with_pending_order_uses_payment_prompt(): void
    {
        $bot = $this->makeBot();
        $botMessage = $this->makeMessage($this->makeConversationMock($bot), 'bot', 'เงินเข้าแล้ว ✅');

        // Stub messages() to return history with ORDER keyword, then return botMessage on create
        $hasMany = Mockery::mock(HasMany::class);
        $hasMany->shouldReceive('create')->andReturn($botMessage);
        $hasMany->shouldReceive('whereIn')->andReturnSelf();
        $hasMany->shouldReceive('where')->andReturnSelf();
        $hasMany->shouldReceive('latest')->andReturnSelf();
        $hasMany->shouldReceive('take')->andReturnSelf();
        // Return one history message containing an ORDER keyword
        $historyMsg = $this->makeMessage($this->makeConversationMock($bot), 'bot', 'ยอดรวมทั้งหมด 500 บาท เลขบัญชี 123');
        $hasMany->shouldReceive('get')->andReturn(collect([$historyMsg]));

        $conversation = Mockery::mock(Conversation::class)->makePartial();
        $conversation->id = 9999;
        $conversation->bot_id = $bot->id;
        $conversation->status = 'active';
        $conversation->is_handover = false;
        $conversation->context_cleared_at = null;
        $conversation->shouldReceive('messages')->andReturn($hasMany);
        $conversation->shouldReceive('update')->andReturn(true);

        $userMessage = $this->makeMessage($conversation, 'user', '[รูปภาพ]');
        $userMessage->type = 'image';
        $userMessage->media_url = 'https://example.com/slip.jpg';

        $modelCapability = Mockery::mock(ModelCapabilityService::class);
        $modelCapability->shouldReceive('supportsVision')->andReturn(true);

        $capturedMessages = null;
        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('chatWithVision')
            ->once()
            ->andReturnUsing(function (array $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;

                return [
                    'content' => 'เงินเข้าแล้ว ✅',
                    'model' => 'openai/gpt-4o',
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
                ];
            });
        $openRouter->shouldReceive('estimateCost')->andReturn(0.001);

        $ctx = new WebhookContext($bot, $this->makeImageEvent());
        $ctx->conversation = $conversation;
        $ctx->userMessage = $userMessage;
        $ctx->metadata['should_generate_response'] = true;

        $svc = $this->makeService(openRouter: $openRouter, modelCapability: $modelCapability);
        $svc->generate($ctx);

        $this->assertNotNull($capturedMessages);
        $userMsg = collect($capturedMessages)->firstWhere('role', 'user');
        $this->assertNotNull($userMsg);
        $this->assertStringContainsString('สลิป', $userMsg['content']);
    }

    // -------------------------------------------------------------------------
    // Test 10: Sticker exception is swallowed and logged (legacy parity)
    // -------------------------------------------------------------------------

    public function test_sticker_exception_is_swallowed_and_logged(): void
    {
        $bot = $this->makeBot();
        $bot->setRelation('settings', new BotSetting([
            'reply_sticker_enabled' => true,
            'reply_sticker_mode' => 'static',
        ]));

        $conversation = $this->makeConversationMock($bot);

        $stickerReply = Mockery::mock(StickerReplyService::class);
        $stickerReply->shouldReceive('generateReply')
            ->once()
            ->andThrow(new \RuntimeException('sticker boom'));

        $ctx = new WebhookContext($bot, $this->makeStickerEvent());
        $ctx->conversation = $conversation;
        $ctx->metadata['should_generate_response'] = true;

        Log::spy();

        $svc = $this->makeService(stickerReply: $stickerReply);

        // Should NOT throw — legacy swallows and logs
        $svc->generate($ctx);

        $this->assertNull($ctx->response);
        Log::shouldHaveReceived('warning')
            ->with('Failed to reply to sticker', Mockery::any())
            ->once();
    }

    // -------------------------------------------------------------------------
    // Test 11: AIService throws — exception re-thrown
    // -------------------------------------------------------------------------

    public function test_ai_service_exception_is_rethrown(): void
    {
        $bot = $this->makeBot();
        $conversation = $this->makeConversationMock($bot);
        $userMessage = $this->makeMessage($conversation);

        $contextService = Mockery::mock(ConversationContextService::class);
        $contextService->shouldReceive('autoClearIfIdle')->andReturn(true);

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldReceive('generateAndSaveResponse')
            ->once()
            ->andThrow(new \RuntimeException('OpenRouter down'));

        $ctx = new WebhookContext($bot, $this->makeTextEvent());
        $ctx->conversation = $conversation;
        $ctx->userMessage = $userMessage;
        $ctx->metadata['should_generate_response'] = true;

        $svc = $this->makeService(aiService: $aiService, contextService: $contextService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRouter down');

        $svc->generate($ctx);
    }
}
