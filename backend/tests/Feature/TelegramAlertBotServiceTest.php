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

    public function test_send_message_returns_true_when_telegram_accepts(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $sent = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertTrue($sent);
    }

    public function test_send_message_returns_false_when_telegram_rejects_the_payload(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad'], 400)]);

        $sent = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertFalse($sent);
    }

    public function test_send_message_returns_false_when_the_connection_times_out(): void
    {
        // เคสจริง 1 ส.ค. 2026: api.telegram.org ค้าง การ์ดปุ่มส่งของหายเงียบ
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $sent = app(TelegramAlertBotService::class)->sendMessage('TOK', '999', 'hi');

        $this->assertFalse($sent);
    }

    public function test_alert_timeout_is_not_shorter_than_the_plugin_notifier(): void
    {
        // กันตั้ง timeout สั้นกว่า FlowPluginService (ใช้ค่าปริยาย Laravel = 30 วิ) อีก
        // ต้นเหตุที่การ์ดตายแต่ข้อความ "ออเดอร์ใหม่!" รอดในเหตุการณ์เดียวกัน
        $timeout = (new \ReflectionClassConstant(TelegramAlertBotService::class, 'TIMEOUT_SECONDS'))->getValue();

        $this->assertGreaterThanOrEqual(30, $timeout);
    }
}
