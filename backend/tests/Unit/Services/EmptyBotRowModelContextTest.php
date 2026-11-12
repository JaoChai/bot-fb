<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Message;
use App\Models\User;
use App\Services\EntityExtractionService;
use App\Services\FlowPluginService;
use App\Services\LeadRecoveryService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\OpenRouterService;
use App\Services\StickerReplyService;
use App\Services\Webhook\Channels\LINE\VisionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lead widening (2026-11-12, item 2): a Support-Router handover suppressed by
 * the 60 s dedupe is saved as a bot row with EMPTY content. Every builder that
 * turns conversation messages into model context must leave that row out, so
 * no model ever receives an empty turn. Each test here fails on main (the
 * empty row leaks into the context) and passes on the branch.
 */
class EmptyBotRowModelContextTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-4o-mini',
            'support_router_mode' => 'on',
            'support_handover_message' => 'กรุณาติดต่อทีมซัพพอร์ตที่ https://support.example.com/th ครับ',
        ]);
        $this->conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        // user turn, then the suppressed handover row (empty content), then a
        // real bot turn — the empty row sits INSIDE the context window.
        Message::factory()->fromUser()->create([
            'conversation_id' => $this->conversation->id,
            'content' => 'ล็อกอินไม่ได้ครับ',
            'created_at' => now()->subSeconds(30),
        ]);
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender' => 'bot',
            'content' => '',
            'model_used' => 'support_router',
            'created_at' => now()->subSeconds(20),
        ]);
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender' => 'bot',
            'content' => 'ลิงก์เข้าระบบ: https://example.com/login',
            'created_at' => now()->subSeconds(10),
        ]);
    }

    public function test_vision_webhook_history_leaves_out_empty_bot_rows(): void
    {
        // LineWebhookResponseService::getVisionConversationHistory feeds the
        // vision chat messages (l.268) and the slip-verification history.
        $service = app(LineWebhookResponseService::class);

        $method = new \ReflectionMethod($service, 'getVisionConversationHistory');
        $history = $method->invoke($service, $this->conversation, 15);

        $this->assertSame(
            ['ล็อกอินไม่ได้ครับ', 'ลิงก์เข้าระบบ: https://example.com/login'],
            array_column($history, 'content'),
        );
    }

    public function test_vision_handler_history_leaves_out_empty_bot_rows(): void
    {
        // VisionHandler::getVisionConversationHistory feeds assistant turns to
        // the vision model in analyze() (l.125-130).
        $handler = app(VisionHandler::class, ['bot' => $this->conversation->bot]);

        $method = new \ReflectionMethod($handler, 'getVisionConversationHistory');
        $history = $method->invoke($handler, $this->conversation);

        $this->assertSame(
            ['ล็อกอินไม่ได้ครับ', 'ลิงก์เข้าระบบ: https://example.com/login'],
            array_column($history, 'content'),
        );
    }

    public function test_sticker_reply_history_leaves_out_empty_bot_rows(): void
    {
        // StickerReplyService::getRecentHistory feeds assistant turns to the
        // AI sticker model (l.199-202).
        $service = app(StickerReplyService::class);

        $method = new \ReflectionMethod($service, 'getRecentHistory');
        $history = $method->invoke($service, $this->conversation);

        $this->assertSame(
            ['ล็อกอินไม่ได้ครับ', 'ลิงก์เข้าระบบ: https://example.com/login'],
            array_column($history, 'content'),
        );
    }

    public function test_entity_extraction_prompt_leaves_out_empty_bot_rows(): void
    {
        // EntityExtractionService::extractAndSave formats every message as
        // "{$role}: {$content}" and sends the transcript to the LLM (l.67-84).
        $captured = null;
        $this->mock(OpenRouterService::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('chat')->once()->andReturnUsing(function (...$args) use (&$captured) {
                $captured = $args[0];

                return ['content' => '{"entities": []}'];
            });
        });

        $result = app(EntityExtractionService::class)->extractAndSave($this->conversation);

        $this->assertSame([], $result['extracted']);
        $this->assertNotNull($captured, 'the LLM must be called with the transcript');
        $userPrompt = $captured[1]['content'];
        // no "บอท:" line with nothing after it (the empty turn's transcript line)
        $this->assertSame(0, preg_match('/^บอท:\s*$/m', $userPrompt));
        $this->assertStringContainsString('ลูกค้า: ล็อกอินไม่ได้ครับ', $userPrompt);
        $this->assertStringContainsString('บอท: ลิงก์เข้าระบบ: https://example.com/login', $userPrompt);
    }

    public function test_lead_recovery_context_leaves_out_empty_bot_rows(): void
    {
        // LeadRecoveryService::getConversationContext maps bot rows to
        // assistant turns for the AI follow-up prompt (l.287-303).
        $context = app(LeadRecoveryService::class)->getConversationContext($this->conversation, 5);

        $this->assertSame([
            ['role' => 'customer', 'content' => 'ล็อกอินไม่ได้ครับ'],
            ['role' => 'assistant', 'content' => 'ลิงก์เข้าระบบ: https://example.com/login'],
        ], $context);
    }

    public function test_flow_plugin_evaluation_prompt_leaves_out_empty_bot_rows(): void
    {
        // Reviewer round 1 item 1: FlowPluginService::evaluateAndExecute maps the
        // conversation context to "{$role}: {$msg->content}" and sends it inside
        // the plugin-evaluation prompt to openRouter->chat. Both entry points go
        // through that same map: executePlugins() (webhook path, live context)
        // and executePluginsSnapshot() (aggregated job, captured message ids).
        config(['services.openrouter.api_key' => 'test-key']);

        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'utility_model' => 'test/model',
            'support_router_mode' => 'on',
            'support_handover_message' => 'กรุณาติดต่อทีมซัพพอร์ตที่ https://support.example.com/th ครับ',
        ]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $flow->plugins()->create([
            'type' => 'telegram',
            'enabled' => true,
            'trigger_condition' => 'ลูกค้าต้องการติดต่อซัพพอร์ต',
            'config' => ['message_template' => 'no trigger keywords configured'],
        ]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'current_flow_id' => $flow->id,
        ]);

        // the reviewer's repro: user / bot URL reply / user / suppressed row '',
        // and the suppressed row is also the botMessage the plugins run for
        // (the webhook path runs plugins even when it sends nothing).
        $userFirst = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'ล็อกอินไม่ได้ครับ',
            'created_at' => now()->subSeconds(40),
        ]);
        $botUrlReply = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => 'กรุณาติดต่อ https://support.example.com/th ครับ',
            'model_used' => 'vision-model',
            'created_at' => now()->subSeconds(30),
        ]);
        $userSecond = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'รหัสผ่านไม่ถูกครับ',
            'created_at' => now()->subSeconds(20),
        ]);
        $suppressed = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => '',
            'model_used' => 'support_router',
            'created_at' => now()->subSeconds(10),
        ]);

        $captured = [];
        $this->mock(OpenRouterService::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('chat')->twice()->andReturnUsing(function (...$args) use (&$captured) {
                // args[0] is the messages array; [1] is the user prompt that
                // carries the transcript of the conversation context
                $captured[] = $args[0][1]['content'];

                return ['content' => '{"triggered": false}'];
            });
        });

        $service = app(FlowPluginService::class);
        $service->executePlugins($bot, $conversation, $suppressed);
        $service->executePluginsSnapshot(
            $bot,
            $conversation,
            $suppressed,
            $flow->id,
            [$userFirst->id, $botUrlReply->id, $userSecond->id, $suppressed->id],
        );

        $this->assertCount(2, $captured, 'both entry points must reach the evaluation LLM');
        foreach ($captured as $userPrompt) {
            // no empty "Assistant:" turn — the suppressed row must be dropped
            $this->assertSame(0, preg_match('/^Assistant:\s*$/m', $userPrompt));
            // non-empty lines keep their exact content and order
            $this->assertSame(
                [
                    'User: ล็อกอินไม่ได้ครับ',
                    'Assistant: กรุณาติดต่อ https://support.example.com/th ครับ',
                    'User: รหัสผ่านไม่ถูกครับ',
                ],
                array_values(preg_grep('/^(User|Assistant):/', explode("\n", $userPrompt))),
            );
        }
    }
}
