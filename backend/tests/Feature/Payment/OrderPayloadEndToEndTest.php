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
}
