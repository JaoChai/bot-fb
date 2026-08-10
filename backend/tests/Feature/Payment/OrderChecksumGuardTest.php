<?php

namespace Tests\Feature\Payment;

use App\Models\Bot;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Payment\LLMOrderItemExtractor;
use App\Services\Payment\OrderReconstructor;
use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\SlipVerificationService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ตาข่าย checksum: ผลรวมรายการต้องเท่ายอดโอน ไม่งั้นถือว่า parse เชื่อไม่ได้
 * → ให้ LLM อ่านซ้ำ → ยังไม่ตรงอีก = ห้ามส่งของเอง (Task 3/4)
 */
class OrderChecksumGuardTest extends TestCase
{
    use RefreshDatabase;

    /** ใบสรุปที่ regex อ่านเพี้ยน: ได้ item ราคา 50 ขณะยอดโอน 1,000 (สร้างจากฟอร์แมตสมมติที่ยังไม่รองรับ) */
    private const BROKEN_SUMMARY = "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. G3D ราคา 50 บาท ต่อชิ้น รวม 20 ชิ้น\n\nรวมยอดโอน: 1,000 บาท ✅\nโอนเข้าบัญชี 223-3-24880-3";

    private function history(string $summary): array
    {
        return [
            ['sender' => 'user', 'content' => 'ซื้อเฟสไก่ 20 ครับ'],
            ['sender' => 'bot', 'content' => $summary],
        ];
    }

    private function makeBot(): Bot
    {
        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['openrouter_api_key' => 'or-key-123']);

        return Bot::factory()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-4o-mini',
            'utility_model' => 'openai/gpt-4o-mini',
        ]);
    }

    private function service(OpenRouterService $openRouter): SlipVerificationService
    {
        return new SlipVerificationService(
            new PaymentMessageDetector,
            new TelegramAlertBotService,
            new LLMOrderItemExtractor($openRouter),
            new OrderReconstructor($openRouter),
        );
    }

    public function test_calls_llm_when_item_sum_does_not_match_total(): void
    {
        $bot = $this->makeBot();

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->once())
            ->method('chat')
            ->willReturn(['content' => '{"items":[{"name":"G3D","qty":20,"total":"1000"}]}']);

        $result = $this->service($openRouter)->findExpectedPayment($this->history(self::BROKEN_SUMMARY), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(1000.0, $result['total']);
        $this->assertSame(20, $result['items'][0]['qty']);
        $this->assertSame('G3D x20', $result['summary']);
        $this->assertFalse($result['items_unreliable']);
    }

    public function test_marks_items_unreliable_when_llm_also_fails_checksum(): void
    {
        $bot = $this->makeBot();

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->method('chat')
            ->willReturn(['content' => '{"items":[{"name":"G3D","qty":1,"total":"50"}]}']);

        $result = $this->service($openRouter)->findExpectedPayment($this->history(self::BROKEN_SUMMARY), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(1000.0, $result['total'], 'ยอดโอนต้องยังใช้ยืนยันเงินได้');
        $this->assertTrue($result['items_unreliable'], 'รายการเชื่อไม่ได้ → ห้ามเอาไปส่งของเอง');
    }

    public function test_does_not_call_llm_when_checksum_passes(): void
    {
        $bot = $this->makeBot();
        $good = "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. G3D (50 บาท x 20) = 1,000 บาท\n\nรวมยอดโอน: 1,000 บาท ✅\nโอนเข้าบัญชี 223-3-24880-3";

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)->findExpectedPayment($this->history($good), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(20, $result['items'][0]['qty']);
        $this->assertFalse($result['items_unreliable']);
    }

    public function test_fail_open_when_checksum_cannot_be_computed(): void
    {
        // ราคาต่อรายการอ่านไม่ได้ (itemsMatchTotal คืน null) → ต้องไม่เรียก LLM และไม่ตีว่าเชื่อไม่ได้
        $bot = $this->makeBot();
        $noPrices = "สรุปรายการที่พี่สั่งซื้อครับ:\n\n1. G3D = 1,000 บาท\n\nรวมยอดโอน: 1,000 บาท\nโอนเข้าบัญชี 223-3-24880-3";

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)->findExpectedPayment($this->history($noPrices), null, $bot);

        $this->assertNotNull($result);
        $this->assertFalse($result['items_unreliable']);
    }
}
