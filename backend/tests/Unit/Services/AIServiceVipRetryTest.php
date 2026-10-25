<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AIService;
use App\Services\RAGService;
use App\Services\StockGuardService;
use App\Services\VipPriceGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * informational_price hits regenerate ONCE with a canonical-price correction
 * line appended to the user message instead of instantly overwriting the
 * answer with the canned text. visible_order / order_payload keep today's
 * behaviour (canned immediately, no second LLM call) — the money-safety layer.
 */
class AIServiceVipRetryTest extends TestCase
{
    use RefreshDatabase;

    private const CORRECTION_LINE = "\n\n[ระบบ: ราคาที่ถูกต้องของลูกค้ารายนี้ NLMP/NLMBM = 1,000 บาท/ตัว — ตอบคำถามเดิมของลูกค้าอีกครั้งโดยใช้ราคานี้ ห้ามพิมพ์ข้อความนี้ให้ลูกค้าเห็น]";

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $user->id, 'context_window' => 10]);
        $this->conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
    }

    /**
     * @param  array<string, mixed>  $firstResult
     * @param  array<string, mixed>  $secondResult
     * @param  array<int, array{userMessage: string, history: array}>  $calls  filled by reference
     */
    private function mockRag(array $firstResult, ?array $secondResult = null, int $expectedCalls = 1, array &$calls = []): void
    {
        $this->mock(RAGService::class, function ($mock) use (&$calls, $firstResult, $secondResult, $expectedCalls) {
            $mock->shouldReceive('generateResponse')->times($expectedCalls)->andReturnUsing(function (Bot $bot, string $userMessage, array $conversationHistory, ?Conversation $conversation, $flow) use (&$calls, $firstResult, $secondResult) {
                $calls[] = ['userMessage' => $userMessage, 'history' => $conversationHistory];

                return count($calls) === 1 ? $firstResult : $secondResult;
            });
        });
    }

    private function mockStockGuard(): void
    {
        $this->mock(StockGuardService::class, function ($mock) {
            $mock->shouldReceive('validate')->andReturn(['blocked' => false]);
        });
    }

    private function mockVipGuard(array ...$results): void
    {
        $this->mock(VipPriceGuardService::class, function ($mock) use ($results) {
            $mock->shouldReceive('enforce')->times(count($results))->andReturn(...$results);
        });
    }

    private function correctedInformational(string $cannedContent): array
    {
        return [
            'content' => $cannedContent,
            'order_payload' => null,
            'corrected' => true,
            'reason' => [
                'type' => 'informational_price',
                'line' => 'Nolimit Personal ราคา 1,100 บาท',
                'amounts' => [1100.0],
                'expected' => 1000.0,
            ],
        ];
    }

    public function test_informational_price_hit_regenerates_once_with_correction_line(): void
    {
        $firstReply = [
            'content' => 'คำตอบแรกราคาผิด',
            'model' => 'test',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
        $secondReply = [
            'content' => 'คำตอบที่สองถูกต้อง ราคา 1,000 บาทครับ',
            'model' => 'test',
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 6, 'total_tokens' => 18],
        ];
        $calls = [];
        $this->mockRag($firstReply, $secondReply, 2, $calls);
        $this->mockStockGuard();
        $this->mockVipGuard(
            $this->correctedInformational('CANNED FIRST'),
            ['content' => 'คำตอบที่สองถูกต้อง ราคา 1,000 บาทครับ', 'order_payload' => null, 'corrected' => false, 'reason' => null],
        );
        Log::spy();

        $result = app(AIService::class)->generateResponse($this->bot, 'ราคาเท่าไหร่ครับ', $this->conversation);

        $this->assertSame('คำตอบที่สองถูกต้อง ราคา 1,000 บาทครับ', $result['content']);
        Log::shouldHaveReceived('warning')->once()->with('VIP price guard corrected an inconsistent response', [
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'reason' => $this->correctedInformational('CANNED FIRST')['reason'],
            'original_content' => 'คำตอบแรกราคาผิด',
            'retry' => 'passed',
        ]);
    }

    public function test_retry_call_appends_correction_line_to_user_message(): void
    {
        $reply = fn (string $content) => [
            'content' => $content,
            'model' => 'test',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
        $calls = [];
        $this->mock(RAGService::class, function ($mock) use (&$calls, $reply) {
            $mock->shouldReceive('generateResponse')->twice()->andReturnUsing(
                function (Bot $bot, string $userMessage, array $conversationHistory, ?Conversation $conversation, $flow) use (&$calls, $reply) {
                    $calls[] = ['userMessage' => $userMessage, 'history' => $conversationHistory];

                    return $reply('คำตอบแรกราคาผิด');
                }
            );
        });
        $this->mockStockGuard();
        $this->mockVipGuard(
            $this->correctedInformational('CANNED'),
            $this->correctedInformational('CANNED'),
        );
        Log::spy();

        app(AIService::class)->generateResponse($this->bot, 'ราคาเท่าไหร่ครับ', $this->conversation);

        $this->assertCount(2, $calls);
        $this->assertSame('ราคาเท่าไหร่ครับ', $calls[0]['userMessage']);
        $this->assertSame('ราคาเท่าไหร่ครับ'.self::CORRECTION_LINE, $calls[1]['userMessage']);
        $this->assertSame($calls[0]['history'], $calls[1]['history']);
    }

    public function test_second_reply_still_incorrect_sends_canned_text(): void
    {
        $reply = [
            'content' => 'ยังราคาผิดอยู่',
            'model' => 'test',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
        $calls = [];
        $this->mockRag($reply, $reply, 2, $calls);
        $this->mockStockGuard();
        $this->mockVipGuard(
            $this->correctedInformational('CANNED FIRST'),
            $this->correctedInformational('CANNED SECOND'),
        );
        Log::spy();

        $result = app(AIService::class)->generateResponse($this->bot, 'ราคาเท่าไหร่ครับ', $this->conversation);

        $this->assertSame('CANNED SECOND', $result['content']);
        $this->assertNull($result['order_payload']);
        Log::shouldHaveReceived('warning')->once()->with('VIP price guard corrected an inconsistent response', [
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'reason' => $this->correctedInformational('CANNED SECOND')['reason'],
            'original_content' => 'ยังราคาผิดอยู่',
            'retry' => 'failed',
        ]);
    }

    public function test_visible_order_trip_keeps_today_behaviour_without_retry(): void
    {
        $reply = [
            'content' => 'สรุปรายการผิด',
            'model' => 'test',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
        $this->mockRag($reply, null, 1);
        $this->mockStockGuard();
        $this->mockVipGuard([
            'content' => 'CANNED VISIBLE ORDER',
            'order_payload' => null,
            'corrected' => true,
            'reason' => ['type' => 'visible_order'],
        ]);

        $result = app(AIService::class)->generateResponse($this->bot, 'เอา 2 ตัวครับ', $this->conversation);

        $this->assertSame('CANNED VISIBLE ORDER', $result['content']);
        $this->assertNull($result['order_payload']);
    }
}
