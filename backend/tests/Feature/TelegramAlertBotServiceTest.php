<?php

namespace Tests\Feature;

use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAlertBotServiceTest extends TestCase
{
    public function test_send_message_uses_html_parse_mode(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramAlertBotService::class)->sendMessage('TOK', '999', '<b>hi</b>');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && ($r['parse_mode'] ?? null) === 'HTML');
    }

    public function test_edit_message_uses_html_parse_mode(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramAlertBotService::class)->editMessageText('TOK', '999', 5, '<b>hi</b>');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText')
            && ($r['parse_mode'] ?? null) === 'HTML');
    }

    public function test_esc_escapes_html_special_chars_and_null(): void
    {
        $this->assertSame('a&lt;b&gt; &amp; &quot;c&quot;', TelegramAlertBotService::esc('a<b> & "c"'));
        $this->assertSame('', TelegramAlertBotService::esc(null));
    }

    public function test_send_message_returns_the_telegram_result_when_accepted(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true, 'result' => ['message_id' => 4321],
        ])]);

        $result = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertSame(4321, $result['message_id'] ?? null);
    }

    public function test_send_message_returns_empty_array_when_telegram_omits_result(): void
    {
        // "สำเร็จ" คือ HTTP ตอบ ok — response ที่ไม่มี result ห้ามถูกตีความว่าล้มเหลว
        // (เทสต์ทั้งระบบ fake ด้วย ['ok' => true] เปล่าๆ ถ้าตีความผิดจะพังยกแผง)
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $result = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertSame([], $result);
    }

    public function test_send_message_returns_null_when_telegram_rejects_the_payload(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad'], 400)]);

        $result = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertNull($result);
    }

    public function test_send_message_returns_null_when_the_connection_times_out(): void
    {
        // เคสจริง 1 ส.ค. 2026: api.telegram.org ค้าง การ์ดปุ่มส่งของหายเงียบ
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $result = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertNull($result);
    }

    public function test_send_message_attaches_reply_target_that_survives_a_deleted_card(): void
    {
        // allow_sending_without_reply: ถ้าการ์ดใบแรกถูกลบไปแล้ว Telegram ต้องยังส่งใบเตือนออก
        // ไม่ใช่ปฏิเสธทั้งข้อความ (ใบเตือนตายเงียบอันตรายกว่าปุ่มค้าง)
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);

        app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi', null, 777);

        Http::assertSent(fn ($r) => ($r['reply_to_message_id'] ?? null) === 777
            && ($r['allow_sending_without_reply'] ?? null) === true
            && ! isset($r['reply_markup']));
    }

    public function test_alert_timeout_is_not_shorter_than_the_plugin_notifier(): void
    {
        // กันตั้ง timeout สั้นกว่า FlowPluginService (ใช้ค่าปริยาย Laravel = 30 วิ) อีก
        // ต้นเหตุที่การ์ดตายแต่ข้อความ "ออเดอร์ใหม่!" รอดในเหตุการณ์เดียวกัน
        $timeout = (new \ReflectionClassConstant(TelegramAlertBotService::class, 'TIMEOUT_SECONDS'))->getValue();

        $this->assertGreaterThanOrEqual(30, $timeout);
    }
}
