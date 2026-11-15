<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\FollowUpApproval;
use App\Models\LeadRecoveryLog;
use App\Services\FollowUp\FollowUpCapabilities;
use App\Services\FollowUp\FollowUpEligibility;
use App\Services\FollowUp\FollowUpPipeline;
use App\Services\FollowUp\FollowUpReservationService;
use App\Services\FollowUp\FollowUpSalesProjector;
use App\Services\FollowUp\FollowUpTelegramService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * สแกน "ตามลูกค้า" ทุก 15 นาที — การ์ด B6d (D95-D99)
 *
 * อ่านนาฬิกาครั้งเดียวต่อการทำงาน ทุกขั้นใช้วินาทีนั้นตลอด ต่อบอทใน config
 * customer_follow_up.bot_ids ตามลำดับ:
 *
 *  Gate (D97) ก่อนเขียนอะไรทั้งสิ้น: บอท active+LINE, bot_settings เปิด
 *  (switch + ข้อความยืนยัน + เวลายืนยัน), อยู่ในชั่วโมงส่ง 08:00-22:00, และ
 *  capabilities ไม่ติด block — บอทที่ปิดไม่มี INSERT/UPDATE/DELETE, ไม่มี
 *  งานเข้าคิว, ไม่มี HTTP, ไม่มี log เหนือ debug (การเติมทั้งบอทจะเขียนแถว
 *  signal ของทุกห้องทุก 15 นาทีถ้าไม่กั้น)
 *
 *  A. เติม projection เฉพาะห้องที่ผ่านเงื่อนไขห้อง "และ" มีข้อความล่าสุดใน 30
 *     วัน (D96) — เดินด้วย id chunks ผ่าน refreshOne เดียวเพื่อให้ล้มรายห้องได้
 *  B. ห้องเงียบที่ผ่าน eligibility เก่าสุดก่อน (last_message_at แล้ว id) ไม่เกิน
 *     100 ห้อง → FollowUpReservationService::candidate สร้าง/คืนแถวเดิม
 *  C. เข้าคิวร่างแถวที่ prepare จะเก็บเป๊ะ (candidate; sort_retry/deferred ครบกำหนด;
 *     sorting/drafting เกิน 10 นาที) ที่รอไม่เกิน 7 วัน (D97, B6f ปิดต่อ) — เก่าสุดก่อน
 *     ไม่เกิน 100 แถว
 *  D. เสนอการ์ดที่ค้าง (status pending, state new/sending, ยังไม่หมดอายุ) เก่าสุดก่อน
 *     ไม่เกิน 5 ใบ → FollowUpTelegramService::publish
 *
 * ห้องไหนล้มใน A หรือ B ไม่หยุดสแกน: warning บรรทัดเดียวต่อห้อง พาแค่ bot id,
 * room id และชื่อ class ของ exception (message มีได้ทั้ง token และข้อความลูกค้า)
 * จบสแกนมี info บรรทัดเดียวต่อบอทที่เปิด ไม่มีข้อความ/ตัวตนลูกค้า
 */
