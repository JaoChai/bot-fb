<?php

namespace Tests\Feature\Payment;

use App\Models\Bot;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Payment\LLMOrderItemExtractor;
use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\SlipVerificationService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ชั้น 2: LLM fallback สำหรับดึง items เมื่อ regex (ชั้น 1) ได้ total แต่ items ว่าง
 * (prose ล้วน / หลายสินค้าบรรทัดเดียวไม่มีราคาต่อชิ้น).
 */
class LLMOrderItemFallbackTest extends TestCase
{
    use RefreshDatabase;

    // prose ล้วน: มี total ชัดเจนแต่ regex (ทั้ง primary bullet + fallback "name: total บาท")
    // ดึง items ไม่ได้ เพราะไม่มีบรรทัดต่อรายการ
    private const PROSE_SUMMARY = "สรุปออเดอร์ของพี่ครับ ได้แก่ Nolimit Level Up+ Personal และ Page รวมทั้งหมด 1,600 บาท ครับ\nรวมยอดโอน: 1,600 บาท\nโอนเข้าบัญชี 223-3-24880-3";

    // canonical: regex ดึง items ได้ปกติ (ไม่ควรต้อง fallback)
    private const CANONICAL_SUMMARY = "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3";

    private function history(string $summary): array
    {
        return [
            ['sender' => 'user', 'content' => 'สนใจครับ'],
            ['sender' => 'bot', 'content' => $summary],
        ];
    }

    private function makeBotWithUtilityModel(): Bot
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
        );
    }

    public function test_fallback_extracts_items_when_regex_finds_total_but_no_items(): void
    {
        $bot = $this->makeBotWithUtilityModel();

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->once())
            ->method('chat')
            ->willReturn([
                'content' => '{"items":[{"name":"Nolimit Level Up+ Personal","qty":1,"total":"1000"},{"name":"Page","qty":1,"total":"600"}]}',
            ]);

        $result = $this->service($openRouter)->findExpectedPayment($this->history(self::PROSE_SUMMARY), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(1600.0, $result['total']);
        $this->assertSame([
            ['name' => 'Nolimit Level Up+ Personal', 'qty' => 1, 'total' => '1000'],
            ['name' => 'Page', 'qty' => 1, 'total' => '600'],
        ], $result['items']);
        $this->assertSame('Nolimit Level Up+ Personal, Page', $result['summary']);
    }

    public function test_does_not_call_llm_when_regex_already_found_items(): void
    {
        $bot = $this->makeBotWithUtilityModel();

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)->findExpectedPayment($this->history(self::CANONICAL_SUMMARY), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(1500.0, $result['total']);
        $this->assertSame('Nolimit BM', $result['summary']);
    }

    public function test_does_not_call_llm_when_flag_disabled(): void
    {
        config(['delivery.llm_item_fallback_enabled' => false]);

        $bot = $this->makeBotWithUtilityModel();

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)->findExpectedPayment($this->history(self::PROSE_SUMMARY), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(1600.0, $result['total']);
        $this->assertSame([], $result['items']);
        $this->assertSame('-', $result['summary']);
    }

    public function test_skips_silently_when_no_utility_model_configured(): void
    {
        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['openrouter_api_key' => 'or-key-123']);
        $bot = Bot::factory()->create(['user_id' => $user->id]); // no primary/fallback/utility model set

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->never())->method('chat');

        $result = $this->service($openRouter)->findExpectedPayment($this->history(self::PROSE_SUMMARY), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(1600.0, $result['total']);
        $this->assertSame([], $result['items']);
        $this->assertSame('-', $result['summary']);
    }

    public function test_returns_empty_items_when_llm_response_is_malformed(): void
    {
        $bot = $this->makeBotWithUtilityModel();

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->method('chat')->willReturn(['content' => 'ขอโทษครับ ไม่สามารถช่วยได้']);

        $result = $this->service($openRouter)->findExpectedPayment($this->history(self::PROSE_SUMMARY), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(1600.0, $result['total']);
        $this->assertSame([], $result['items']);
        $this->assertSame('-', $result['summary']);
    }
}
