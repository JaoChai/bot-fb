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
                'allowed_updates' => ['callback_query', 'message'],
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

    /**
     * ส่งบัตรอนุมัติ "ตามลูกค้า" ใบแรก (การ์ด B6a) — เส้นทางพิเศษ ห้ามใช้ call() เดิม (D71)
     *
     * ต่างจาก sendMessage เดิมทุกจุดที่การ์ดกำหนด: timeout รวม 10 วิ, connect timeout ตาม config,
     * ไม่ retry, ไม่ตาม redirect, ไม่มีทางโยน exception และ "ยอมรับ" ได้ต้องมี result.message_id
     * เป็นจำนวนเต็มบวกจริง (ต่างจากเดิมที่คืน [] แล้วดูเหมือนสำเร็จ)
     * คืน array เดียว: outcome accepted|rejected|unknown + message_id + status + retry_after
     * log บรรทัดเดียวต่อการเรียก เอาแค่ outcome + HTTP status — ห้ามมี token / chat id /
     * ข้อความ / body / exception message (exception message มี token เต็ม URL, วัดแล้ว)
     */
    public function sendFollowUpCard(string $token, string $chatId, string $html, array $inlineKeyboard): array
    {
        $result = ['outcome' => 'unknown', 'message_id' => null, 'status' => null, 'retry_after' => null];

        if ($token === '' || $chatId === '' || $html === '' || $inlineKeyboard === []) {
            $result['outcome'] = 'rejected';
            $this->logFollowUpCardCall($result);

            return $result;
        }

        try {
            $res = Http::connectTimeout((int) config('customer_follow_up.http_connect_timeout_seconds', 3))
                ->timeout(10)
                ->withoutRedirecting()
                ->post($this->followUpBase().$token.'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $html,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
                    'link_preview_options' => json_encode(['is_disabled' => true]),
                ]);
        } catch (\Throwable) {
            $this->logFollowUpCardCall($result);

            return $result;
        }

        $result['status'] = $res->status();

        $messageId = $res->json('result.message_id');
        if ($res->status() === 200 && $res->json('ok') === true && is_int($messageId) && $messageId > 0) {
            $result['outcome'] = 'accepted';
            $result['message_id'] = $messageId;
            $this->logFollowUpCardCall($result);

            return $result;
        }

        if ($res->status() >= 400 && $res->status() <= 499 && $res->json('ok') === false) {
            $result['outcome'] = 'rejected';
            $retryAfter = $res->json('parameters.retry_after');
            if (is_int($retryAfter) && $retryAfter > 0) {
                $result['retry_after'] = $retryAfter;
            }
        }

        $this->logFollowUpCardCall($result);

        return $result;
    }

    /**
     * ข้อความล้วนของการ์ดตามลูกค้า (B6c) — ช่องแก้และข้อความแจ้ง การขนส่งตัวเอง ไม่แตะ call():
     * connect timeout ตาม config, timeout 10 วิ, ไม่ retry, ไม่ตาม redirect, ไม่มี parse_mode,
     * ไม่มีทางโยน exception; คืน ['outcome' => accepted|rejected|unknown, 'status' => ?int, 'message_id' => ?int]
     * accepted = HTTP 200 + ok true + result.message_id เป็นจำนวนเต็ม > 0 (กฎเดียวกับ sendFollowUpCard)
     * replyToMessageId = ตอบกลับข้อความนั้น (reply_parameters + allow_sending_without_reply)
     * placeholder ไม่ null = ช่อง ForceReply ที่มีคำใบ้ในช่องพิมพ์
     * log บรรทัดเดียวต่อการเรียก: label + outcome + HTTP status เท่านั้น
     */
    public function sendFollowUpText(string $token, string $chatId, string $text, ?int $replyToMessageId = null, ?string $placeholder = null): array
    {
        $result = ['outcome' => 'unknown', 'status' => null, 'message_id' => null];

        if ($token === '' || $chatId === '' || $text === '' || ($replyToMessageId !== null && $replyToMessageId < 1)) {
            $result['outcome'] = 'rejected';
            $this->logFollowUpTransportCall('Follow-up text send', $result);

            return $result;
        }

        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ];
        if ($replyToMessageId !== null) {
            $params['reply_parameters'] = json_encode(['message_id' => $replyToMessageId, 'allow_sending_without_reply' => true]);
        }
        if ($placeholder !== null) {
            $params['reply_markup'] = json_encode(['force_reply' => true, 'input_field_placeholder' => $placeholder]);
        }

        try {
            $res = Http::connectTimeout((int) config('customer_follow_up.http_connect_timeout_seconds', 3))
                ->timeout(10)
                ->withoutRedirecting()
                ->post($this->followUpBase().$token.'/sendMessage', $params);
        } catch (\Throwable) {
            $this->logFollowUpTransportCall('Follow-up text send', $result);

            return $result;
        }

        $result['status'] = $res->status();

        $messageId = $res->json('result.message_id');
        if ($res->status() === 200 && $res->json('ok') === true && is_int($messageId) && $messageId > 0) {
            $result['outcome'] = 'accepted';
            $result['message_id'] = $messageId;
        } elseif ($res->status() >= 400 && $res->status() <= 499 && $res->json('ok') === false) {
            $result['outcome'] = 'rejected';
        }

        $this->logFollowUpTransportCall('Follow-up text send', $result);

        return $result;
    }

    /**
     * แก้การ์ดตามลูกค้า (B6b) — การขนส่งของตัวเอง ไม่แตะ call()/เมธอดเดิม:
     * connect timeout ตาม config, timeout 10 วิ, ไม่ retry, ไม่ตาม redirect,
     * ไม่มีทางโยน exception, คืน ['outcome' => accepted|rejected|unknown, 'status' => ?int]
     * log บรรทัดเดียวต่อการเรียก: label + outcome + HTTP status เท่านั้น
     * keyboard ว่าง = ลบปุ่มออกจากการ์ด (reply_markup {"inline_keyboard":[]})
     */
    public function editFollowUpCard(string $token, string $chatId, int $messageId, string $html, array $inlineKeyboard = []): array
    {
        $result = ['outcome' => 'unknown', 'status' => null];

        if ($token === '' || $chatId === '' || $html === '' || $messageId < 1) {
            $result['outcome'] = 'rejected';
            $this->logFollowUpTransportCall('Follow-up card edit', $result);

            return $result;
        }

        return $this->postFollowUpCall('Follow-up card edit', 'editMessageText', $token, [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ], 10, false);
    }

    /**
     * ตอบปุ่มที่กดบนการ์ดตามลูกค้า (B6b) — timeout 5 วิ, ไม่ retry, ไม่ตาม redirect,
     * ไม่มีทางโยน exception, คืน ['outcome' => accepted|rejected|unknown, 'status' => ?int]
     * show_alert เป็น JSON boolean (true = เด้งกล่องเตือน, false = toast มุมจอ)
     */
    public function answerFollowUpCallback(string $token, string $callbackQueryId, string $text, bool $alert = false): array
    {
        $result = ['outcome' => 'unknown', 'status' => null];

        if ($token === '' || $callbackQueryId === '' || $text === '') {
            $result['outcome'] = 'rejected';
            $this->logFollowUpTransportCall('Follow-up callback answer', $result);

            return $result;
        }

        return $this->postFollowUpCall('Follow-up callback answer', 'answerCallbackQuery', $token, [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $alert,
        ], 5, true);
    }

    /** โพสต์หนึ่งครั้งแบบไม่ retry — ใช้โดย editFollowUpCard/answerFollowUpCallback เท่านั้น */
    private function postFollowUpCall(string $label, string $method, string $token, array $params, int $timeoutSeconds, bool $asJson): array
    {
        $result = ['outcome' => 'unknown', 'status' => null];

        try {
            $client = Http::connectTimeout((int) config('customer_follow_up.http_connect_timeout_seconds', 3))
                ->timeout($timeoutSeconds)
                ->withoutRedirecting();
            $res = $asJson
                ? $client->asJson()->post($this->followUpBase().$token.'/'.$method, $params)
                : $client->post($this->followUpBase().$token.'/'.$method, $params);
        } catch (\Throwable) {
            $this->logFollowUpTransportCall($label, $result);

            return $result;
        }

        $result['status'] = $res->status();

        if ($res->status() === 200 && $res->json('ok') === true) {
            $result['outcome'] = 'accepted';
        } elseif ($res->status() >= 400 && $res->status() <= 499 && $res->json('ok') === false) {
            $result['outcome'] = 'rejected';
        }

        $this->logFollowUpTransportCall($label, $result);

        return $result;
    }

    /** log เดียวต่อการเรียก: label + outcome + HTTP status เท่านั้น */
    private function logFollowUpTransportCall(string $label, array $result): void
    {
        Log::info($label, [
            'outcome' => $result['outcome'],
            'status' => $result['status'],
        ]);
    }

    /** base URL ของ Telegram Bot API สำหรับบัตรตามลูกค้า — test ล้อง override เป็น port ปิดในเครื่องได้ */
    protected function followUpBase(): string
    {
        return self::BASE;
    }

    /** log เดียวต่อการเรียก: outcome + HTTP status เท่านั้น */
    private function logFollowUpCardCall(array $result): void
    {
        Log::info('Follow-up card send', [
            'outcome' => $result['outcome'],
            'status' => $result['status'],
        ]);
    }
}
