<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\RAGService;
use App\Services\StockGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * เส้นทางแชทหลักผ่าน AIService::generateResponse ทั้งสองผู้เรียก
 * (generateAndSaveResponse + ProcessAggregatedMessages) — เทสต์ชุดนี้คุม:
 * (1) LeakTailGuard ถูกเรียกหลัง GuardrailOutputSanitizer และติดธง leak_guard
 * (2) metadata.llm เก็บ generation_id + finish_reason (ค่าว่าง = null ไม่พัง)
 * (3) finish_reason ไม่ใช่ stop → log warning โดยไม่เปลี่ยนเนื้อหา
 */
class AIServiceLeakTailGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makeBotWithConversation(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'context_window' => 10]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line']);

        return [$bot, $conversation];
    }

    private function mockRagResult(array $overrides = []): void
    {
        $this->mock(RAGService::class, function ($m) use ($overrides) {
            $m->shouldReceive('generateResponse')->once()->andReturn(array_merge([
                'content' => 'BM ผูกบัตรหรือเติมเงินก็ได้ครับ',
                'model' => 'openai/gpt-6-luna',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ], $overrides));
        });
        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });
    }

    #[Test]
    public function strips_leaked_tail_and_sets_flag(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->mockRagResult(['content' => 'รับทราบครับพี่ เครดิตฟรี']);

        $result = app(AIService::class)->generateResponse($bot, 'ครับ', $conversation);

        $this->assertSame('รับทราบครับพี่', $result['content']);
        $this->assertSame('stripped', $result['leak_guard']);
    }

    #[Test]
    public function normal_answer_is_byte_identical_and_has_no_flag(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->mockRagResult();

        $result = app(AIService::class)->generateResponse($bot, 'BM ราคาเท่าไหร่ครับ', $conversation);

        $this->assertSame('BM ผูกบัตรหรือเติมเงินก็ได้ครับ', $result['content']);
        $this->assertArrayNotHasKey('leak_guard', $result);
    }

    #[Test]
    public function mid_sentence_case_keeps_bytes_and_logs_warning_without_content(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $original = 'ขออภัยครับ เครดิตฟรีสอบถามเพิ่มได้เลยครับ';
        $this->mockRagResult(['content' => $original]);
        Log::spy();

        $result = app(AIService::class)->generateResponse($bot, 'ครับ', $conversation);

        $this->assertSame($original, $result['content']);
        $this->assertSame('mid_sentence', $result['leak_guard']);
        Log::shouldHaveReceived('warning')->with('Leak tail guard event', [
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'event' => 'mid_sentence',
        ])->once();
    }

    #[Test]
    public function saves_llm_generation_id_and_finish_reason_to_metadata(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->mockRagResult([
            'id' => 'gen-abc-123',
            'finish_reason' => 'stop',
        ]);
        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'BM ราคาเท่าไหร่ครับ',
        ]);

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertSame('gen-abc-123', $botMessage->metadata['llm']['generation_id']);
        $this->assertSame('stop', $botMessage->metadata['llm']['finish_reason']);
    }

    #[Test]
    public function missing_llm_values_are_saved_as_null_without_breaking(): void
    {
        // cache / circuit breaker / support_router ไม่มี id/finish_reason → null ไม่พัง
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->mockRagResult();
        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'BM ราคาเท่าไหร่ครับ',
        ]);

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertNull($botMessage->metadata['llm']['generation_id']);
        $this->assertNull($botMessage->metadata['llm']['finish_reason']);
    }

    #[Test]
    public function leak_guard_flag_is_saved_to_message_metadata(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->mockRagResult(['content' => 'รับทราบครับพี่ เครดิตฟรี']);
        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'ครับ',
        ]);

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertSame('รับทราบครับพี่', $botMessage->content);
        $this->assertSame('stripped', $botMessage->metadata['leak_guard']);
    }

    #[Test]
    public function non_stop_finish_reason_logs_warning_without_changing_content(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->mockRagResult([
            'id' => 'gen-xyz',
            'finish_reason' => 'length',
        ]);
        Log::spy();

        $result = app(AIService::class)->generateResponse($bot, 'BM ราคาเท่าไหร่ครับ', $conversation);

        $this->assertSame('BM ผูกบัตรหรือเติมเงินก็ได้ครับ', $result['content']);
        Log::shouldHaveReceived('warning')->with('LLM finish_reason not stop', [
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'model' => 'openai/gpt-6-luna',
            'finish_reason' => 'length',
            'generation_id' => 'gen-xyz',
        ])->once();
    }

    #[Test]
    public function stop_or_missing_finish_reason_does_not_log_the_warning(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        $this->mockRagResult(['id' => 'gen-ok', 'finish_reason' => 'stop']);
        Log::spy();

        app(AIService::class)->generateResponse($bot, 'BM ราคาเท่าไหร่ครับ', $conversation);

        Log::shouldNotHaveReceived('warning');
    }
}
