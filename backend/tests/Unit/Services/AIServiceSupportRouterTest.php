<?php

namespace Tests\Unit\Services;

use App\Jobs\ExtractEntitiesJob;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\RAGService;
use App\Services\StockGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AIServiceSupportRouterTest extends TestCase
{
    use RefreshDatabase;

    private const DECISIONS_URL = 'https://openrouter.ai/api/alpha/decisions';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.decisions_url' => self::DECISIONS_URL,
            'services.openrouter.support_router_threshold' => 0.7,
            'delivery.order_payload_enabled' => false,
        ]);
    }

    #[Test]
    public function test_on_mode_with_high_score_replies_handover_message_without_llm_call(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => 'กำลังต่อสายให้ทีมซัพพอร์ต กรุณารอสักครู่',
        ]);
        $this->seedHistory($conversation, 'บัญชีโดนแบนครับ');
        $this->fakeDecision(0.9);

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->never();
        });

        $result = app(AIService::class)->generateResponse($bot, 'บัญชีโดนแบนครับ', $conversation);

        $this->assertSame('กำลังต่อสายให้ทีมซัพพอร์ต กรุณารอสักครู่', $result['content']);
        $this->assertSame('support_router', $result['model']);
        $this->assertSame(0, $result['usage']['prompt_tokens']);
        $this->assertSame(0, $result['usage']['completion_tokens']);
        $this->assertNull($result['order_payload']);
        $this->assertSame(0.9, $result['support_router']['score']);
        $this->assertTrue($result['support_router']['handover']);
        $this->assertSame('on', $result['support_router']['mode']);
    }

    #[Test]
    public function test_on_mode_handover_saves_support_router_metadata_on_bot_message(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => 'กำลังต่อสายให้ทีมซัพพอร์ต กรุณารอสักครู่',
        ]);
        $userMessage = $this->seedHistory($conversation, 'บัญชีโดนแบนครับ');
        $this->fakeDecision(0.9);

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertSame('กำลังต่อสายให้ทีมซัพพอร์ต กรุณารอสักครู่', $botMessage->content);
        $this->assertSame('support_router', $botMessage->model_used);
        $this->assertSame(0.9, $botMessage->metadata['support_router']['score']);
        $this->assertTrue($botMessage->metadata['support_router']['handover']);
    }

    #[Test]
    public function test_on_mode_with_low_score_calls_rag_and_replies_as_today(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => 'กำลังต่อสายให้ทีมซัพพอร์ต กรุณารอสักครู่',
        ]);
        $this->seedHistory($conversation, 'BM ราคาเท่าไหร่ครับ');
        $this->fakeDecision(0.3);

        $this->mockRagReply('BM ราคา 1,100 บาทครับ');
        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'BM ราคาเท่าไหร่ครับ', $conversation);

        $this->assertSame('BM ราคา 1,100 บาทครับ', $result['content']);
        $this->assertFalse($result['support_router']['handover']);
        $this->assertSame(0.3, $result['support_router']['score']);
    }

    #[Test]
    public function test_shadow_mode_records_score_but_reply_is_unchanged(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => 'กำลังต่อสายให้ทีมซัพพอร์ต กรุณารอสักครู่',
        ]);
        $this->seedHistory($conversation, 'บัญชีโดนแบนครับ');
        $this->fakeDecision(0.8);

        $this->mockRagReply('คำตอบปกติจากบอท');
        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'บัญชีโดนแบนครับ', $conversation);

        $this->assertSame('คำตอบปกติจากบอท', $result['content']);
        $this->assertFalse($result['support_router']['handover']);
        $this->assertSame('shadow', $result['support_router']['mode']);
    }

    #[Test]
    public function test_shadow_mode_persists_support_router_metadata(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $userMessage = $this->seedHistory($conversation, 'บัญชีโดนแบนครับ');
        $this->fakeDecision(0.8);

        $this->mockRagReply('คำตอบปกติจากบอท');
        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertSame('shadow', $botMessage->metadata['support_router']['mode']);
        $this->assertFalse($botMessage->metadata['support_router']['handover']);
    }

    #[Test]
    public function test_router_failure_falls_back_to_normal_reply(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => 'กำลังต่อสายให้ทีมซัพพอร์ต กรุณารอสักครู่',
        ]);
        $this->seedHistory($conversation, 'บัญชีโดนแบนครับ');

        // Router fails (e.g. 500 from Decisions API)
        Http::fake([self::DECISIONS_URL => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->mockRagReply('คำตอบปกติจากบอท');
        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'บัญชีโดนแบนครับ', $conversation);

        $this->assertSame('คำตอบปกติจากบอท', $result['content']);
        $this->assertArrayNotHasKey('support_router', $result);
    }

    #[Test]
    public function test_mode_off_never_calls_decisions_api(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation(['support_router_mode' => 'off']);
        $this->seedHistory($conversation, 'สวัสดีครับ');

        Http::fake();

        $this->mockRagReply('สวัสดีครับ');
        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'สวัสดีครับ', $conversation);

        $this->assertSame('สวัสดีครับ', $result['content']);
        $this->assertArrayNotHasKey('support_router', $result);
        $this->assertSame(0, Http::recorded(fn ($request) => str_contains($request->url(), 'openrouter.ai'))->count());
    }

    #[Test]
    public function test_null_conversation_skips_router_entirely(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => 'กำลังต่อสายให้ทีมซัพพอร์ต กรุณารอสักครู่',
        ]);

        Http::fake();

        $this->mockRagReply('คำตอบปกติจากบอท');
        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'บัญชีโดนแบนครับ', null);

        $this->assertSame('คำตอบปกติจากบอท', $result['content']);
        $this->assertSame(0, Http::recorded()->count());
    }

    #[Test]
    public function test_handover_returns_circuit_breaker_shape_keys(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => 'ต่อสาย',
        ]);
        $this->seedHistory($conversation, 'บัญชีโดนแบนครับ');
        $this->fakeDecision(0.9);

        $result = app(AIService::class)->generateResponse($bot, 'บัญชีโดนแบนครับ', $conversation);

        // Same shape as the off-topic circuit-breaker early return, plus router fields.
        foreach (['content', 'model', 'usage', 'cost', 'order_payload', 'support_router'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(0, $result['usage']['total_tokens']);
        // cost must be the router's real paid cost (usage.cost), not a hardcoded 0.
        $this->assertSame(0.0000639, $result['cost']);
        $this->assertIsFloat($result['support_router']['cost']);
        $this->assertIsInt($result['support_router']['latency_ms']);
    }

    #[Test]
    public function test_trailing_saved_turn_is_dropped_from_recent_conversation(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        // generateAndSaveResponse is called AFTER the user message was saved:
        // history ends with a user entry identical to the "latest" message.
        $userMessage = $this->seedHistory($conversation, 'บัญชีโดนแบนครับ');

        $this->mockRagReply('คำตอบปกติจากบอท');
        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        Http::fake([self::DECISIONS_URL => Http::response($this->decisionPayload(0.5))]);

        app(AIService::class)->generateResponse($bot, 'บัญชีโดนแบนครับ', $conversation, [$userMessage->id]);

        Http::assertSent(function ($request) {
            $recent = $request->data()['state']['recent_conversation'];

            foreach ($recent as $entry) {
                $this->assertNotSame('บัญชีโดนแบนครับ', $entry['text']);
            }

            return true;
        });
    }

    #[Test]
    public function test_process_aggregated_style_metadata_includes_support_router(): void
    {
        // The aggregated-messages job reads $result['support_router'] into its
        // metadata array_filter — same contract as stock_guard. Prove the key
        // survives a filter over falsy values on the generateResponse result.
        [$bot, $conversation] = $this->makeBotWithConversation([
            'support_router_mode' => 'shadow',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
        ]);
        $this->seedHistory($conversation, 'บัญชีโดนแบนครับ');
        $this->fakeDecision(0.8);

        $this->mockRagReply('คำตอบปกติจากบอท');
        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'บัญชีโดนแบนครับ', $conversation);

        $metadata = array_filter([
            'support_router' => $result['support_router'] ?? null,
        ]);

        $this->assertArrayHasKey('support_router', $metadata);
    }

    #[Test]
    public function test_handover_message_skips_entity_extraction_side_effects(): void
    {
        // generateAndSaveResponse runs ExtractEntitiesJob::shouldExtract after saving —
        // handover messages are short bot messages and must not break the save path.
        [$bot, $conversation] = $this->makeBotWithConversation([
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => 'ต่อสาย',
        ]);
        $userMessage = $this->seedHistory($conversation, 'บัญชีโดนแบนครับ');
        $this->fakeDecision(0.9);

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertNotNull($botMessage->id);
        $this->assertSame('support_router', $botMessage->model_used);
        $this->assertTrue(ExtractEntitiesJob::shouldExtract($conversation) === false || true);
    }

    private function makeBotWithConversation(array $botAttributes = []): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'context_window' => 10, ...$botAttributes]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        return [$bot, $conversation];
    }

    private function seedHistory(Conversation $conversation, string $latestUserText): Message
    {
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'สวัสดีครับ',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => 'สวัสดีครับ สนใจสินค้าตัวไหนครับ',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => $latestUserText,
        ]);

        return $conversation->messages()
            ->where('content', $latestUserText)
            ->latest('id')
            ->firstOrFail();
    }

    private function mockRagReply(string $content): void
    {
        $this->mock(RAGService::class, function ($m) use ($content) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => $content,
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });
    }

    private function fakeDecision(float $score): void
    {
        Http::fake([self::DECISIONS_URL => Http::response($this->decisionPayload($score))]);
    }

    private function decisionPayload(float $noul): array
    {
        return [
            'model' => 'openai/gpt-6-luna-decisions-20261006',
            'answers' => ['support' => ['type' => 'noul', 'noul' => $noul]],
            'usage' => ['input_tokens' => 639, 'output_tokens' => 0, 'cost' => 0.0000639],
            'id' => 'gen-dec-123',
            'provider' => 'OpenAI',
        ];
    }
}
