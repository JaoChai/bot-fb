<?php

namespace App\Services\FollowUp;

use App\Models\Bot;
use App\Models\LeadRecoveryLog;
use App\Services\LINEService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ตัวตัดสิน "บอทนี้ส่งข้อความตามลูกค้าอีกได้ไหม" — การ์ด B4a (D42/D43)
 *
 * ไม่มีแคช (D43): ทุกครั้งอ่านโควต้าใหม่จาก LINE ผ่าน LINEService::followUpQuota
 * ไม่ส่งข้อความเอง ไม่มีการแจ้งเตือน ไม่มีตารางงาน ไม่มี HTTP client ของตัวเอง —
 * มีหน้าที่อ่านโควต้า + นับ local_pending แล้วตัดสินตามสูตรของ D42
 */
class FollowUpQuotaService
{
    public function __construct(private readonly LINEService $lineService) {}

    /**
     * ตัดสินว่าบอทส่งข้อความตามลูกค้าเพิ่มอีกหนึ่งข้อความได้หรือไม่
     *
     * - reserve ต้องเป็นจำนวนเต็มบวกจาก config มิฉะนั้น fail-closed โดยไม่ยิง HTTP
     * - โควต้าฝั่ง transport ล้มเหลว = บล็อกด้วยเหตุผลเดียวกัน (local_pending 0)
     * - limited: allowed = value - total_usage - local_pending - 1 >= reserve (เท่ากันพอดี = ผ่าน)
     * - none: ไม่จำกัดโควต้าฝั่ง LINE → ผ่าน (value null)
     *
     * @return array{allowed: bool, reason: ?string, type: ?string, value: ?int, total_usage: ?int, local_pending: int, reserve: ?int}
     */
    public function check(Bot $bot, CarbonImmutable $now, ?int $exceptLogId = null): array
    {
        $reserve = config('customer_follow_up.quota_reserve_messages');

        if (! is_int($reserve) || $reserve < 1) {
            return $this->result(false, 'reserve_not_configured', null, null, null, 0, null, $bot->id);
        }

        $quota = $this->lineService->followUpQuota($bot);

        if (! $quota['ok']) {
            return $this->result(false, $quota['reason'], null, null, null, 0, $reserve, $bot->id);
        }

        $localPending = $this->localPending($bot, $now, $exceptLogId);

        if ($quota['type'] === 'limited') {
            $allowed = ($quota['value'] - $quota['total_usage'] - $localPending - 1) >= $reserve;

            return $this->result(
                $allowed,
                $allowed ? null : 'below_reserve',
                $quota['type'],
                $quota['value'],
                $quota['total_usage'],
                $localPending,
                $reserve,
                $bot->id
            );
        }

        // type none = LINE ไม่จำกัดโควต้า → ผ่าน โดย value เป็น null ตามที่ transport คืน
        return $this->result(
            true,
            null,
            $quota['type'],
            null,
            $quota['total_usage'],
            $localPending,
            $reserve,
            $bot->id
        );
    }

    /**
     * จำนวนข้อความของบอทนี้ที่ LINE อาจยังไม่เห็น: แถวที่เริ่มยิงแล้ว (first_push_at ไม่ null)
     * และ (ยังไม่รู้ผล sending/retry หรือ เริ่มยิงวันนี้กรุงเทพฯ) — นับหนึ่งครั้งต่อแถว
     * ไม่ว่าผลสุดท้ายจะเป็นอะไร; $exceptLogId ตัดแถวที่กำลังจะส่งออกจากการนับ
     */
    private function localPending(Bot $bot, CarbonImmutable $now, ?int $exceptLogId): int
    {
        $todayStart = $now->setTimezone(FollowUpCapabilities::TIMEZONE)
            ->startOfDay()
            ->format('Y-m-d H:i:s');

        return DB::table('lead_recovery_logs')
            ->where('bot_id', $bot->id)
            ->whereNotNull('first_push_at')
            ->when($exceptLogId !== null, fn ($query) => $query->where('id', '!=', $exceptLogId))
            ->where(function ($query) use ($todayStart) {
                $query->whereIn('delivery_status', [
                    LeadRecoveryLog::DELIVERY_STATUS_SENDING,
                    LeadRecoveryLog::DELIVERY_STATUS_RETRY,
                ])->orWhere('first_push_at', '>=', $todayStart);
            })
            ->count();
    }

    /**
     * ประกอบผลลัพธ์และเขียน log บรรทัดเดียวต่อ call — มีแค่ bot_id + รหัส/ตัวเลข
     */
    private function result(
        bool $allowed,
        ?string $reason,
        ?string $type,
        ?int $value,
        ?int $totalUsage,
        int $localPending,
        ?int $reserve,
        int $botId
    ): array {
        $result = [
            'allowed' => $allowed,
            'reason' => $reason,
            'type' => $type,
            'value' => $value,
            'total_usage' => $totalUsage,
            'local_pending' => $localPending,
            'reserve' => $reserve,
        ];

        Log::info('Customer follow-up quota check', array_merge(['bot_id' => $botId], $result));

        return $result;
    }
}
