<?php

namespace Tests\Feature\Payment;

use App\Jobs\ReserveAccountStock;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\OpenRouterService;
use App\Services\Payment\LLMOrderItemExtractor;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\Payment\OrderReconstructor;
use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\SlipVerificationResult;
use App\Services\Payment\SlipVerificationService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    /** prose ล้วน: regex ดึง items ไม่ได้เลย (เส้นทาง items ว่าง) แม้มี total ชัดเจน */
    private const PROSE_SUMMARY = "สรุปออเดอร์ของพี่ครับ ได้แก่ Nolimit Level Up+ Personal และ Page รวมทั้งหมด 1,600 บาท ครับ\nรวมยอดโอน: 1,600 บาท\nโอนเข้าบัญชี 223-3-24880-3";

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

    public function test_fail_open_when_llm_items_have_no_price(): void
    {
        // regex ดึง items ไม่ได้เลย (prose ล้วน → เส้นทาง items ว่าง) แล้ว LLM คืนรายการ
        // ที่ไม่มี total → itemsMatchTotal คืน null (ตรวจไม่ได้ ไม่ใช่ "ผิด") ต้อง fail-open:
        // เก็บชื่อไว้ใน summary และไม่ตีว่าเชื่อไม่ได้ (ออเดอร์ปกติที่ LLM ไม่คืนราคาต้องไม่ถูกหยุดทิ้ง)
        $bot = $this->makeBot();

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->once())
            ->method('chat')
            ->willReturn(['content' => '{"items":[{"name":"Nolimit Level Up+ Personal","qty":1}]}']);

        $result = $this->service($openRouter)->findExpectedPayment($this->history(self::PROSE_SUMMARY), null, $bot);

        $this->assertNotNull($result);
        $this->assertFalse($result['items_unreliable'], 'ตรวจไม่ได้ ≠ ผิด → ห้ามตีเป็นเชื่อไม่ได้');
        $this->assertNotSame('-', $result['summary'], 'fail-open เก็บชื่อที่ LLM ดึงได้ไว้ใน summary');
    }

    public function test_keeps_unreliable_when_llm_cannot_confirm_amount(): void
    {
        // regex อ่านใบสรุปนี้พังชัด (checksum=false: ได้ 50 ขณะยอดโอน 1,000) แล้ว LLM คืนรายการ
        // ที่ไม่มี total → ยืนยันยอดไม่ได้ (null) ทั้งที่ของเดิมผิดชัด ต้องคงสถานะ "เชื่อไม่ได้" ไว้
        // ไม่ใช่ปล่อย null มาลบหลักฐาน false → ระบบจะได้ไม่ส่งของไปโดยไม่มีใครตรวจจำนวน
        $bot = $this->makeBot();

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->expects($this->once())
            ->method('chat')
            ->willReturn(['content' => '{"items":[{"name":"G3D","qty":20}]}']);

        $result = $this->service($openRouter)->findExpectedPayment($this->history(self::BROKEN_SUMMARY), null, $bot);

        $this->assertNotNull($result);
        $this->assertSame(1000.0, $result['total'], 'ยอดโอนต้องยังใช้ยืนยันเงินได้');
        $this->assertTrue($result['items_unreliable'], 'หลักฐานว่าอ่านพังต้องไม่ถูก null ลบทิ้ง');
    }

    public function test_result_carries_items_unreliable_flag(): void
    {
        $result = new SlipVerificationResult(
            isSlip: true,
            passed: true,
            amount: 1000.0,
            orderSummary: '-',
            orderItems: [['name' => 'G3D (', 'total' => '50']],
            itemsUnreliable: true,
        );

        $this->assertTrue($result->itemsUnreliable);
    }

    public function test_result_defaults_items_unreliable_to_false(): void
    {
        $result = new SlipVerificationResult(isSlip: true, passed: true);

        $this->assertFalse($result->itemsUnreliable);
    }

    public function test_reserve_job_not_dispatched_when_items_unreliable(): void
    {
        Queue::fake();

        $bot = $this->makeBot();
        $bot->update(['auto_delivery_enabled' => true]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'channel_type' => 'line',
        ]);
        $slip = SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'amount' => 1000,
            'status' => 'passed',
        ]);

        $result = new SlipVerificationResult(
            isSlip: true, passed: true, amount: 1000.0,
            orderSummary: '-', orderItems: [['name' => 'G3D (', 'total' => '50']],
            itemsUnreliable: true,
        );
        $result->slipVerificationId = $slip->id;

        ReserveAccountStock::dispatchIfItemsTrusted($bot->id, $conversation->id, $result);

        Queue::assertNothingPushed();
    }

    public function test_reserve_job_dispatched_when_items_trusted(): void
    {
        Queue::fake();

        $bot = $this->makeBot();
        $bot->update(['auto_delivery_enabled' => true]);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'channel_type' => 'line',
        ]);
        $slip = SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'amount' => 1000,
            'status' => 'passed',
        ]);

        $result = new SlipVerificationResult(
            isSlip: true, passed: true, amount: 1000.0,
            orderSummary: 'G3D x20', orderItems: [['name' => 'G3D', 'total' => '1000', 'qty' => 20]],
        );
        $result->slipVerificationId = $slip->id;

        ReserveAccountStock::dispatchIfItemsTrusted($bot->id, $conversation->id, $result);

        Queue::assertPushed(ReserveAccountStock::class);
    }

    public function test_card_gives_one_instruction_when_items_unreliable(): void
    {
        $bot = $this->makeBot();
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งออเดอร์',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tg-token', 'chat_id' => '-100123'],
        ]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $result = new SlipVerificationResult(
            isSlip: true, passed: true, amount: 1000.0,
            orderSummary: 'G3D', itemsUnreliable: true,
        );

        app(SlipVerificationService::class)->notifyAdmin($bot->fresh(), $conversation, $result);

        Http::assertSent(function ($request) {
            $text = $request->data()['text'];

            return ! str_contains($text, 'ไม่ต้องทำอะไร')
                && str_contains($text, 'ยังไม่ได้ส่งของ');
        });
    }

    public function test_manual_confirm_alerts_owner_when_items_unreliable(): void
    {
        Queue::fake([ReserveAccountStock::class]);
        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"triggered": false}']]],
                'model' => 'openai/gpt-4o-mini',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]),
        ]);

        $bot = $this->makeBot();
        $bot->update(['auto_delivery_enabled' => true]);
        BotSetting::create([
            'bot_id' => $bot->id,
            'slip_receiver_account' => '223-3-24880-3',
        ]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งออเดอร์',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tg-token', 'chat_id' => '-100123'],
        ]);

        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'channel_type' => 'line',
            'external_customer_id' => 'U123',
        ]);
        // ใบสรุปที่ regex อ่านเพี้ยน: item 50 ขณะยอดโอน 1,000 → checksum fail → items_unreliable
        $conversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => self::BROKEN_SUMMARY,
        ]);

        app(ManualPaymentConfirmService::class)
            ->confirm($bot->fresh(), $conversation, 1000.0, $bot->user_id);

        // กติกา: รายการเชื่อไม่ได้ = ห้ามส่งของเอง → ห้ามจองสต๊อก
        Queue::assertNothingPushed();
        // เจ้าของต้องรู้ว่าต้องส่งเอง (LOG_LEVEL prod กลืน Log::warning → ต้องแจ้งผ่าน Telegram)
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'], 'ยังไม่ได้ส่งของ'));
    }
}
