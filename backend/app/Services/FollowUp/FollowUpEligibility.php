<?php

namespace App\Services\FollowUp;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\LeadRecoveryLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ประตู step 1 ของ "ตามลูกค้า": ห้องไหนตามได้ — ONE SQL statement บน read model ไร้ข้อความ (D2)
 *
 * สัญญาจาก plan §3.3 (lines 138-139) และตารางเงื่อนไข (lines 143-157):
 * - ทุกเงื่อนไขเป็น predicate ของ SQL เดียว ห้ามกรองผลลัพธ์ด้วย PHP
 * - นอกชั่วโมงส่ง (08:00-22:00 กรุงเทพฯ) หรือ capabilities ติด block → constant-false
 * - projection เก่ากว่าต้นจังหวะเช็ค หรือ id ไม่ตรงตารางจริง → ไม่ผ่านเด็ดขาด
 * - recheck คืน null เมื่อห้องยังผ่านสำหรับ log ตัวเอง ไม่ผ่าน → reason code เสถียร
 *
 * ตารางเงื่อนไข → predicate (แต่ละบรรทัดใน query()):
 *  145 bot opt-in                → bots.id/status/channel + bot_settings.follow_up_enabled + capabilities (PHP gate)
 *  146 ห้อง active บอทเปิด       → conversations.status/is_handover/deleted_at/channel_type/external_customer_id
 *  147 เทิร์นสุดท้ายเชื่อถือได้   → projected_at >= :now + MAX(id) 3 ตัว (last/last-user/latest-order NULL-safe)
 *  148 span ≥ 2 ข้อความลูกค้า    → customer_message_count >= 2 (ตัวนับโดน projector)
 *  149-150 เงียบตามสาย           → last_message_at <= now-(payment?3h:24h)
 *  151 ไม่มีออเดอร์/สลิปหลังข้อความลูกค้าสุดท้าย → NOT EXISTS orders/slip(passed,manual_confirmed) >= last_user_at
 *  152 เงียบนี้ยังไม่ถูกตาม       → NOT EXISTS log เดียวกัน anchor เดียวกัน สถานะติดขัด (ownLogId ยกเว้น non-sent)
 *  153 ลูกค้าเดียวกัน 7 วัน      → NOT EXISTS log sent เกินหน้าต่าง 7 วัน หรือ sending/retry ที่มี first_push_at
 *  154 แบ็คล็อกตอนเปิด 1-7 วัน   → anchor เก่ากว่า high-water ต้องอยู่ใน [activated-7d, activated-1d] + ไม่มีข้อความหลัง high-water
 *  155 งบร่างรายวัน             → (SELECT COUNT วันนี้กรุงเทพฯ) < 10 (ownLogId ยกเว้นตอน recheck)
 *  156 ชั่วโมงส่ง                → PHP gate 08:00-22:00 → constant-false นอกเวลา
 *  157 ไม่มี account data        → sensitive_account_data = false
 */
class FollowUpEligibility
{
    /** สถานะ log ที่ "เงียบนี้ถูกดูแลแล้ว" — คงที่ตาม plan line 152 */
    private const SILENCE_BLOCKING_STATUSES = ['pending', 'approved', 'sending', 'retry', 'sent', 'skipped', 'expired', 'cancelled', 'excluded'];

    /** สถานะสลิปที่ถือว่า "เงินเข้าจริง" — เดียวกับ SlipController::MONEY_IN */
    private const MONEY_IN_STATUSES = ['passed', 'manual_confirmed'];

