<?php

namespace Tests\Feature\Payment;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Payment\LLMOrderItemExtractor;
use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\SlipVerificationService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fallback ชั้น 3 (เฉพาะ manual confirm): อ่านออเดอร์จากข้อความยืนยันขั้น 2
 * เมื่อไม่มีข้อความสรุปยอด+เลขบัญชีใน history (เคสลูกค้าโอนข้ามขั้นตอน — delivery #38)
 */
class ConfirmMessageFallbackTest extends TestCase
{
    use RefreshDatabase;

    // ข้อความยืนยันขั้น 2 ที่ regex ดึง items ได้ (รูปแบบ "name xN = ราคา บาท")
    private const CONFIRM_PARSEABLE = "สรุปตะกร้าครับ\nNolimit BM x2 = 2,200 บาท\nรวมทั้งหมด 2,200 บาท ถูกต้องไหมครับ? พิมพ์ ยืนยัน ได้เลยครับ";

    // ข้อความยืนยันขั้น 2 แบบ prose (เคสจริง delivery #38 แชท #92) — regex ดึง items ไม่ได้
    private const CONFIRM_PROSE = 'กัปตันแอดขอเช็คความถูกต้องอีกครั้งนะครับ: Nolimit Level Up+ Personal แบบผูกบัตร 2 ตัว รวม 2,200 บาท ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลยครับ';

    private function history(string $botMessage): array
    {
        return [
            ['sender' => 'user', 'content' => 'Nolimit Personal ผูกบัตร'],
            ['sender' => 'bot', 'content' => $botMessage],
            ['sender' => 'user', 'content' => 'โอนแล้วครับ'],
        ];
    }

    private function makeBot(float $tolerance = 0): Bot
    {
        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['openrouter_api_key' => 'or-key-123']);

        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-4o-mini',
            'utility_model' => 'openai/gpt-4o-mini',
        ]);
        BotSetting::create(['bot_id' => $bot->id, 'slip_amount_tolerance' => $tolerance]);

        return $bot;
    }

    private function service(OpenRouterService $openRouter): SlipVerificationService
    {
        return new SlipVerificationService(
            new PaymentMessageDetector,
            new TelegramAlertBotService,
            new LLMOrderItemExtractor($openRouter),
        );
    }

    public function test_returns_items_from_parseable_confirm_message(): void
    {
        $bot = $this->makeBot();
        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)
            ->findExpectedFromConfirmMessage($this->history(self::CONFIRM_PARSEABLE), $bot, 2200.0);

        $this->assertNotNull($result);
        $this->assertSame(2200.0, $result['total']);
        $this->assertSame('Nolimit BM', $result['items'][0]['name']);
        $this->assertSame(2, $result['items'][0]['qty']);
        $this->assertSame('Nolimit BM', $result['summary']);
    }

    public function test_returns_null_when_amount_mismatch(): void
    {
        $bot = $this->makeBot();
        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)
            ->findExpectedFromConfirmMessage($this->history(self::CONFIRM_PARSEABLE), $bot, 9999.0);

        $this->assertNull($result);
    }

    public function test_amount_within_tolerance_passes(): void
    {
        $bot = $this->makeBot(tolerance: 5);
        $openRouter = $this->createMock(OpenRouterService::class);

        $result = $this->service($openRouter)
            ->findExpectedFromConfirmMessage($this->history(self::CONFIRM_PARSEABLE), $bot, 2204.0);

        $this->assertNotNull($result);
        $this->assertSame('Nolimit BM', $result['summary']);
    }

    public function test_prose_confirm_uses_llm_extractor(): void
    {
        $bot = $this->makeBot();
        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->once())->method('chat')->willReturn([
            'content' => '{"items":[{"name":"Nolimit Level Up+ Personal","qty":2,"total":"2200"}]}',
        ]);

        $result = $this->service($openRouter)
            ->findExpectedFromConfirmMessage($this->history(self::CONFIRM_PROSE), $bot, 2200.0);

        $this->assertNotNull($result);
        $this->assertSame(2200.0, $result['total']);
        $this->assertSame(
            [['name' => 'Nolimit Level Up+ Personal', 'qty' => 2, 'total' => '2200']],
            $result['items'],
        );
        $this->assertSame('Nolimit Level Up+ Personal', $result['summary']);
    }

    public function test_returns_null_when_llm_cannot_extract(): void
    {
        $bot = $this->makeBot();
        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->method('chat')->willReturn(['content' => 'ขอโทษครับ ช่วยไม่ได้']);

        $result = $this->service($openRouter)
            ->findExpectedFromConfirmMessage($this->history(self::CONFIRM_PROSE), $bot, 2200.0);

        $this->assertNull($result);
    }

    public function test_returns_null_without_confirm_message(): void
    {
        $bot = $this->makeBot();
        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)->findExpectedFromConfirmMessage(
            $this->history('รับทราบครับ สนใจรุ่นไหนดีครับ'),
            $bot,
            2200.0,
        );

        $this->assertNull($result);
    }
}
