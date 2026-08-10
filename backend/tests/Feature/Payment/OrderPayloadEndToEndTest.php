<?php

namespace Tests\Feature\Payment;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\Payment\ManualPaymentConfirmService;
use App\Services\RAGService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPayloadEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function makeConversation(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'context_window' => 10]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line']);

        return [$bot, $conversation];
    }

    public function test_payload_block_never_reaches_saved_message_content(): void
    {
        config(['delivery.order_payload_enabled' => true]);
        [$bot, $conversation] = $this->makeConversation();

        $rag = $this->createMock(RAGService::class);
        $rag->method('generateResponse')->willReturn([
            'content' => "รวมยอดโอน: 1,000 บาท ✅\n"
                .'[[ORDER]]{"items":[{"name":"G3D","qty":20,"price":50}],"total":1000}[[/ORDER]]',
            'model' => 'openai/gpt-4o-mini',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);
        $this->app->instance(RAGService::class, $rag);

        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'ซื้อเฟสไก่ 20 ครับ',
        ]);

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertStringNotContainsString('[[ORDER]]', $botMessage->content);
        $this->assertStringContainsString('รวมยอดโอน: 1,000 บาท', $botMessage->content);
        $this->assertSame(20, $botMessage->metadata['order_payload']['items'][0]['qty']);
        // assertEquals ไม่ใช่ assertSame โดยตั้งใจ — metadata cast array ผ่าน JSON
        // ซึ่ง json_encode(1000.0) ให้ 1000 (ไม่มี .0) แล้ว decode คืน int
        // ค่าถูกต้องทางธุรกิจ ส่วน type ปลายทางถูก cast เป็น float ตอนใช้งานอยู่แล้ว
        $this->assertEquals(1000.0, $botMessage->metadata['order_payload']['total']);
    }

    public function test_flag_off_leaves_content_untouched(): void
    {
        config(['delivery.order_payload_enabled' => false]);
        [$bot, $conversation] = $this->makeConversation();

        $rag = $this->createMock(RAGService::class);
        $rag->method('generateResponse')->willReturn([
            'content' => 'สวัสดีครับ',
            'model' => 'openai/gpt-4o-mini',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]);
        $this->app->instance(RAGService::class, $rag);

        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'สวัสดี',
        ]);

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertSame('สวัสดีครับ', $botMessage->content);
        $this->assertArrayNotHasKey('order_payload', $botMessage->metadata ?? []);
    }

    public function test_history_carries_metadata_for_payment_lookup(): void
    {
        [$bot, $conversation] = $this->makeConversation();

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'type' => 'text',
            'content' => 'รวมยอดโอน: 1,000 บาท',
            'metadata' => ['order_payload' => ['items' => [['name' => 'G3D', 'qty' => 20, 'total' => '1000']], 'total' => 1000.0]],
        ]);

        $service = app(ManualPaymentConfirmService::class);
        $method = new \ReflectionMethod($service, 'recentTextHistory');
        $history = $method->invoke($service, $conversation);

        $this->assertArrayHasKey('metadata', $history[0]);
        $this->assertSame(20, $history[0]['metadata']['order_payload']['items'][0]['qty']);
    }

    public function test_payment_lookup_prefers_payload_over_regex(): void
    {
        $bot = Bot::factory()->create(['user_id' => User::factory()->create()->id]);

        // ข้อความเขียนจำนวนเป็นคำไทย "ยี่สิบตัว" — regex อ่าน qty ไม่ออกเลย
        // แต่ payload บอกจำนวนจริงไว้แล้ว เลยต้องเป็นแหล่งความจริงแทน regex
        $history = [[
            'sender' => 'bot',
            'content' => "G3D ยี่สิบตัว รวม 1,000 บาท\nรวมยอดโอน: 1,000 บาท\nโอนเข้าบัญชี 223-3-24880-3",
            'metadata' => ['order_payload' => [
                'items' => [['name' => 'G3D', 'qty' => 20, 'total' => '1000']],
                'total' => 1000.0,
            ]],
        ]];

        $expected = app(\App\Services\Payment\SlipVerificationService::class)
            ->findExpectedPayment($history, null, $bot);

        $this->assertNotNull($expected);
        $this->assertSame(1000.0, $expected['total']);
        $this->assertSame(20, $expected['items'][0]['qty']);
        $this->assertSame('G3D x20', $expected['summary']);
        $this->assertFalse($expected['items_unreliable']);
    }

    public function test_payment_lookup_falls_back_to_regex_without_payload(): void
    {
        $bot = Bot::factory()->create(['user_id' => User::factory()->create()->id]);

        $history = [[
            'sender' => 'bot',
            'content' => "1. G3D (50 x 20) = 1,000 บาท\nรวมยอดโอน: 1,000 บาท\nโอนเข้าบัญชี 223-3-24880-3",
            'metadata' => null,
        ]];

        $expected = app(\App\Services\Payment\SlipVerificationService::class)
            ->findExpectedPayment($history, null, $bot);

        $this->assertNotNull($expected);
        $this->assertSame(20, $expected['items'][0]['qty']);
    }

    public function test_payload_is_ignored_when_amount_filter_does_not_match(): void
    {
        // ใบสรุปหลายใบค้างพร้อมกัน: payload ที่ยอดไม่ตรงสลิปต้องถูกข้ามเหมือน regex path
        $bot = Bot::factory()->create(['user_id' => User::factory()->create()->id]);

        $history = [[
            'sender' => 'bot',
            'content' => "1. Page = 199 บาท\nรวมยอดโอน: 199 บาท\nโอนเข้าบัญชี 223-3-24880-3",
            'metadata' => ['order_payload' => [
                'items' => [['name' => 'Page', 'qty' => 1, 'total' => '199']],
                'total' => 199.0,
            ]],
        ]];

        $expected = app(\App\Services\Payment\SlipVerificationService::class)
            ->findExpectedPayment($history, null, $bot, 1000.0, 0.0);

        $this->assertNull($expected);
    }
}