    public static function query(Bot $bot, CarbonImmutable $now, ?int $ownLogId = null): Builder
    {
        $now = $now->startOfSecond();

        $builder = Conversation::query()
            ->select('conversations.*')
            ->join('bots', 'bots.id', '=', 'conversations.bot_id')
            ->join('bot_settings as bs', 'bs.bot_id', '=', 'bots.id')
            ->join('follow_up_sales_signals as s', 's.conversation_id', '=', 'conversations.id')
            // 145: bot opt-in + สถานะ + ช่องทาง
            ->where('bots.id', $bot->id)
            ->where('bots.status', 'active')
            ->where('bots.channel_type', 'line')
            ->where('bs.follow_up_enabled', true)
            ->whereNotNull('bs.follow_up_activation_message_id')
            ->whereNotNull('bs.follow_up_activated_at')
            // 146: ห้อง active ยังไม่ handover ยังไม่ลบ LINE มีตัวตนลูกค้า
            ->where('conversations.status', 'active')
            ->where('conversations.is_handover', false)
            ->whereNull('conversations.deleted_at')
            ->where('conversations.channel_type', 'line')
            ->where('conversations.external_customer_id', '!=', '');

        // 156 + capabilities: นอกเวลา/ติด block = constant-false (ยังเป็น SQL เดียวกัน)
        if (! self::withinSendingHours($now)
            || FollowUpCapabilities::forBot($bot, $now)['blocked_reason'] !== null) {
            return $builder->whereRaw('1 = 0');
        }

        $settings = DB::table('bot_settings')
            ->where('bot_id', $bot->id)
            ->first(['follow_up_activation_message_id', 'follow_up_activated_at']);

        // fail-closed: คัตออฟแบ็คล็อกขาด = ไม่มีห้องผ่าน (ยังเป็น SQL เดียวกัน)
        if ($settings === null || $settings->follow_up_activation_message_id === null || $settings->follow_up_activated_at === null) {
            return $builder->whereRaw('1 = 0');
        }

        $activationMessageId = (int) $settings->follow_up_activation_message_id;
        $activatedAt = CarbonImmutable::parse($settings->follow_up_activated_at)->startOfSecond();

        $paymentCut = $now->subHours((int) config('customer_follow_up.payment_wait_hours'))->format('Y-m-d H:i:s');
        $saleCut = $now->subHours((int) config('customer_follow_up.sale_wait_hours'))->format('Y-m-d H:i:s');
        $gapStart = $now->subDays((int) config('customer_follow_up.customer_gap_days'))->format('Y-m-d H:i:s');
        $today = $now->tz(FollowUpCapabilities::TIMEZONE)->toDateString();
        $dailyCap = (int) config('customer_follow_up.daily_draft_cap');

        // 147: projection สด และ id ทั้งสามตรงตารางจริง
        $builder
            ->where('s.projected_at', '>=', $now->format('Y-m-d H:i:s'))
            ->whereRaw('s.last_message_id = (select max(m.id) from messages m where m.conversation_id = conversations.id)')
            ->whereRaw("s.last_user_message_id = (select max(mu.id) from messages mu where mu.conversation_id = conversations.id and mu.sender = 'user')");

        // 147: latest_order_id NULL-safe กับ MAX(orders.id) ของลูกค้านี้บนบอทนี้
        $orderMax = '(select max(o.id) from orders o join conversations oc on oc.id = o.conversation_id'
            ." where o.bot_id = bots.id and oc.channel_type = 'line' and oc.external_customer_id = conversations.external_customer_id)";
        $builder->whereRaw("(s.latest_order_id = {$orderMax} or (s.latest_order_id is null and {$orderMax} is null))");

        // 148: span ปัจจุบันต้องมี ≥ 2 ข้อความลูกค้า
        $builder->where('s.customer_message_count', '>=', 2);

        // 149-150: เงียบครบตามสาย (ขอบ <= นับว่าครบ) — เขียนเป็น OR ให้พารามิเตอร์
        // เทียบตรงกับคอลัมน์ timestamp (PostgreSQL อนุมานชนิดได้, ไม่ต้องใช้ cast เฉพาะฐานข้อมูล)
        $builder->where(function ($q) use ($paymentCut, $saleCut): void {
            $q->where(function ($q2) use ($paymentCut): void {
                $q2->where('s.payment_step', true)->where('s.last_message_at', '<=', $paymentCut);
            })->orWhere(function ($q2) use ($saleCut): void {
                $q2->where('s.payment_step', false)->where('s.last_message_at', '<=', $saleCut);
            });
        });

        // 151: ไม่มีออเดอร์/สลิป "เงินเข้าจริง" หลังข้อความลูกค้าสุดท้าย (ข้ามห้องของลูกค้าเดียวกัน)
        $builder->whereNotExists(function ($q): void {
            $q->selectRaw(1)
                ->from('orders as o2')
                ->join('conversations as c2', 'c2.id', '=', 'o2.conversation_id')
                ->whereColumn('o2.bot_id', 'bots.id')
                ->where('c2.channel_type', 'line')
                ->whereColumn('c2.external_customer_id', 'conversations.external_customer_id')
                ->whereColumn('o2.created_at', '>=', 's.last_user_at');
        })->whereNotExists(function ($q): void {
            $q->selectRaw(1)
                ->from('slip_verifications as sv')
                ->join('conversations as c3', 'c3.id', '=', 'sv.conversation_id')
                ->whereColumn('sv.bot_id', 'bots.id')
                ->where('c3.channel_type', 'line')
                ->whereColumn('c3.external_customer_id', 'conversations.external_customer_id')
                ->whereIn('sv.status', self::MONEY_IN_STATUSES)
                ->whereColumn('sv.created_at', '>=', 's.last_user_at');
        });

        // 152: เงียบนี้ (anchor เดียวกัน) ยังไม่ถูกตาม — ownLogId ยกเว้นได้เฉพาะแถวที่ยังไม่ sent
        $builder->whereNotExists(function ($q) use ($ownLogId): void {
            $q->selectRaw(1)
                ->from('lead_recovery_logs as l2')
                ->whereColumn('l2.conversation_id', 'conversations.id')
                ->whereColumn('l2.silence_user_message_id', 's.last_user_message_id')
                ->whereIn('l2.delivery_status', self::SILENCE_BLOCKING_STATUSES);
            if ($ownLogId !== null) {
                $q->where(function ($q2) use ($ownLogId): void {
                    $q2->where('l2.id', '!=', $ownLogId)
                        ->orWhere('l2.delivery_status', 'sent');
                });
            }
        });

        // 153: ลูกค้าเดียวกันไม่ควรถูกตามซ้ำใน 7 วัน — รวม sending/retry ที่ push แล้ว
        $builder->whereNotExists(function ($q) use ($ownLogId, $gapStart, $bot): void {
            $q->selectRaw(1)
                ->from('lead_recovery_logs as l3')
                ->whereIn('l3.customer_key', self::customerKeys($bot))
                ->where(function ($q2) use ($gapStart): void {
                    $q2->where(function ($q3) use ($gapStart): void {
                        $q3->where('l3.delivery_status', 'sent')
                            ->whereNotNull('l3.sent_at')
                            ->where('l3.sent_at', '>', $gapStart);
                    })->orWhere(function ($q3): void {
                        $q3->whereIn('l3.delivery_status', ['sending', 'retry'])
                            ->whereNotNull('l3.first_push_at');
                    });
                });
            if ($ownLogId !== null) {
                $q->where('l3.id', '!=', $ownLogId);
            }
        });

        // 154: แบ็คล็อกตอนเปิดครั้งแรก — anchor เก่ากว่า high-water ต้องเงียบอยู่แล้ว 1-7 วัน ณ activated
        // และห้ามมีข้อความใดหลัง high-water (ไม่งั้นไม่ใช่เงียบเดิม); anchor ใหม่ใช้ 3h/24h ปกติ
        $builder->where(function ($q) use ($activationMessageId, $activatedAt): void {
            $observedAtActivation = '(select m3.created_at from messages m3 where m3.conversation_id = conversations.id and m3.id <= ? order by m3.id desc limit 1)';

            $q->where('s.last_user_message_id', '>', $activationMessageId)
                ->orWhere(function ($q2) use ($activationMessageId, $activatedAt, $observedAtActivation): void {
                    // เทียบตรงกับคอลัมน์สองข้าง (>= / <=) ให้ PostgreSQL อนุมานชนิดได้ทุกฐานข้อมูล
                    // (raw แต่ละตัวมี 2 placeholder: high-water ใน subquery + ขอบหน้าต่าง)
                    $q2->where('s.last_message_id', '<=', $activationMessageId)
                        ->whereRaw("{$observedAtActivation} >= ?", [$activationMessageId, $activatedAt->subDays(7)->format('Y-m-d H:i:s')])
                        ->whereRaw("{$observedAtActivation} <= ?", [$activationMessageId, $activatedAt->subDays(1)->format('Y-m-d H:i:s')]);
                });
        });

        // 155: งบร่างรายวันของบอทนี้ (วันที่กรุงเทพฯ) — แถวที่ยังจองหรือมีร่างจริงเท่านั้น
        $ownExclusion = $ownLogId !== null ? ' and lc.id != '.((int) $ownLogId) : '';
        $builder->whereRaw(
            '(select count(*) from lead_recovery_logs lc where lc.bot_id = ? and lc.draft_day = ?'
            .' and (lc.drafted_at is not null or lc.draft_reserved_at is not null)'.$ownExclusion.') < ?',
            [$bot->id, $today, $dailyCap]
        );

        // 157: ห้องที่ span มี account/credential = ทำหน้าที่หลังการขาย ห้ามตาม
        $builder->where('s.sensitive_account_data', false);

        return $builder;
    }

