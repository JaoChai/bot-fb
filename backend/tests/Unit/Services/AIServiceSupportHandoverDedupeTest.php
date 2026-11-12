<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\RAGService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Support handover dedupe: when the bot already pointed the customer to
 * Support within the last 60 s of the same conversation, the Support Router's
 * handover reply is returned with EMPTY content (+ duplicate_suppressed flag)
 * so both consumers (generateAndSaveResponse / ProcessAggregatedMessages)
 * deliver nothing for that turn. The router decision itself is unchanged.
 */
class AIServiceSupportHandoverDedupeTest extends TestCase
{
    use RefreshDatabase;

    private const DECISIONS_URL = 'https://openrouter.ai/api/alpha/decisions';

    private const HANDOVER_MESSAGE = 'กรุณาติดต่อทีมซัพพอร์ตที่ https://support.example.com/th ครับ';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.decisions_url' => self::DECISIONS_URL,
            'services.openrouter.support_router_threshold' => 0.7,
            'delivery.order_payload_enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function test_recent_bot_message_containing_handover_url_suppresses_duplicate_handover(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $recent = $this->seedBotMessage($conversation, [
            'content' => 'กรุณาติดต่อ https://support.example.com/th ครับ',
            'model_used' => 'vision-model',
            'created_at' => now()->subSeconds(10),
        ]);
        $this->seedCurrentUserMessage($conversation, 'รหัสผ่านไม่ถูกครับ');
        $this->fakeDecision(0.9);
        $this->mockRagNeverCalled();
        Log::spy();

        $result = app(AIService::class)->generateResponse($bot, 'รหัสผ่านไม่ถูกครับ', $conversation);

        $this->assertSame('', $result['content']);
        $this->assertSame('support_router', $result['model']);
        $this->assertTrue($result['support_router']['duplicate_suppressed']);
        $this->assertTrue($result['support_router']['handover']);
        // the suppression log carries ids only — no customer text
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($recent, $bot, $conversation) {
            return $message === 'Support router: duplicate handover suppressed'
                && $context['bot_id'] === $bot->id
                && $context['conversation_id'] === $conversation->id
                && $context['earlier_message_id'] === $recent->id;
        });
    }

    #[Test]
    public function test_recent_router_handover_reply_suppresses_duplicate_even_without_url(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->seedBotMessage($conversation, [
            'content' => 'กำลังต่อสายให้ทีมซัพพอร์ต กรุณารอสักครู่',
            'model_used' => 'support_router',
            'created_at' => now()->subSeconds(10),
        ]);
        $this->seedCurrentUserMessage($conversation, 'ยังไม่ได้ครับ');
        $this->fakeDecision(0.9);
        $this->mockRagNeverCalled();

        $result = app(AIService::class)->generateResponse($bot, 'ยังไม่ได้ครับ', $conversation);

        $this->assertSame('', $result['content']);
        $this->assertTrue($result['support_router']['duplicate_suppressed']);
    }

    #[Test]
    public function test_bot_message_older_than_60s_still_replies_handover_text(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->seedBotMessage($conversation, [
            'content' => 'กรุณาติดต่อ https://support.example.com/th ครับ',
            'model_used' => 'vision-model',
            'created_at' => now()->subSeconds(61),
        ]);
        $this->seedCurrentUserMessage($conversation, 'รหัสผ่านไม่ถูกครับ');
        $this->fakeDecision(0.9);
        $this->mockRagNeverCalled();

        $result = app(AIService::class)->generateResponse($bot, 'รหัสผ่านไม่ถูกครับ', $conversation);

        $this->assertSame(self::HANDOVER_MESSAGE, $result['content']);
        $this->assertArrayNotHasKey('duplicate_suppressed', $result['support_router']);
    }

    #[Test]
    public function test_recent_bot_message_without_support_reference_replies_handover_text(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->seedBotMessage($conversation, [
            'content' => 'BM ราคา 1,100 บาทครับ',
            'model_used' => 'test',
            'created_at' => now()->subSeconds(10),
        ]);
        $this->seedCurrentUserMessage($conversation, 'รหัสผ่านไม่ถูกครับ');
        $this->fakeDecision(0.9);
        $this->mockRagNeverCalled();

        $result = app(AIService::class)->generateResponse($bot, 'รหัสผ่านไม่ถูกครับ', $conversation);

        $this->assertSame(self::HANDOVER_MESSAGE, $result['content']);
        $this->assertArrayNotHasKey('duplicate_suppressed', $result['support_router']);
    }

    #[Test]
    public function test_no_earlier_bot_message_replies_handover_text(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->seedCurrentUserMessage($conversation, 'รหัสผ่านไม่ถูกครับ');
        $this->fakeDecision(0.9);
        $this->mockRagNeverCalled();

        $result = app(AIService::class)->generateResponse($bot, 'รหัสผ่านไม่ถูกครับ', $conversation);

        $this->assertSame(self::HANDOVER_MESSAGE, $result['content']);
        $this->assertArrayNotHasKey('duplicate_suppressed', $result['support_router']);
    }

    #[Test]
    public function test_recent_user_message_containing_url_does_not_suppress(): void
    {
        // only bot rows count — a user pasting the Support URL never suppresses
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->seedBotMessage($conversation, [
            'content' => 'กรุณาติดต่อ https://support.example.com/th ครับ',
            'model_used' => 'vision-model',
            'created_at' => now()->subSeconds(10),
            'sender' => 'user',
        ]);
        $this->seedCurrentUserMessage($conversation, 'รหัสผ่านไม่ถูกครับ');
        $this->fakeDecision(0.9);
        $this->mockRagNeverCalled();

        $result = app(AIService::class)->generateResponse($bot, 'รหัสผ่านไม่ถูกครับ', $conversation);

        $this->assertSame(self::HANDOVER_MESSAGE, $result['content']);
        $this->assertArrayNotHasKey('duplicate_suppressed', $result['support_router']);
    }

    #[Test]
    public function test_matching_bot_message_in_another_conversation_does_not_suppress(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $otherConversation = Conversation::factory()->create(['bot_id' => $bot->id]);
        $this->seedBotMessage($otherConversation, [
            'content' => 'กรุณาติดต่อ https://support.example.com/th ครับ',
            'model_used' => 'vision-model',
            'created_at' => now()->subSeconds(10),
        ]);
        $this->seedCurrentUserMessage($conversation, 'รหัสผ่านไม่ถูกครับ');
        $this->fakeDecision(0.9);
        $this->mockRagNeverCalled();

        $result = app(AIService::class)->generateResponse($bot, 'รหัสผ่านไม่ถูกครับ', $conversation);

        $this->assertSame(self::HANDOVER_MESSAGE, $result['content']);
        $this->assertArrayNotHasKey('duplicate_suppressed', $result['support_router']);
    }

    #[Test]
    public function test_suppressed_handover_saves_empty_bot_row_with_duplicate_suppressed_metadata(): void
    {
        // through generateAndSaveResponse: the saved row carries the flag and its
        // content is empty — the delivery gate in LineWebhookResponseService (l.119,
        // `if ($botMessage->content)`) then sends NOTHING to the customer.
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->seedBotMessage($conversation, [
            'content' => 'กรุณาติดต่อ https://support.example.com/th ครับ',
            'model_used' => 'vision-model',
            'created_at' => now()->subSeconds(10),
        ]);
        $userMessage = $this->seedCurrentUserMessage($conversation, 'รหัสผ่านไม่ถูกครับ');
        $this->fakeDecision(0.9);

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertSame('', $botMessage->content);
        $this->assertFalse((bool) $botMessage->content); // the exact delivery-gate condition → nothing sent
        $this->assertSame('support_router', $botMessage->model_used);
        $this->assertTrue($botMessage->metadata['support_router']['duplicate_suppressed']);
    }

    #[Test]
    public function test_get_conversation_history_leaves_out_empty_bot_rows(): void
    {
        // Lead widening item 1: the suppressed handover row (content '') must
        // never reach the chat model as an empty turn. Non-empty rows keep
        // their order; only the empty row is dropped.
        [$bot, $conversation] = $this->makeBotWithConversation();
        Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'สอบถามครับ',
            'created_at' => now()->subSeconds(30),
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => '',
            'model_used' => 'support_router',
            'created_at' => now()->subSeconds(20),
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => 'กรุณาติดต่อ https://support.example.com/th ครับ',
            'model_used' => 'vision-model',
            'created_at' => now()->subSeconds(10),
        ]);

        $method = new \ReflectionMethod(AIService::class, 'getConversationHistory');
        $history = $method->invoke(app(AIService::class), $conversation, 10);

        $this->assertSame([
            ['sender' => 'user', 'content' => 'สอบถามครับ'],
            ['sender' => 'bot', 'content' => 'กรุณาติดต่อ https://support.example.com/th ครับ'],
        ], $history);
    }

    #[Test]
    public function test_suppression_chain_pins_empty_rows_never_count_and_exact_60s_boundary(): void
    {
        // Reviewer round 1 item 2: pins the "content is non-empty" half of the
        // rule. Rows are written by the REAL code through generateAndSaveResponse
        // under a travelled clock (no hand-set created_at — a hand-set timestamp
        // could cross the boundary for timezone reasons). A suppressed row
        // (model_used = support_router, content '') must NOT itself count as
        // pointing to Support: the handover text comes back the moment the last
        // row that really carried text is older than 60 s, even while suppressed
        // rows still sit inside the window, and the fresh handover then counts
        // again. The exact boundary is pinned in the same chain: 59/60 s inside,
        // 61 s outside.
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->mockRagNeverCalled();
        $this->fakeDecision(0.9);

        $t0 = Carbon::now();
        Carbon::setTestNow($t0);

        // T0: no earlier bot row — the handover text goes out and is saved by
        // the real code (support_router, non-empty, carries the handover URL).
        $first = $this->handoverTurn($bot, $conversation, 'ล็อกอินไม่ได้ครับ');
        $this->assertSame(self::HANDOVER_MESSAGE, $first->content);
        $this->assertArrayNotHasKey('duplicate_suppressed', $first->metadata['support_router']);

        $steps = [
            10 => 'suppressed', // first duplicate turn inside the window
            50 => 'suppressed', // second suppressed turn — '' rows never count
            59 => 'suppressed', // boundary: 59 s old is inside the window
            60 => 'suppressed', // boundary: exactly 60 s old is still inside
            61 => 'text',       // boundary: 61 s old is outside -> fresh handover text
            80 => 'suppressed', // the fresh handover (+61 text row) counts again
            141 => 'text',      // '' rows never extend the window: text comes back
        ];

        foreach ($steps as $seconds => $expected) {
            Carbon::setTestNow($t0->copy()->addSeconds($seconds));
            $botMessage = $this->handoverTurn($bot, $conversation, "ยังไม่ได้ครับ (t+{$seconds})");

            if ($expected === 'suppressed') {
                $this->assertSame('', $botMessage->content, "at t+{$seconds}s the duplicate handover must be suppressed");
                $this->assertTrue(
                    $botMessage->metadata['support_router']['duplicate_suppressed'],
                    "at t+{$seconds}s duplicate_suppressed must be set",
                );
            } else {
                $this->assertSame(self::HANDOVER_MESSAGE, $botMessage->content, "at t+{$seconds}s the handover text must come back");
                $this->assertArrayNotHasKey(
                    'duplicate_suppressed',
                    $botMessage->metadata['support_router'],
                    "at t+{$seconds}s the fresh handover must not carry the key",
                );
            }
        }
    }

    private function handoverTurn(Bot $bot, Conversation $conversation, string $userText): Message
    {
        $userMessage = $this->seedCurrentUserMessage($conversation, $userText);

        return app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);
    }

    private function makeBotWithConversation(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'context_window' => 10,
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => self::HANDOVER_MESSAGE,
        ]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        return [$bot, $conversation];
    }

    private function seedBotMessage(Conversation $conversation, array $attributes): Message
    {
        return Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            ...$attributes,
        ]);
    }

    private function seedCurrentUserMessage(Conversation $conversation, string $content): Message
    {
        return Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => $content,
        ]);
    }

    private function mockRagNeverCalled(): void
    {
        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->never();
        });
    }

    private function fakeDecision(float $score): void
    {
        Http::fake([
            self::DECISIONS_URL => function ($request) use ($score) {
                if (isset($request->data()['questions']['buying'])) {
                    return Http::response([
                        'model' => 'openai/gpt-6-luna-decisions-20261006',
                        'answers' => ['buying' => ['type' => 'noul', 'noul' => 0.25]],
                        'usage' => ['input_tokens' => 31, 'output_tokens' => 0, 'cost' => 0.0000021],
                        'id' => 'gen-dec-buy',
                        'provider' => 'OpenAI',
                    ]);
                }

                return Http::response($this->decisionPayload($score));
            },
        ]);
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
