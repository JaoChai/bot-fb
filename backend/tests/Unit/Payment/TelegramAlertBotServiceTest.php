<?php

namespace Tests\Unit\Payment;

use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAlertBotServiceTest extends TestCase
{
    public function test_send_message_posts_text_and_inline_keyboard(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramAlertBotService::class)->sendMessage(
            'TOK', '123', 'hi',
            [[['text' => 'A', 'callback_data' => 'pc|1|590']]],
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/botTOK/sendMessage')
                && $request['chat_id'] === '123'
                && $request['text'] === 'hi'
                && str_contains($request['reply_markup'], 'callback_data');
        });
    }

    public function test_answer_callback_query_posts_id_and_text(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(TelegramAlertBotService::class)->answerCallbackQuery('TOK', 'cb99', 'ยืนยันแล้ว');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/botTOK/answerCallbackQuery')
            && $r['callback_query_id'] === 'cb99' && $r['text'] === 'ยืนยันแล้ว');
    }

    public function test_send_message_swallows_errors(): void
    {
        Http::fake(fn () => throw new \RuntimeException('down'));

        // ต้องไม่ throw
        app(TelegramAlertBotService::class)->sendMessage('TOK', '123', 'hi');
        $this->assertTrue(true);
    }
}
