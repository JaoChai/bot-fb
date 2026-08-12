<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper Telegram Bot API สำหรับ "bot แจ้งเตือน" (ใช้ raw token จาก flow telegram plugin,
 * ไม่ใช่ Bot model). ไม่มี method ไหนโยน exception — ล้มแล้ว log เสมอ
 * แต่ตัวที่คืนค่า (sendMessage คืน ?array, setWebhook คืน bool) บอกผู้เรียกได้ว่าสำเร็จไหม
 * ผู้เรียกที่ส่งของสำคัญต้องเช็คค่านั้น อย่าถือว่าเรียกแล้วคือถึงปลายทาง
 */
class TelegramAlertBotService
{
    private const BASE = 'https://api.telegram.org/bot';

    /**
     * เวลารอ Telegram ตอบ ต้องไม่สั้นกว่าตัวส่งข้อความอีกทางคือ FlowPluginService
     * ซึ่งใช้ค่าปริยายของ Laravel (30 วิ)
     *
     * เหตุการณ์ 1 ส.ค. 2026: ค่านี้เคยเป็น 5 วิ พอ api.telegram.org ค้าง ~30 วิ
     * การ์ดปุ่มส่งของตายทั้ง 2 ครั้งใน 10 วินาที ส่วนข้อความ "ออเดอร์ใหม่!" รอด
     * ผลคือเจ้าของเห็นว่ามีออเดอร์ แต่ไม่มีปุ่มให้กดส่ง ออเดอร์ค้างเงียบ
     */
    private const TIMEOUT_SECONDS = 30;

    /** escape ค่า dynamic ก่อนประกอบเข้า HTML message — ค่าที่ไม่ escape ทำให้ Telegram ปฏิเสธทั้งข้อความ */
    public static function esc(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return array<string, mixed>|null null = ส่งไม่สำเร็จ; array = result จาก Telegram
     *                                   (มี message_id เมื่อ Telegram ส่งมา) — เทียบด้วย !== null
     *
     * $replyToMessageId: ผูกข้อความนี้เป็น reply ของข้อความเดิม พร้อม allow_sending_without_reply
     * เพื่อให้ยังส่งออกแม้ข้อความต้นทางถูกลบไปแล้ว
     */
    public function sendMessage(
        string $token,
        string $chatId,
        string $text,
        ?array $inlineKeyboard = null,
        ?int $replyToMessageId = null,
    ): ?array {
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($inlineKeyboard !== null) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }
        if ($replyToMessageId !== null) {
            $params['reply_to_message_id'] = $replyToMessageId;
            $params['allow_sending_without_reply'] = true;
        }

        return $this->call($token, 'sendMessage', $params);
    }

    public function editMessageText(string $token, string $chatId, int $messageId, string $text, ?array $inlineKeyboard = null): void
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
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

    /**
     * @return array<string, mixed>|null null = ยิงไม่สำเร็จ; array = Telegram รับแล้ว
     *
     * "สำเร็จ" ผูกกับ HTTP ok เท่านั้น — response ที่ไม่มี result คืน [] ไม่ใช่ null
     * ผู้เรียกต้องเทียบด้วย !== null ([] เป็น falsy จะทำให้ if (! $x) อ่านผิดเป็นล้มเหลว)
     */
    private function call(string $token, string $method, array $params): ?array
    {
        try {
            $res = Http::timeout(self::TIMEOUT_SECONDS)->retry(2, 500)->post(self::BASE.$token.'/'.$method, $params);

            if (! $res->successful() || $res->json('ok') === false) {
                Log::warning('Telegram alert API non-OK response', [
                    'method' => $method,
                    'status' => $res->status(),
                    'body' => $res->body(),
                ]);

                return null;
            }

            $result = $res->json('result');

            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            Log::warning('Telegram alert API call failed', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