class ProcessCustomerFollowUps implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ครั้งเดียว — รอบถัดไปในอีก 15 นาทีเป็นคนกู้เอง ไม่ใช่ retry ของ queue */
    public int $tries = 1;

    /** เพดานแข็ง 120 วินาที — 151 ห้อง × 8 statements วัดจริงไว้ไม่กินเวลา */
    public int $timeout = 120;

    /** ห้องเงียบสูงสุดต่อบอทต่อสแกน (step B) */
    private const MAX_ELIGIBLE_ROOMS = 100;

    /** งานร่างสูงสุดต่อบอทต่อสแกน (step C) */
    private const MAX_QUEUED_DRAFTS = 100;

    /** การ์ดค้างสูงสุดต่อบอทต่อสแกน (step D) */
    private const MAX_CARDS_OFFERED = 5;

    /** ห้องที่มีข้อความล่าสุดเกินกว่านี้ไม่เติม projection (D96) */
    private const RECENT_WINDOW_DAYS = 30;

    /** แถวที่รอเกินกว่านี้ไม่เข้าคิวอีก — B6f คือคนปิด (D97) */
    private const WAITING_MAX_DAYS = 7;

    /** งานส่งสูงสุดต่อบอทต่อสแกน (step E) */
    private const MAX_SEND_JOBS = 20;

    /** sending ที่ค้างไม่น้อยกว่านี้ถือว่า worker ตาย — step E ชนิด s (นาที) */
    private const SEND_STALE_MINUTES = 10;

    /** approved ที่ค้างไม่น้อยกว่านี้ถึงเก็บ — แท็ปมีงานของตัวเองแล้ว (นาที) */
    private const SEND_APPROVED_MIN_AGE = 2;

    public function handle(FollowUpReservationService $reservations, FollowUpTelegramService $telegram): void
    {
        // นาฬิกาครั้งเดียวต่อการทำงาน — ทุกขั้นใช้วินาทีนี้ตลอด (การ์ด B6d)
        $now = CarbonImmutable::now(FollowUpCapabilities::TIMEZONE)->startOfSecond();

        foreach (array_map(intval(...), (array) config('customer_follow_up.bot_ids', [])) as $botId) {
            $this->scanBot($botId, $now, $reservations, $telegram);
        }
    }

    private function scanBot(int $botId, CarbonImmutable $now, FollowUpReservationService $reservations, FollowUpTelegramService $telegram): void
    {
        $bot = Bot::query()->find($botId);

        if ($bot === null) {
            return;
        }

        // ---- E. เก็บงานส่งค้างก่อน gate (D104): แถวที่ยิงแล้วต้องถึงจุดจบแม้
        // บอทปิดอยู่ — (r) ถึงคิว และ (s) ค้าง 10 นาที คิวเสมอ, (a) อนุมัติค้าง
        // 2 นาที เมื่อบอทเปิดเท่านั้น (gate เดียวกับ A-D) ----
        $scanStartedAt = microtime(true);
        $botOpen = (string) $bot->status === 'active'
            && (string) $bot->channel_type === 'line'
            && $this->switchIsOn($botId)
            && $this->withinSendingHours($now)
            && (FollowUpCapabilities::forBot($bot, $now)['blocked_reason'] === null);
        $sendJobsQueued = $this->queueSendJobs($botId, $now, $botOpen);

        if ($bot === null
            || (string) $bot->status !== 'active'
            || (string) $bot->channel_type !== 'line'
            || ! $this->switchIsOn($botId)
            || ! $this->withinSendingHours($now)
            || (FollowUpCapabilities::forBot($bot, $now)['blocked_reason'] !== null)
        ) {
            // D104: บอทปิดที่คิวงานส่ง = info line ครั้งเดียว นับเฉพาะงาน step E
            if ($sendJobsQueued > 0) {
                Log::info('Follow-up scan', [
                    'bot_id' => $botId,
                    'rooms_refreshed' => 0,
                    'rooms_failed' => 0,
                    'eligible_rooms' => 0,
                    'candidates_created' => 0,
                    'jobs_queued' => 0,
                    'cards_offered' => 0,
                    'card_results' => [],
                    'send_jobs_queued' => $sendJobsQueued,
                    'milliseconds' => (int) round((microtime(true) - $scanStartedAt) * 1000),
                ]);
            }

            // D97: บอทปิด = SELECT เท่านั้น ไม่เขียน ไม่ HTTP ไม่ log เหนือ debug
            return;
        }

        $startedAt = microtime(true);

        // ---- A. เติม projection ห้องที่มีข้อความใน 30 วัน (D96) ----
        [$refreshed, $failed] = $this->refreshRooms($bot, $now);

        // ---- B. ห้องเงียบที่ผ่าน → candidate (เก่าสุดก่อน, ไม่เกิน 100) ----
        $eligibleRooms = FollowUpEligibility::query($bot, $now)
            ->orderBy('s.last_message_at')
            ->orderBy('conversations.id')
            ->limit(self::MAX_ELIGIBLE_ROOMS)
            ->get();

        $nowString = $now->format('Y-m-d H:i:s');
        $created = 0;
        foreach ($eligibleRooms as $room) {
            try {
                $log = $reservations->candidate($room, $now);
                // แถวที่ candidate เพิ่ง insert มี created_at เป๊ะกับ $now ของสแกนนี้
                // (scan เดียวกันถือ mutex ไม่ทับกัน) — นับเป็น "สร้างรอบนี้"
                if ($log !== null && (string) $log->getRawOriginal('created_at') === $nowString) {
                    $created++;
                }
            } catch (Throwable $failure) {
                $failed++;
                $this->warnRoomFailure($botId, (int) $room->id, $failure);
            }
        }

        // ---- C. เข้าคิวร่างแถวที่ prepare จะเก็บเป๊ะ, รอไม่เกิน 7 วัน ----
        $queued = $this->queueDrafting($botId, $now);

        // ---- D. เสนอการ์ดค้าง (ไม่เกิน 5 ใบ) ----
        $cards = $this->offerLeftBehindCards($botId, $now, $telegram);

        Log::info('Follow-up scan', [
            'bot_id' => $botId,
            'rooms_refreshed' => $refreshed,
            'rooms_failed' => $failed,
            'eligible_rooms' => $eligibleRooms->count(),
            'candidates_created' => $created,
            'jobs_queued' => $queued,
            'cards_offered' => $cards['offered'],
            'card_results' => $cards['results'],
            'send_jobs_queued' => $sendJobsQueued,
            'milliseconds' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /**
     * E. งานส่งค้างของบอท (D104) — SELECT แล้วคิว SendCustomerFollowUp เท่านั้น
     * เขียนอะไรลงตารางไม่ลง ยิง HTTP ไม่มี คิว id น้อยไปมากไม่เกิน 20 ต่อบอทต่อสแกน
     *
     * @return int จำนวนงานที่คิวรอบนี้
     */
    private function queueSendJobs(int $botId, CarbonImmutable $now, bool $botOpen): int
    {
        $nowString = $now->format('Y-m-d H:i:s');
        $sendingCutoff = $now->subMinutes(self::SEND_STALE_MINUTES)->format('Y-m-d H:i:s');
        $approvedCutoff = $now->subMinutes(self::SEND_APPROVED_MIN_AGE)->format('Y-m-d H:i:s');

        $ids = DB::table('follow_up_approvals as a')
            ->join('lead_recovery_logs as l', 'l.id', '=', 'a.lead_recovery_log_id')
            ->where('l.bot_id', $botId)
            ->where(function ($query) use ($nowString, $sendingCutoff, $approvedCutoff, $botOpen): void {
                // (r) ยิงไม่เข้า (LINE 500/timeout) และถึงคิวรอบใหม่แล้ว
                $query->where(function ($retry) use ($nowString): void {
                    $retry->where('l.delivery_status', LeadRecoveryLog::DELIVERY_STATUS_RETRY)
                        ->where('a.status', FollowUpApproval::STATUS_SENDING)
                        ->whereNotNull('l.next_attempt_at')
                        ->where('l.next_attempt_at', '<=', $nowString);
                });
                // (s) งานส่งค้าง — worker ตายทิ้ง
                $query->orWhere(function ($stale) use ($sendingCutoff): void {
                    $stale->where('l.delivery_status', LeadRecoveryLog::DELIVERY_STATUS_SENDING)
                        ->where('a.status', FollowUpApproval::STATUS_SENDING)
                        ->where('l.updated_at', '<=', $sendingCutoff);
                });
                // (a) อนุมัติแล้วแต่งานไม่มา — บอทเปิดเท่านั้น
                if ($botOpen) {
                    $query->orWhere(function ($approved) use ($approvedCutoff): void {
                        $approved->where('l.delivery_status', LeadRecoveryLog::DELIVERY_STATUS_APPROVED)
                            ->where('a.status', FollowUpApproval::STATUS_APPROVED)
                            ->where('l.updated_at', '<=', $approvedCutoff);
                    });
                }
            })
            ->orderBy('a.id')
            ->limit(self::MAX_SEND_JOBS)
            ->pluck('a.id');

        foreach ($ids as $id) {
            SendCustomerFollowUp::dispatch((int) $id);
        }

        return count($ids);
    }

    /** สวิตช์ของบอท — ต้องมี bot_settings เปิดครบสามคอลัมน์ (D97) */
    private function switchIsOn(int $botId): bool
    {
        $settings = DB::table('bot_settings')
            ->where('bot_id', $botId)
            ->first(['follow_up_enabled', 'follow_up_activation_message_id', 'follow_up_activated_at']);

        return $settings !== null
            && (bool) $settings->follow_up_enabled
            && $settings->follow_up_activation_message_id !== null
            && $settings->follow_up_activated_at !== null;
    }

    /** ชั่วโมงส่ง (กรุงเทพฯ) — ขอบรวม 08:00 ตัด 22:00 เหมือน FollowUpEligibility */
    private function withinSendingHours(CarbonImmutable $now): bool
    {
        $local = $now->tz(FollowUpCapabilities::TIMEZONE);
        $secondsOfDay = $local->hour * 3600 + $local->minute * 60 + $local->second;

        [$startHour, $startMinute] = array_map(intval(...), explode(':', FollowUpCapabilities::SEND_START));
        [$endHour, $endMinute] = array_map(intval(...), explode(':', FollowUpCapabilities::SEND_END));

        return $secondsOfDay >= $startHour * 3600 + $startMinute * 60
            && $secondsOfDay < $endHour * 3600 + $endMinute * 60;
    }

    /**
     * A — เติม projection ของห้องที่ผ่านเงื่อนไขห้อง และมีข้อความใน 30 วัน (D96)
     * เดินด้วย id chunks; ห้องไหนล้มจบสแกนไปต่อ พร้อม warning หนึ่งบรรทัด
     *
     * @return array{0: int, 1: int} [rooms_refreshed, rooms_failed]
     */
    private function refreshRooms(Bot $bot, CarbonImmutable $now): array
    {
        $cutoff = $now->tz(FollowUpCapabilities::TIMEZONE)
            ->subDays(self::RECENT_WINDOW_DAYS)
            ->format('Y-m-d H:i:s');
        $refreshed = 0;
        $failed = 0;

        Conversation::query()
            ->where('bot_id', $bot->id)
            ->where('status', 'active')
            ->where('is_handover', false)
            ->whereNull('deleted_at')
            ->where('channel_type', 'line')
            ->where('external_customer_id', '!=', '')
            ->whereHas('messages', fn ($query) => $query->where('messages.created_at', '>=', $cutoff))
            ->orderBy('id')
            ->chunkById(200, function ($rooms) use ($bot, $now, &$refreshed, &$failed): void {
                foreach ($rooms as $room) {
                    try {
                        $this->refreshOne($room, $now);
                        $refreshed++;
                    } catch (Throwable $failure) {
                        $failed++;
                        $this->warnRoomFailure($bot->id, (int) $room->id, $failure);
                    }
                }
            });

        return [$refreshed, $failed];
    }

    /** จุดเดียวที่แตะ projector — protected เพื่อให้เทสทำให้ห้องใดห้องหนึ่งล้มได้ */
    protected function refreshOne(Conversation $room, CarbonImmutable $now): void
    {
        FollowUpSalesProjector::refresh($room, $now);
    }

    /** warning หนึ่งบรรทัดต่อห้องที่ล้ม — ชื่อ class เท่านั้น ห้ามพา message */
    private function warnRoomFailure(int $botId, int $roomId, Throwable $failure): void
    {
        Log::warning('Follow-up scan room failed', [
            'bot_id' => $botId,
            'room_id' => $roomId,
            'exception_class' => $failure::class,
        ]);
    }

    /**
     * C — เข้าคิวร่างแถวที่ prepare จะเก็บเป๊ะ (เงื่อนไขเดียวกับ claim ของ pipeline):
     * candidate; หรือ sort_retry/deferred ที่ next_attempt_at ว่างหรือครบกำหนด;
     * หรือ sorting/drafting ที่ไม่มีการต่ออายุเกิน STALE_CLAIM_MINUTES —
     * จำกัดเฉพาะแถวที่รอไม่เกิน 7 วัน (D97), เก่าสุดก่อน, ไม่เกิน 100 แถว
     */
    private function queueDrafting(int $botId, CarbonImmutable $now): int
    {
        $nowString = $now->format('Y-m-d H:i:s');
        $staleCutoff = $now->subMinutes(FollowUpPipeline::STALE_CLAIM_MINUTES)->format('Y-m-d H:i:s');
        $waitedCutoff = $now->tz(FollowUpCapabilities::TIMEZONE)
            ->subDays(self::WAITING_MAX_DAYS)
            ->format('Y-m-d H:i:s');

        $ids = DB::table('lead_recovery_logs')
            ->where('bot_id', $botId)
            ->where('created_at', '>=', $waitedCutoff)
            ->where(function ($query) use ($nowString, $staleCutoff): void {
                $query
                    ->where('delivery_status', LeadRecoveryLog::DELIVERY_STATUS_CANDIDATE)
                    ->orWhere(function ($due) use ($nowString): void {
                        $due->whereIn('delivery_status', [LeadRecoveryLog::DELIVERY_STATUS_SORT_RETRY, LeadRecoveryLog::DELIVERY_STATUS_DEFERRED])
                            ->where(function ($next) use ($nowString): void {
                                $next->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $nowString);
                            });
                    })
                    ->orWhere(function ($stale) use ($staleCutoff): void {
                        $stale->whereIn('delivery_status', [LeadRecoveryLog::DELIVERY_STATUS_SORTING, LeadRecoveryLog::DELIVERY_STATUS_DRAFTING])
                            ->where('updated_at', '<=', $staleCutoff);
                    });
            })
            ->orderBy('id')
            ->limit(self::MAX_QUEUED_DRAFTS)
            ->pluck('id');

        foreach ($ids as $id) {
            GenerateCustomerFollowUp::dispatch((int) $id);
        }

        return count($ids);
    }

    /**
     * D — เสนอการ์ดค้างของบอทนี้: status pending, state new หรือ sending,
     * ยังไม่หมดอายุ — เก่าสุดก่อน ไม่เกิน 5 ใบ; publish เจอ state ที่ตัวเองปล่อยไว้
     * จะไม่ยิงซ้ำเอง (busy/already_sent/blocked มาจากตัวมัน)
     *
     * @return array{offered: int, results: array<string, int>}
     */
    private function offerLeftBehindCards(int $botId, CarbonImmutable $now, FollowUpTelegramService $telegram): array
    {
        $ids = DB::table('follow_up_approvals as a')
            ->join('lead_recovery_logs as l', 'l.id', '=', 'a.lead_recovery_log_id')
            ->where('l.bot_id', $botId)
            ->where('a.status', FollowUpApproval::STATUS_PENDING)
            ->whereIn('a.telegram_delivery_state', [FollowUpApproval::TELEGRAM_STATE_NEW, FollowUpApproval::TELEGRAM_STATE_SENDING])
            ->where('a.expires_at', '>', $now->format('Y-m-d H:i:s'))
            ->orderBy('a.id')
            ->limit(self::MAX_CARDS_OFFERED)
            ->pluck('a.id');

        $results = [];
        foreach ($ids as $id) {
            $outcome = $telegram->publish((int) $id, $now);
            $results[$outcome] = ($results[$outcome] ?? 0) + 1;
        }

        return ['offered' => count($ids), 'results' => $results];
    }
}
