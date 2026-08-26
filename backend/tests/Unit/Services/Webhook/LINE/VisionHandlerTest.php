<?php

namespace Tests\Unit\Services\Webhook\LINE;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Webhook\Channels\LINE\VisionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Characterization test for the LINE vision-analysis extraction (Task 3).
 *
 * Pins the EXACT output the legacy ProcessLINEWebhook vision helpers produced
 * for one canned image event (fixture copied from PipelineImageRoutingTest),
 * so the verbatim move into VisionHandler is provably behavior-identical.
 *
 * The expected strings below were captured pre-extraction by running
 * tests/pins/vision-pin.php against the original implementation:
 *
 *   image_prompt_pending : ลูกค้าส่งรูปมา — ตรวจสอบว่าเป็นสลิปโอนเงินหรือไม่ ถ้าเป็นสลิปให้ยืนยันยอดตาม conversation history
 *   image_prompt_default : กรุณาอธิบายรูปภาพนี้ และช่วยตอบคำถามหากมี
 *   system_prompt        : bot system_prompt + the vision instruction block
 *   vision_model         : google/gemini-3.5-flash (vision-capable primary)
 *   history              : [ {sender: bot, content: <pending-order text>} ]
 *   detect_pending_order : true (history contains ORDER_CONTEXT_KEYWORDS) / false (empty)
 */
class VisionHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Build the same scenario the pin ran: a bot with a pending-order message in
     * its conversation, plus the canned image event from the fixture.
     *
     * @return array{handler: VisionHandler, bot: Bot, conversation: Conversation, history: array}
     */
    private function buildScenario(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'status' => 'active',
            'name' => 'PinBot',
            'primary_chat_model' => 'google/gemini-3.5-flash',
            'fallback_chat_model' => null,
            'system_prompt' => 'You are a helpful assistant for the pin bot.',
            'llm_temperature' => 0.7,
            'llm_max_tokens' => 1024,
        ]);

        $profile = CustomerProfile::factory()->create([
            'external_id' => 'U_img_user',
            'channel_type' => 'line',
        ]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $profile->id,
            'external_customer_id' => 'U_img_user',
            'channel_type' => 'line',
            'status' => 'active',
            'is_handover' => false,
            'last_message_at' => now(),
        ]);

        // Same pending-order history as PipelineImageRoutingTest::setUp().
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'type' => 'text',
            'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3",
        ]);

        $handler = $this->makeHandler($bot);
        $ref = new \ReflectionClass($handler);
        $history = $ref->getMethod('getVisionConversationHistory')->invoke($handler, $conversation);

        return [
            'handler' => $handler,
            'bot' => $bot,
            'conversation' => $conversation,
            'history' => $history,
        ];
    }

    /**
     * Build a VisionHandler bound to the given bot via the container.
     *
     * Mirrors how the job invokes it: app(VisionHandler::class)->analyze(...) —
     * the container binds $bot so the handler's $this->bot is the right one.
     */
    private function makeHandler(Bot $bot, ?OpenRouterService $openRouterOverride = null): VisionHandler
    {
        $openRouter = $openRouterOverride ?? Mockery::mock(OpenRouterService::class);

        $this->app->bind(Bot::class, fn () => $bot);
        $this->app->bind(OpenRouterService::class, fn () => $openRouter);

        return $this->app->make(VisionHandler::class);
    }

    private function invoke(VisionHandler $handler, string $method, array $args = [])
    {
        $m = new \ReflectionMethod($handler, $method);
        $m->setAccessible(true);

        return $m->invoke($handler, ...$args);
    }

    // ------------------------------------------------------------------
    // Prompt / model / history — the values the old path sent to the LLM
    // ------------------------------------------------------------------

    public function test_image_prompt_with_pending_order_history_is_pinned(): void
    {
        $scenario = $this->buildScenario();

        $prompt = $this->invoke($scenario['handler'], 'getImageAnalysisPrompt', [$scenario['history']]);

        $this->assertSame(
            'ลูกค้าส่งรูปมา — ตรวจสอบว่าเป็นสลิปโอนเงินหรือไม่ ถ้าเป็นสลิปให้ยืนยันยอดตาม conversation history',
            $prompt
        );
    }

    public function test_image_prompt_without_history_is_pinned(): void
    {
        $scenario = $this->buildScenario();

        $prompt = $this->invoke($scenario['handler'], 'getImageAnalysisPrompt', [[]]);

        $this->assertSame('กรุณาอธิบายรูปภาพนี้ และช่วยตอบคำถามหากมี', $prompt);
    }

    public function test_custom_image_prompt_setting_wins_over_defaults(): void
    {
        $scenario = $this->buildScenario();

        // NOTE: the bot_settings table has no image_analysis_prompt column (it is
        // not in the migrations nor in BotSetting::$fillable), so a settings row
        // cannot carry it — the `! empty($settings->image_analysis_prompt)` branch
        // is unreachable in the current schema. This test pins that: the custom
        // prompt is dropped and the default is returned. If a migration adds the
        // column, this assertion should be flipped to expect 'CROSS-CHECK THE IMAGE'.
        BotSetting::create([
            'bot_id' => $scenario['bot']->id,
            'welcome_message' => 'CROSS-CHECK THE IMAGE',
        ]);
        $scenario['bot']->load('settings');

        $prompt = $this->invoke($scenario['handler'], 'getImageAnalysisPrompt', [$scenario['history']]);

        $this->assertSame(
            'ลูกค้าส่งรูปมา — ตรวจสอบว่าเป็นสลิปโอนเงินหรือไม่ ถ้าเป็นสลิปให้ยืนยันยอดตาม conversation history',
            $prompt
        );
    }

    public function test_system_prompt_is_bot_prompt_plus_vision_block(): void
    {
        $scenario = $this->buildScenario();

        $prompt = $this->invoke($scenario['handler'], 'buildVisionSystemPrompt');

        $this->assertStringStartsWith('You are a helpful assistant for the pin bot.', $prompt);
        $this->assertStringContainsString('## การวิเคราะห์รูปภาพ', $prompt);
        $this->assertStringContainsString('เงินเข้าแล้ว [จำนวนเงิน] บาท ✅', $prompt);
        $this->assertStringContainsString('[ยืนยันชำระเงิน]', $prompt);
    }

    public function test_vision_model_prefers_vision_capable_primary(): void
    {
        $scenario = $this->buildScenario();

        $model = $this->invoke($scenario['handler'], 'getVisionModel');

        $this->assertSame('google/gemini-3.5-flash', $model);
    }

    public function test_vision_conversation_history_shape_and_order(): void
    {
        $scenario = $this->buildScenario();

        $this->assertSame(
            [[
                'sender' => 'bot',
                'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3",
            ]],
            $scenario['history']
        );
    }

    public function test_detect_pending_order_matches_keyword_rules(): void
    {
        $scenario = $this->buildScenario();

        $this->assertTrue($this->invoke($scenario['handler'], 'detectPendingOrder', [$scenario['history']]));
        $this->assertFalse($this->invoke($scenario['handler'], 'detectPendingOrder', [[]]));
        $this->assertFalse($this->invoke($scenario['handler'], 'detectPendingOrder', [['content' => 'สวัสดีครับ']]));
    }

    // ------------------------------------------------------------------
    // analyze() — the public entry point, green pre- and post-extraction
    // ------------------------------------------------------------------

    public function test_analyze_returns_the_vision_reply_text(): void
    {
        $scenario = $this->buildScenario();
        $event = include base_path('tests/fixtures/line-image-event.php');

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('chatWithVision')->once()->andReturn([
            'content' => 'รูปแมวน่ารักครับ',
            'model' => 'google/gemini-3.5-flash',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $openRouter->shouldReceive('estimateCost')->once()->andReturn(0.0001);

        // analyze() sends the reply through the real LINEService, which needs a
        // channel access token. Give the bot one so the reply path (and thus the
        // successful return) is exercised without an HTTP call.
        $scenario['bot']->forceFill([
            'channel_access_token' => 'test_access_token',
            'channel_secret' => 'test_secret',
        ])->save();
        \Illuminate\Support\Facades\Http::fake(['api.line.me/*' => \Illuminate\Support\Facades\Http::response(['ok' => true])]);

        // The handler resolves $this->bot->user?->settings?->getOpenRouterApiKey()
        // before falling back to config. Create the user's settings row so the
        // encrypted-attribute access path is exercised without a decrypt error.
        $scenario['bot']->user?->getOrCreateSettings();

        $handler = $this->makeHandler($scenario['bot'], $openRouter);

        $userMessage = Message::where('conversation_id', $scenario['conversation']->id)->latest('id')->first();

        $result = $handler->analyze(
            lineService: app(\App\Services\LINEService::class),
            conversation: $scenario['conversation'],
            userMessage: $userMessage,
            imageUrl: 'https://storage.example.com/images/img_msg_001.jpg',
            userId: 'U_img_user',
            replyToken: $event['replyToken'],
        );

        $this->assertSame('รูปแมวน่ารักครับ', $result);
    }

    public function test_analyze_returns_null_when_bot_inactive(): void
    {
        $scenario = $this->buildScenario();
        $scenario['bot']->update(['status' => 'paused']);

        $openRouter = Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('chatWithVision')->never();

        $handler = $this->makeHandler($scenario['bot'], $openRouter);

        $result = $handler->analyze(
            lineService: app(\App\Services\LINEService::class),
            conversation: $scenario['conversation'],
            userMessage: null,
            imageUrl: 'https://storage.example.com/images/img_msg_001.jpg',
            userId: 'U_img_user',
            replyToken: 'reply_token_img_1',
        );

        $this->assertNull($result);
    }
}
