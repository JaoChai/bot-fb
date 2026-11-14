<?php

namespace App\Services;

use App\Exceptions\OpenRouterException;
use App\Models\Bot;
use App\Models\Message;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * จุดเดียวกลางสำหรับ "บอทล่มเพราะ OpenRouter ปฏิเสธกุญแจ" (การ์ด t_44675510)
 *
 * เรียกจาก catch (OpenRouterException) ทั้งสองจุดที่สร้างแถวคำตอบให้ลูกค้า:
 * AIService::generateAndSaveResponse() และ ProcessAggregatedMessages — auth error
 * เท่านั้น (401/403) คือ (1) tag metadata.model_error = "auth" บนแถวที่เพิ่งสร้าง
 * เพื่อให้ห้องนั้นถูกขอให้ลูกค้าพิมพ์ใหม่ตอนกุญแจกลับมา และ (2) แจ้งเจ้าของทาง
 * Telegram 1 ครั้ง/30 นาที ทั้งระบบ (กุญแจเป็น global ของทุกบอท — แจ้งซ้ำทุกห้องไม่มีประโยชน์)
 *
 * ไม่มีทางโยน exception ออกนอกเมธอด: cache ล่ม = แจ้งเลยไม่ทิ้ง, Telegram ล่ม /
 * ไม่มี plugin = log แล้วจบ — ลูกค้าต้องได้รับคำตอบเดิมเสมอ ไม่มีอะไรถูก delay
 * มากกว่าที่ throttle ปล่อย (ส่งจริงครั้งเดียวต่อ 30 นาที)
 */
class OutageNoticeService
{
    /** cache key กันแจ้งซ้ำ — ค่าเดียวทั้งระบบเพราะกุญแจ OpenRouter เป็น global */
    public const AUTH_ALERT_CACHE_KEY = 'outage:auth-alert';

    /** 30 นาที — ตาม spec: 1 ครั้ง/30 นาที ทั้งระบบ */
    public const AUTH_ALERT_TTL_SECONDS = 1800;

    public function __construct(private readonly TelegramAlertBotService $telegram) {}

    /**
     * เรียกท้าย catch หลังแถวคำตอบลูกค้าถูกสร้างแล้ว — no-op เมื่อไม่ใช่ auth error
     * (เส้นทาง sync: AIService::generateAndSaveResponse)
     */
    public function handleAuthFailure(Message $botMessage, Bot $bot, OpenRouterException $e): void
    {
        $this->tagIfAuthError($botMessage, $e);
        $this->alertIfAuthError($bot, $e);
    }

    /**
     * tag model_error="auth" บนแถวคำตอบ — เส้นทาง aggregated เรียก "ก่อน" deliver()
     * (Lead round 1 note A): push ที่โยน exception ต้องไม่ทำให้แถวหลุด tag
     * ไม่งั้นห้องนั้นหลุดจากคิวขอให้ลูกค้าพิมพ์ใหม่ตอนกุญแจกลับมา
     */
    public function tagIfAuthError(Message $botMessage, OpenRouterException $e): void
    {
        if (! $e->isAuthError()) {
            return;
        }

        $this->tagAuthFailure($botMessage);
    }

    /**
     * alert ปิดท้ายเส้นทาง (sync: ท้าย catch / aggregated: หลัง deliver ใน finally) —
     * no-op เมื่อไม่ใช่ auth error; ไม่มีทางโยน exception ออกนอกเมธอด
     */
    public function alertIfAuthError(Bot $bot, OpenRouterException $e): void
    {
        if (! $e->isAuthError()) {
            return;
        }

        $this->alertOwnerDown($bot, $e->getHttpStatus());
    }

    /**
     * "กลับมาแล้ว" — เรียกโดย OutageRecoveryService ต่อบอท 1 ข้อความต่อการรัน
     * $disabled = ห้องที่ถูกปิดท้ายเพราะสวิตช์ปิด (R6) — ไม่ใช่ความล้มเหลวในการส่ง
     */
    public function alertOwnerRecovered(Bot $bot, int $sent, int $failed, int $disabled = 0): void
    {
        $this->telegram->notifyOutageRecovered($bot, $sent, $failed, $disabled);
    }

    private function tagAuthFailure(Message $botMessage): void
    {
        try {
            $metadata = $botMessage->metadata ?? [];
            $metadata['model_error'] = 'auth';
            $botMessage->forceFill(['metadata' => $metadata])->save();
        } catch (\Throwable $e) {
            Log::warning('Outage tag failed', [
                'message_id' => $botMessage->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function alertOwnerDown(Bot $bot, int $httpStatus): void
    {
        try {
            // Resolve the target before taking the slot, inside the best-effort boundary:
            // a failed plugin lookup must not interrupt the customer's fallback reply.
            if (! $this->telegram->hasAlertTarget($bot)) {
                return;
            }

            try {
                $first = Cache::add(self::AUTH_ALERT_CACHE_KEY, 1, self::AUTH_ALERT_TTL_SECONDS);
            } catch (\Throwable $e) {
                // cache ล่ม = ยังต้องแจ้ง (ของจริงพังทั้งระบบอยู่แล้ว ไม่มีเหตุผลเงียบ)
                Log::warning('Outage alert throttle cache unavailable — alerting anyway', [
                    'error' => $e->getMessage(),
                ]);
                $first = true;
            }

            if (! $first) {
                return;
            }

            $this->telegram->notifyOutageDown($bot, $httpStatus);
        } catch (\Throwable $e) {
            Log::warning('Outage owner alert failed', ['bot_id' => $bot->id]);
        }
    }
}
