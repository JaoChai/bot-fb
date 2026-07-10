<?php

namespace Tests\Feature;

use App\Services\Payment\TelegramAlertBotService;
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
}
