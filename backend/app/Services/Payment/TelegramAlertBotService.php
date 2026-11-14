<?php

namespace App\Services\Payment;

use App\Models\Bot;
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
     * แจ้งเจ้าของว่า "ลูกค้าต้องการ Support" — ใช้ร่วมกันทั้ง Support Router
     * ($detailLine = "คะแนน x.xx") และคำตอบรูปจาก vision ที่พาลูกค้าไปหาทีม
     * Support ($detailLine = "จากรูปภาพ") ให้เป็น alert รูปแบบเดียวกัน
     * (header เดิม + ชื่อบอท + $detailLine + 300 ตัวอักษรแรกของข้อความ, escape ทุกค่า)
     * ไม่มีทางโยน exception — ไม่มี plugin / config หาย / API ล่ม = log แล้วจบ
     */
    public function notifySupportAlert(Bot $bot, string $detailLine, string $message): void
    {
        try {
            $targets = $this->alertTargets($bot, 'Support router alert');

            if ($targets === null) {
                return;
            }
            [$token, $chatId] = $targets;

            $text = '🛠 <b>ลูกค้าต้องการ Support</b> ('.self::esc($bot->name).")\n"
                .self::esc($detailLine)."\n"
                .self::esc(mb_substr($message, 0, 300));

            $this->sendMessage($token, $chatId, $text);
        } catch (\Throwable $e) {
            Log::warning('Support router alert failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * แจ้งเจ้าของว่า "บอทล่ม — OpenRouter ปฏิเสธกุญแจ" (การ์ด t_44675510)
     * ลูกค้าได้ข้อความแจ้งระบบขัดข้องตามที่ตั้งไว้ ต้องรีบเปลี่ยน OPENROUTER_API_KEY
     * ไม่มีทางโยน exception — ไม่มี plugin / config หาย / API ล่ม = log แล้วจบ
     */
    public function notifyOutageDown(Bot $bot, int $httpStatus): void
    {
        try {
            $targets = $this->alertTargets($bot, 'Outage alert');

            if ($targets === null) {
                return;
            }
            [$token, $chatId] = $targets;

            $text = '🚨 <b>บอทตอบลูกค้าไม่ได้</b> ('.self::esc($bot->name).")\n"
                .'OpenRouter ปฏิเสธกุญแจ (HTTP '.self::esc((string) $httpStatus).")\n"
                .'ลูกค้าได้ข้อความแจ้งระบบขัดข้องตามที่ตั้งไว้ ต้องเปลี่ยน OPENROUTER_API_KEY';

            $this->sendMessage($token, $chatId, $text);
        } catch (\Throwable $e) {
            Log::warning('Outage alert failed', [
                'bot_id' => $bot->id,
                'http_status' => $httpStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * มีปลายทางแจ้งเตือนจริงไหม (plugin telegram เปิดใช้อยู่ + token/chat_id ครบ) —
     * OutageNoticeService ใช้เช็ค "จะส่งได้จริงไหม" ก่อนกินสล็อต throttle
     * เพื่อไม่ให้บอทที่แจ้งไม่ได้เผาโควตาแจ้งเตือนของบอทอื่น (review round 1 #2)
     */
    public function hasAlertTarget(Bot $bot): bool
    {
        return $this->alertTargets($bot, 'Outage alert target check') !== null;
    }

    /**
     * บอกเจ้าของว่า "บอทกลับมาตอบได้แล้ว" พร้อมยอดต่อบอท (การ์ด t_44675510)
     * $sent = ส่งข้อความขอให้ลูกค้าพิมพ์ใหม่สำเร็จ, $failed = ส่งไม่สำเร็จ (ไม่ retry แล้ว)
     * $disabled = ห้องที่ถูกปิดท้ายเพราะสวิตช์ "เมื่อบอทล่ม" ปิดอยู่ (D3: > 0 เติมท้าย
     * บรรทัดยอด ไม่ใช่ส่งไม่สำเร็จ; = 0 ข้อความคงเดิมทุกไบต์)
     */
    public function notifyOutageRecovered(Bot $bot, int $sent, int $failed, int $disabled = 0): void
    {
        try {
            $targets = $this->alertTargets($bot, 'Outage recovery alert');

            if ($targets === null) {
                return;
            }
            [$token, $chatId] = $targets;

            $text = '✅ <b>บอทกลับมาตอบได้แล้ว</b> ('.self::esc($bot->name).")\n"
                .'ส่งข้อความขอให้ลูกค้าพิมพ์ใหม่ '.self::esc((string) $sent).' ห้อง · ส่งไม่สำเร็จ '.self::esc((string) $failed).' ห้อง';

            if ($disabled > 0) {
                $text .= ' · ไม่ได้ส่ง '.self::esc((string) $disabled).' ห้อง (ปิดการส่งข้อความตามลูกค้าไว้)';
            }

            $this->sendMessage($token, $chatId, $text);
        } catch (\Throwable $e) {
            Log::warning('Outage recovery alert failed', [
                'bot_id' => $bot->id,
                'sent' => $sent,
                'failed' => $failed,
                'disabled' => $disabled,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * token + chat_id ของ plugin telegram ที่เปิดใช้อยู่ใน default flow ของบอท
     * คืน null เมื่อไม่มี plugin หรือ config ไม่ครบ (log ทิ้ง label ของ alert นั้นๆ)
     *
     * @return array{0: string, 1: string}|null [access_token, chat_id]
     */
    private function alertTargets(Bot $bot, string $label): ?array
    {
        $flow = $bot->defaultFlow;
        $plugin = $flow?->plugins()
            ->where('type', 'telegram')
            ->where('enabled', true)
            ->first();

        if (! $plugin) {
            Log::debug($label.': no enabled telegram plugin', ['bot_id' => $bot->id]);

            return null;
        }

        $token = $plugin->config['access_token'] ?? '';
        $chatId = $plugin->config['chat_id'] ?? '';
        if (empty($token) || empty($chatId)) {
            Log::debug($label.': telegram plugin missing config', ['plugin_id' => $plugin->id]);

            return null;
        }

        return [$token, $chatId];
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
