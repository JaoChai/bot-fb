<?php

namespace App\Services\FollowUp;

use App\Models\Bot;
use App\Models\FlowPlugin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ความพร้อมของบอทหนึ่งตัวสำหรับระบบ "ตามลูกค้า" — plan §3.7 (lines 289-290)
 *
 * ผลลัพธ์มีแค่ object นี้ ห้ามมี token / chat id / user id / URL หลุดออกมา:
 * [
 *   'supported'         => bool   (blocked_reason === null),
 *   'telegram_ready'    => bool   (plugin telegram เปิด มี token+chat_id และสิทธิ์เปิดตาม D150:
 *                                  ไม่มี list (ขาด/null/[]) = ห้องเปิด ทุกคนกดได้, มี list = ใช้ list,
 *                                  รูปร่างอื่น = ปิด),
 *   'automatic_allowed' => bool   (บอทอยู่ใน automatic_bot_ids ฝั่ง host + started_at >= 14 วัน),
 *   'blocked_reason'    => ?string (เช็คตามลำดับด้านล่าง, null เมื่อผ่านหมด),
 *   'rules'             => array  (ตัวเลขที่ตัวอื่นต้องใช้ ไม่มี secret),
 * ]
 *
 * ตัวเลือกของการ์ด (BotSetting.php เป็นของ B5): อ่านคอลัมน์ bot_settings ใหม่
 * ผ่าน query builder เท่านั้น ไม่แตะโมเดล
 */
class FollowUpCapabilities
{
    /** ชั่วโมงส่งได้ (เวลากรุงเทพฯ) — ขอบรวม 08:00 ตัด 22:00 (plan line 143-156) */
    public const SEND_START = '08:00';

    public const SEND_END = '22:00';

    public const TIMEZONE = 'Asia/Bangkok';

    /** โหมดอัตโนมัติต้องเปิดมาแล้วอย่างน้อย 14 วัน (D8) */
    private const AUTOMATIC_AGE_DAYS = 14;

    public static function forBot(Bot $bot, CarbonImmutable $now): array
    {
        $plugin = self::alertPlugin($bot);
        $allowlistOk = $plugin !== null && self::telegramGateOpen($plugin);

        $reason = null;
        if ($bot->channel_type !== 'line') {
            $reason = 'line_only';
        } elseif (! in_array((int) $bot->id, self::pilotBotIds(), true)) {
            $reason = 'bot_not_in_pilot';
        } elseif ($plugin === null) {
            $reason = 'telegram_missing';
        } elseif (! $allowlistOk) {
            $reason = 'telegram_allowlist_missing';
        } elseif (trim((string) config('services.telegram_alert.secret')) === '') {
            $reason = 'webhook_secret_missing';
        } elseif (trim((string) $bot->resolvedChatModel()) === '') {
            $reason = 'writer_missing';
        } elseif (trim((string) config('customer_follow_up.admin_web_url')) === '') {
            $reason = 'admin_url_missing';
        }

        return [
            'supported' => $reason === null,
            'telegram_ready' => $plugin !== null && $allowlistOk,
            'automatic_allowed' => $reason === null && self::automaticAllowed($bot, $now),
            'blocked_reason' => $reason,
            'rules' => [
                'payment_wait_hours' => (int) config('customer_follow_up.payment_wait_hours'),
                'sale_wait_hours' => (int) config('customer_follow_up.sale_wait_hours'),
                'customer_gap_days' => (int) config('customer_follow_up.customer_gap_days'),
                'daily_draft_cap' => (int) config('customer_follow_up.daily_draft_cap'),
                'approval_ttl_hours' => (int) config('customer_follow_up.approval_ttl_hours'),
                'send_start' => self::SEND_START,
                'send_end' => self::SEND_END,
                'timezone' => self::TIMEZONE,
            ],
        ];
    }

    /** @return list<int> */
    private static function pilotBotIds(): array
    {
        return array_map(intval(...), (array) config('customer_follow_up.bot_ids', []));
    }

    /** ภาพระดับบอทว่าโหมดอัตโนมัติเปิดได้หรือยัง — host list (D8) + อายุ 14 วันนับจาก started_at */
    private static function automaticAllowed(Bot $bot, CarbonImmutable $now): bool
    {
        if (! in_array((int) $bot->id, array_map(intval(...), (array) config('customer_follow_up.automatic_bot_ids', [])), true)) {
            return false;
        }

        $startedAt = DB::table('bot_settings')->where('bot_id', $bot->id)->value('follow_up_started_at');

        if ($startedAt === null) {
            return false;
        }

        // เวลาในตารางเป็นกรุงเทพฯ ไม่มีโซน — parse ในโซนกรุงเทพฯ
        $started = CarbonImmutable::parse($startedAt, self::TIMEZONE)->startOfSecond();

        return $started->lessThanOrEqualTo($now->startOfSecond()->subDays(self::AUTOMATIC_AGE_DAYS));
    }

    /**
     * plugin telegram ที่เปิดใช้อยู่ของ default flow พร้อม token+chat_id —
     * เงื่อนไขเดียวกับ TelegramAlertBotService::alertTargets (อ่านอย่างเดียว)
     */
    public static function alertPlugin(Bot $bot): ?FlowPlugin
    {
        $flow = $bot->defaultFlow;

        $plugin = $flow?->plugins()
            ->where('type', 'telegram')
            ->where('enabled', true)
            ->first();

        if ($plugin === null) {
            return null;
        }

        $token = $plugin->config['access_token'] ?? '';
        $chatId = $plugin->config['chat_id'] ?? '';

        return (is_string($token) && $token !== '' && is_string($chatId) && $chatId !== '') ? $plugin : null;
    }

    /**
     * สิทธิ์ของการ์ดตามลูกค้า (D150) — ห้องแจ้งเตือนคือสิทธิ์:
     * ขาด/null/[] = ห้องเปิด ทุกคนในห้องกดได้; list ที่มี id ใช้ได้อย่างน้อยหนึ่ง = ใช้ list;
     * รูปร่างอื่น (string, map, list ไม่มี id ใช้ได้, false, 0) = ปิด telegram_allowlist_missing
     */
    private static function telegramGateOpen(FlowPlugin $plugin): bool
    {
        $allow = $plugin->config['authorized_user_ids'] ?? null;

        if ($allow === null || $allow === []) {
            return true;
        }

        if (! is_array($allow) || ! array_is_list($allow)) {
            return false;
        }

        foreach ($allow as $id) {
            if (is_scalar($id) && trim((string) $id) !== '') {
                return true;
            }
        }

        return false;
    }
}
