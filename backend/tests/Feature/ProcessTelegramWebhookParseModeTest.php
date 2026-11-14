<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramWebhook;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AIService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด outage-settings B2 (F18) — ข้อความที่เจ้าของตั้งเองได้ต้องเป็น plain text
 *
 * 1) คำตอบตอนบอทล่ม (แถว tag model_error = auth) ถูกส่งผ่านจุดตอบกลับของ
 *    ProcessTelegramWebhook โดย "ไม่มี parse_mode" เพื่อให้ <, >, & ไปถึงลูกค้าตรงตัว
 * 2) คำตอบปกติและ transport ปกติยังส่ง parse_mode = HTML เหมือนเดิมทุกอย่าง
 */
class ProcessTelegramWebhookParseModeTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH_TEXT = 'ระบบขัดข้องชั่วคราวครับ 🙏 เดี๋ยวแอดมินรีบตรวจสอบให้นะครับ';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function test_down_reply_without_parse_mode_keeps_ordinary_reply_with_html(): void
    {
        $bot = $this->makeTelegramBot();
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
        $userMessage = Message::factory()->fromUser()->create(['conversation_id' => $conversation->id, 'content' => 'สวัสดีครับ']);

        // คำตอบตอนล่ม: แถวติด tag model_error = auth → ส่งแบบไม่มี parse_mode
        $downRow = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => self::AUTH_TEXT,
            'type' => 'text',
            'metadata' => ['model_error' => 'auth'],
        ]);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateAndSaveResponse')->once()->andReturn($downRow);

        $this->invokeReplySite($bot, $conversation, $userMessage, $ai, '777');

        $downRequests = $this->sendMessageRequests('777');
        $this->assertCount(1, $downRequests, 'the down reply must be sent exactly once');
        $this->assertSame(self::AUTH_TEXT, $downRequests[0]['text']);
        $this->assertArrayNotHasKey('parse_mode', $downRequests[0], 'auth down reply must not carry parse_mode');

        // คำตอบปกติ: แถวไม่ติด tag → parse_mode = HTML เหมือนเดิม
        $normalRow = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => '<b>สวัสดี</b> มีสินค้าครับ',
            'type' => 'text',
            'metadata' => null,
        ]);
        $ai2 = Mockery::mock(AIService::class);
        $ai2->shouldReceive('generateAndSaveResponse')->once()->andReturn($normalRow);

        $this->invokeReplySite($bot, $conversation, $userMessage, $ai2, '888');

        $normalRequests = $this->sendMessageRequests('888');
        $this->assertCount(1, $normalRequests, 'the ordinary reply must be sent exactly once');
        $this->assertSame('HTML', $normalRequests[0]['parse_mode'], 'ordinary reply must keep parse_mode=HTML');
    }

    #[Test]
    public function test_the_transport_supports_an_explicit_null_parse_mode_option(): void
    {
        $bot = $this->makeTelegramBot();

        // ปกติ: ไม่ส่ง options → parse_mode = HTML
        app(TelegramService::class)->sendMessage($bot, '111', '<b>hi</b>');
        // ตัวเลือก plain text: parse_mode เป็น null แบบชัดเจน → ตัดคีย์ออกจาก payload
        app(TelegramService::class)->sendMessage($bot, '222', '1 < 2 & ok', ['parse_mode' => null]);

        $html = $this->sendMessageRequests('111');
        $this->assertCount(1, $html);
        $this->assertSame('HTML', $html[0]['parse_mode']);

        $plain = $this->sendMessageRequests('222');
        $this->assertCount(1, $plain);
        $this->assertArrayNotHasKey('parse_mode', $plain[0], 'an explicit null parse_mode must remove the key');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeTelegramBot(): Bot
    {
        return Bot::factory()->active()->create([
            'channel_type' => 'telegram',
            'channel_access_token' => 'tg-token',
        ]);
    }

    /**
     * เรียกจุดตอบกลับจริงของ ProcessTelegramWebhook (generateAIResponse)
     * โดยแทน AIService ด้วย mock ที่คืนแถวคำตอบที่เตรียมไว้
     */
    private function invokeReplySite(Bot $bot, Conversation $conversation, Message $userMessage, AIService $ai, string $chatId): void
    {
        $job = new ProcessTelegramWebhook($bot, ['update_id' => 1]);

        $method = new \ReflectionMethod($job, 'generateAIResponse');
        $method->invoke($job, $conversation, $userMessage, $ai, app(TelegramService::class), $chatId);
    }

    /** @return list<array<string, mixed>> */
    private function sendMessageRequests(string $chatId): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => str_contains($request->url(), 'api.telegram.org')
                && str_contains($request->url(), 'sendMessage'))
            ->filter(function ($request) use ($chatId) {
                $body = $request->data();

                return (string) ($body['chat_id'] ?? '') === $chatId;
            })
            ->map(fn ($request) => $request->data())
            ->values()
            ->all();
    }
}
