<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AIService;
use App\Services\Guardrail\OffTopicCircuitBreaker;
use App\Services\RAGService;
use App\Services\StockGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AIServiceGuardrailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_circuit_breaker_skips_llm_call_when_already_tripped(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        Cache::put(OffTopicCircuitBreaker::cacheKey($bot->id, $conversation->id), OffTopicCircuitBreaker::THRESHOLD, 86400);

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->never();
        });

        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->never();
        });

        $result = app(AIService::class)->generateResponse($bot, 'เขียนโค้ดให้หน่อย', $conversation);

        $this->assertSame(OffTopicCircuitBreaker::CANNED_MESSAGE, $result['content']);
        $this->assertSame(0, $result['usage']['total_tokens']);
        $this->assertTrue($result['off_topic_triggered']);
    }

    #[Test]
    public function test_circuit_breaker_trip_logs_a_warning(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();
        Cache::put(OffTopicCircuitBreaker::cacheKey($bot->id, $conversation->id), OffTopicCircuitBreaker::THRESHOLD, 86400);

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->never();
        });

        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->never();
        });

        Log::shouldReceive('warning')
            ->once()
            ->with('Off-topic circuit breaker tripped', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation->id,
            ]);

        app(AIService::class)->generateResponse($bot, 'เขียนโค้ดให้หน่อย', $conversation);
    }

    #[Test]
    public function test_off_topic_marker_is_stripped_from_content_and_increments_counter(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => "ขอโทษครับพี่ อันนี้ผมช่วยไม่ได้ตรงนี้ครับ สนใจสินค้าตัวไหนดีครับ?\n[[OFFTOPIC]]",
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });

        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'แปลภาษาให้หน่อย', $conversation);

        $this->assertStringNotContainsString('[[OFFTOPIC]]', $result['content']);
        $this->assertTrue($result['off_topic_triggered']);
        $this->assertSame(1, Cache::get(OffTopicCircuitBreaker::cacheKey($bot->id, $conversation->id)));
    }

    #[Test]
    public function test_guard_content_is_applied_even_when_not_blocked(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => 'BM ราคา 1,100 บาทครับ',
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });

        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn([
                'content' => "BM ราคา 1,100 บาทครับ\n\nตอนนี้ BM หมดสต็อกชั่วคราวครับ",
                'blocked' => false,
                'blocked_products' => [],
            ]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'BM ราคาเท่าไหร่ครับ', $conversation);

        $this->assertStringContainsString('หมดสต็อกชั่วคราว', $result['content']);
        $this->assertFalse($result['stock_guard']['blocked']);
        $this->assertSame('BM ราคา 1,100 บาทครับ', $result['stock_guard']['original_preview']);
    }

    #[Test]
    public function test_normal_response_does_not_increment_off_topic_counter(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => 'BM ราคา 1,100 บาทครับ',
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });

        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'BM ราคาเท่าไหร่ครับ', $conversation);

        $this->assertFalse($result['off_topic_triggered']);
        $this->assertNull(Cache::get(OffTopicCircuitBreaker::cacheKey($bot->id, $conversation->id)));
    }

    #[Test]
    public function test_output_sanitizer_replaces_flagged_content_before_returning(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => "```python\nprint('leaked')\n```",
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });

        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'เขียนโค้ดให้หน่อย', $conversation);

        $this->assertSame(OffTopicCircuitBreaker::CANNED_MESSAGE, $result['content']);
        $this->assertStringNotContainsString('print', $result['content']);
    }

    #[Test]
    public function test_sanitizer_nulls_order_payload_when_flagging_content(): void
    {
        // order_payload ถูกดึงออกมาก่อนที่ sanitizer จะรัน — ถ้าไม่ null ทิ้งด้วย
        // ลูกค้าจะเห็นข้อความปฏิเสธ แต่ระบบบันทึกออเดอร์ไว้เงียบๆ ที่ลูกค้าไม่เคยเห็น/ยืนยัน
        config(['delivery.order_payload_enabled' => true]);
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => "```python\nprint('leaked')\n```\n[[ORDER]]{\"items\":[{\"name\":\"BM\",\"qty\":1,\"price\":1100}],\"total\":1100}[[/ORDER]]",
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });

        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'เขียนโค้ดให้หน่อย', $conversation);

        $this->assertSame(OffTopicCircuitBreaker::CANNED_MESSAGE, $result['content']);
        $this->assertNull($result['order_payload']);
    }

    #[Test]
    public function test_marker_only_content_returns_canned_message_not_empty_string(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => '[[OFFTOPIC]]',
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });

        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'แปลภาษาให้หน่อย', $conversation);

        $this->assertSame(OffTopicCircuitBreaker::CANNED_MESSAGE, $result['content']);
        $this->assertTrue($result['off_topic_triggered']);
    }

    #[Test]
    public function test_circuit_breaker_and_sanitizer_are_skipped_when_conversation_is_null(): void
    {
        // testBotConfiguration() / เทสต์ prompt แบบ conversation: null (ไม่ผ่านลูกค้า ไม่เขียน DB)
        // ต้องไม่พัง แม้ไม่มี conversation ให้ผูก counter
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $this->mock(RAGService::class, function ($m) {
            $m->shouldReceive('generateResponse')->once()->andReturn([
                'content' => "ขอโทษครับพี่ อันนี้ผมช่วยไม่ได้ตรงนี้ครับ สนใจสินค้าตัวไหนดีครับ?\n[[OFFTOPIC]]",
                'model' => 'test',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]);
        });

        $this->mock(StockGuardService::class, function ($m) {
            $m->shouldReceive('validate')->andReturn(['blocked' => false]);
        });

        $result = app(AIService::class)->generateResponse($bot, 'แปลภาษาให้หน่อย', null);

        $this->assertStringNotContainsString('[[OFFTOPIC]]', $result['content']);
        $this->assertTrue($result['off_topic_triggered']);
    }

    private function makeBotWithConversation(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'context_window' => 10]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        return [$bot, $conversation];
    }
}