    /**
     * ห้องของ log นี้ยังผ่านสำหรับ log ตัวเองหรือยัง — null = ผ่าน,
     * ไม่ผ่าน = reason code เสถียร (ตารางโค้ดอยู่ใน PR body)
     * refresh projection ก่อนเสมอ แล้วเทียบ anchor/observed-last กับ log ตาม plan line 139
     */
    public static function recheck(LeadRecoveryLog $log, CarbonImmutable $now): ?string
    {
        $now = $now->startOfSecond();

        if (! self::withinSendingHours($now)) {
            return 'outside_hours';
        }

        $conversation = $log->conversation()->first();
        $bot = $log->bot()->first();

        if ($bot === null || $bot->status !== 'active') {
            return 'bot_switched_off';
        }

        $caps = FollowUpCapabilities::forBot($bot, $now);
        if ($caps['blocked_reason'] !== null) {
            return 'capabilities_'.$caps['blocked_reason'];
        }

        if (! (bool) DB::table('bot_settings')->where('bot_id', $bot->id)->value('follow_up_enabled')) {
            return 'bot_switched_off';
        }

        if ($conversation === null || $conversation->status !== 'active' || $conversation->deleted_at !== null) {
            return 'room_not_active';
        }

        if ($conversation->is_handover) {
            return 'handover_switched_on';
        }

        FollowUpSalesProjector::refresh($conversation, $now);

        $signal = DB::table('follow_up_sales_signals')->where('conversation_id', $conversation->id)->first();

        if ($signal === null) {
            return 'projection_missing';
        }

        if ($log->silence_user_message_id !== $signal->last_user_message_id) {
            return 'new_customer_message';
        }

        if ($log->observed_last_message_id !== $signal->last_message_id) {
            return 'new_bot_message';
        }

        if (self::orderExistsSince((int) $bot->id, $conversation, $signal->last_user_at)) {
            return 'order_created';
        }

        if (self::moneyInSlipExistsSince((int) $bot->id, $conversation, $signal->last_user_at)) {
            return 'passed_slip';
        }

        $stillPasses = self::query($bot, $now, $log->id)
            ->where('conversations.id', $conversation->id)
            ->exists();

        return $stillPasses ? null : self::diagnose($bot, $now, $signal);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** 08:00:00 ≤ เวลากรุงเทพฯ < 22:00:00 */
    private static function withinSendingHours(CarbonImmutable $now): bool
    {
        $local = $now->tz(FollowUpCapabilities::TIMEZONE);
        $start = self::hourMinuteToSeconds(FollowUpCapabilities::SEND_START);
        $end = self::hourMinuteToSeconds(FollowUpCapabilities::SEND_END);
        $seconds = $local->hour * 3600 + $local->minute * 60 + $local->second;

        return $seconds >= $start && $seconds < $end;
    }

    private static function hourMinuteToSeconds(string $hourMinute): int
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $hourMinute));

        return $hour * 3600 + $minute * 60;
    }

    /**
     * key ของลูกค้าทุกคนบนบอทนี้ (bot|line|external_customer_id → sha256) —
     * เทียบด้วย IN(...) ให้เหมือนกันทั้ง SQLite/PostgreSQL
     *
     * @return list<string>
     */
    private static function customerKeys(Bot $bot): array
    {
        return Conversation::query()
            ->where('bot_id', $bot->id)
            ->where('channel_type', 'line')
            ->whereNotNull('external_customer_id')
            ->distinct()
            ->pluck('external_customer_id')
            ->map(fn (string $externalId): string => hash('sha256', $bot->id.'|line|'.$externalId))
            ->all();
    }

    private static function orderExistsSince(int $botId, Conversation $conversation, ?string $since): bool
    {
        if ($since === null) {
            return false;
        }

        return DB::table('orders')
            ->join('conversations as oc', 'oc.id', '=', 'orders.conversation_id')
            ->where('orders.bot_id', $botId)
            ->where('oc.channel_type', 'line')
            ->where('oc.external_customer_id', $conversation->external_customer_id)
            ->where('orders.created_at', '>=', $since)
            ->exists();
    }

    private static function moneyInSlipExistsSince(int $botId, Conversation $conversation, ?string $since): bool
    {
        if ($since === null) {
            return false;
        }

        return DB::table('slip_verifications')
            ->join('conversations as sc', 'sc.id', '=', 'slip_verifications.conversation_id')
            ->where('slip_verifications.bot_id', $botId)
            ->where('sc.channel_type', 'line')
            ->where('sc.external_customer_id', $conversation->external_customer_id)
            ->whereIn('slip_verifications.status', self::MONEY_IN_STATUSES)
            ->where('slip_verifications.created_at', '>=', $since)
            ->exists();
    }

    /**
     * ตัว gate ตอบว่าไม่ผ่านแล้ว — ไล่หา predicate แรกที่ล้มตามลำดับตาราง (147→157)
     * เพื่อคืนโค้ดเสถียร (ใช้ PHP ตัดสิน "โค้ด" เท่านั้น ไม่ได้ใช้กรองผลลัพธ์)
     */
    private static function diagnose(Bot $bot, CarbonImmutable $now, object $signal): string
    {
        $signalFresh = (string) $signal->projected_at;
        if ($signalFresh < $now->format('Y-m-d H:i:s')) {
            return 'projection_stale';
        }

        $paymentCut = $now->subHours((int) config('customer_follow_up.payment_wait_hours'))->format('Y-m-d H:i:s');
        $saleCut = $now->subHours((int) config('customer_follow_up.sale_wait_hours'))->format('Y-m-d H:i:s');
        $cut = ((int) $signal->payment_step) === 1 ? $paymentCut : $saleCut;
        if ((string) $signal->last_message_at > $cut) {
            return 'wait_time_not_met';
        }

        if ((int) $signal->customer_message_count < 2) {
            return 'span_too_short';
        }

        return 'condition_not_met';
    }
}
