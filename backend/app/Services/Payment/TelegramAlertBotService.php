<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper Telegram Bot API สำหรับ "bot แจ้งเตือน" (ใช้ raw token จาก flow telegram plugin,
 * ไม่ใช่ Bot model). ทุก method นอกจาก setWebhook เป็น best-effort — ล้มแล้วแค่ log.
 */
class TelegramAlertBotService
{
    private const BASE = 'https://api.telegram.org/bot';

    public function sendMessage(string $token, string $chatId, string $text, ?array $inlineKeyboard = null): void
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($inlineKeyboard !== null) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }
        $this->call($token, 'sendMessage', $params);
    }

    public function editMessageText(string $token, string $chatId, int $messageId, string $text, ?array $inlineKeyboard = null): void
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
        $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard ?? []]);
        $this->call($token, 'editMessageText', $params);
    }

    public function answerCallbackQuery(string $token, string $callbackQueryId, string $text): void
    {
        $this->call($token, 'answerCallbackQuery', ['callback_query_id' => $callbackQueryId, 'text' => $text]);
    }

    public function setWebhook(string $token, string $url, string $secret): bool
    {
        try {
            $res = Http::timeout(10)->post(self::BASE.$token.'/setWebhook', [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => ['callback_query'],
            ]);

            return $res->successful() && ($res->json('ok') === true);
        } catch (\Throwable $e) {
            Log::warning('Telegram alert setWebhook failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function call(string $token, string $method, array $params): void
    {
        try {
            $res = Http::timeout(5)->retry(2, 500)->post(self::BASE.$token.'/'.$method, $params);

            if (! $res->successful() || $res->json('ok') === false) {
                Log::warning('Telegram alert API non-OK response', [
                    'method' => $method,
                    'status' => $res->status(),
                    'body' => $res->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram alert API call failed', ['method' => $method, 'error' => $e->getMessage()]);
        }
    }
}
