<?php

namespace App\Services\FollowUp;

use App\Models\Conversation;
use App\Models\FollowUpSalesSignal;
use App\Models\LeadRecoveryLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * แถว candidate และการจองโควตาร่างรายวันแบบ atomic — plan §3.3 lines 140-141, 155
 *
 * การ์ด B2c (B2 ชุดสุดท้าย): บันทึก candidate/จองโควตาล้วนๆ — ไม่เรียกโมเดล,
 * ไม่มี HTTP, ไม่ยิงส่ง, ไม่มี scheduler/route ให้เรียก
 *
 * - candidate(): insert-or-read ตาม unique (conversation_id, silence_user_message_id);
 *   คนที่ insert สำเร็จคือผู้ชนะ คนที่ชนกันอ่านแถวผู้ชนะกลับไป (lock winner only)
 * - reserveDraft(): transaction เดียว ล็อก bot_settings ก่อนแล้วล็อกแถว log
 *   (ทุก transaction ล็อกตามลำดับเดียวกันจึงไม่ deadlock), นับด้วยกฎเดียวกับประตู
 *   (FollowUpEligibility line 155) ต่ำกว่า daily_draft_cap จึงเขียนจอง; ร่างที่
 *   ผลิตแล้ว (drafted_at) ไม่จองซ้ำอีกทุกวัน และไม่แตะแถว
 * - markDraftProduced(): ร่างผลิตแล้ว — เขียน drafted_at และใช้ token ทิ้ง
 *   (claim_token = null) เพื่อให้ release ภายหลังคืน slot ไม่ได้ ไม่ว่าร่างจะเป็นอย่างไร
 * - releaseDraft(): ล้มเหลวโดยไม่มีร่าง — คืน slot ทั้งสี่คอลัมน์เป็น null
 *
 * เวลาทุกคอลัมน์เป็นกรุงเทพฯ แบบไร้โซน (house style เดียวกับ B2b) — จึงเขียนผ่าน
 * query builder ด้วย string 'Y-m-d H:i:s' ไม่ผ่าน cast ของโมเดล (แปลงตาม app tz = UTC)
 */
class FollowUpReservationService
{
    /**
     * insert-or-read แถว candidate ของเงียบปัจจุบันของห้อง — anchor/ธงเอาจาก
     * แถว follow_up_sales_signals ล่าสุด เรียกซ้ำได้เสมอ (คืนแถวเดิม ไม่แก้อะไร)
     *
     * @throws InvalidArgumentException เมื่อห้องไม่มี signal row หรือไม่มีข้อความลูกค้าสุดท้าย
     */
    public function candidate(Conversation $conversation, CarbonImmutable $now): LeadRecoveryLog
    {
        $now = $now->tz(FollowUpCapabilities::TIMEZONE)->startOfSecond();

        $signal = FollowUpSalesSignal::query()
            ->where('conversation_id', $conversation->id)
            ->first();

        if ($signal === null) {
            throw new InvalidArgumentException('follow-up candidate requires a signal row (refresh the projection first): code candidate_signal_missing');
        }

        $silenceMessageId = $signal->last_user_message_id;

        if ($silenceMessageId === null) {
            throw new InvalidArgumentException('follow-up candidate requires a last customer message as silence anchor: code candidate_anchor_missing');
        }

        $existing = $this->findBySilence((int) $conversation->id, $silenceMessageId);

        if ($existing !== null) {
            return $existing;
        }

        // customer_key = SHA-256 ของ bot|channel|external_customer_id — ไม่ข้ามบอท/ช่องทาง (plan line 101)
        $customerKey = hash('sha256', $conversation->bot_id.'|'.$conversation->channel_type.'|'.$conversation->external_customer_id);

        try {
            DB::table('lead_recovery_logs')->insert([
                'conversation_id' => $conversation->id,
                'bot_id' => $conversation->bot_id,
                'attempt_number' => 0,
                'message_mode' => 'ai',
                'message_sent' => null,
                'sent_at' => null,
                'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_CANDIDATE,
                'silence_user_message_id' => $silenceMessageId,
                'observed_last_message_id' => $signal->last_message_id,
                'customer_key' => $customerKey,
                'sales_started_at' => $signal->sales_started_at?->format('Y-m-d H:i:s'),
                'payment_step' => $signal->payment_step,
                'transfer_summary_sent' => $signal->transfer_summary_sent,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (QueryException $uniqueRace) {
            // แพ้การแข่ง unique key — อ่านแถวของผู้ชนะแทน (insert-or-read)
            $existing = $this->findBySilence((int) $conversation->id, $silenceMessageId);

            if ($existing !== null) {
                return $existing;
            }

            throw $uniqueRace;
        }

        $created = $this->findBySilence((int) $conversation->id, $silenceMessageId);

        if ($created === null) {
            // insert สำเร็จแต่อ่านไม่เจอ = สภาพที่ตาราง/unique key ผิดปกติ — หยุดเสียงเดียว
            throw new InvalidArgumentException('candidate insert succeeded but the row is not readable: code candidate_read_missing');
        }

        return $created;
    }

    /**
     * จองโควตาร่างของวันนี้ (กรุงเทพฯ) ให้ log — true เมื่อจองได้หรือ log นี้ถือ
     * slot วันนี้อยู่แล้ว (จองซ้ำไม่กินโควตาใหม่), false เมื่อ cap เต็ม/แถวหาย/
     * ไม่มี bot_settings หรือร่างถูกผลิตไปแล้ว (drafted_at ไม่ null = ไม่จองซ้ำ
     * อีกทั้งวันไหน แถวไม่ถูกแตะ) — กรณี false แถวคงเดิมทุกคอลัมน์
     */
    public function reserveDraft(LeadRecoveryLog $log, CarbonImmutable $now): bool
    {
        $now = $now->tz(FollowUpCapabilities::TIMEZONE)->startOfSecond();
        $today = $now->toDateString();

        return DB::transaction(function () use ($log, $now, $today): bool {
            // ล็อก bot_settings ก่อนแถว log เสมอ — ทุก transaction เดินตามลำดับเดียวกัน
            $settings = DB::table('bot_settings')
                ->where('bot_id', $log->bot_id)
                ->lockForUpdate()
                ->first();

            if ($settings === null) {
                return false;
            }

            $row = DB::table('lead_recovery_logs')
                ->where('id', $log->id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return false;
            }

            // ร่างผลิตแล้ว (drafted_at ไม่ null) — วันไหนก็ตามไม่จองซ้ำ ไม่เขียนแถวเลย
            // (one draft per silence: owner edit / recheck ของร่างเดิมไม่เรียก reserveDraft)
            if ($row->drafted_at !== null) {
                return false;
            }

            // log นี้ถือ slot วันนี้อยู่แล้ว (จองค้าง ยังไม่มีร่าง) — ไม่กิน slot ที่สอง (plan line 141)
            if ($row->draft_day === $today && $row->draft_reserved_at !== null) {
                return true;
            }

            // กฎนับเดียวกับประตู: draft_day = วันนี้กรุงเทพฯ และ (drafted_at หรือ draft_reserved_at ไม่ null)
            $used = DB::table('lead_recovery_logs')
                ->where('bot_id', $row->bot_id)
                ->where('draft_day', $today)
                ->where(function ($query): void {
                    $query->whereNotNull('drafted_at')->orWhereNotNull('draft_reserved_at');
                })
                ->count();

            if ($used >= (int) config('customer_follow_up.daily_draft_cap')) {
                return false;
            }

            DB::table('lead_recovery_logs')->where('id', $row->id)->update([
                'draft_day' => $today,
                'draft_reserved_at' => $now->format('Y-m-d H:i:s'),
                'claimed_at' => $now->format('Y-m-d H:i:s'),
                'claim_token' => (string) Str::uuid(),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);

            return true;
        });
    }

    /**
     * ร่างถูกผลิตแล้ว — เขียน drafted_at และใช้ claim ทิ้ง (claim_token/claimed_at = null):
     * slot ของวันนี้ถูกใช้ไปแล้วและ release ภายหลังจะคืนไม่ได้ ไม่ว่าร่างจะถูกตรวจผ่าน/
     * ไม่ผ่าน/ถูกข้ามอย่างไร คืน true เฉพาะ token ตรงกับ claim_token ที่เก็บ
     */
    public function markDraftProduced(LeadRecoveryLog $log, string $claimToken, CarbonImmutable $now): bool
    {
        $now = $now->tz(FollowUpCapabilities::TIMEZONE)->startOfSecond();

        return DB::transaction(function () use ($log, $claimToken, $now): bool {
            $row = DB::table('lead_recovery_logs')
                ->where('id', $log->id)
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->claim_token === null || ! hash_equals($row->claim_token, $claimToken)) {
                return false;
            }

            DB::table('lead_recovery_logs')->where('id', $row->id)->update([
                'drafted_at' => $now->format('Y-m-d H:i:s'),
                'claimed_at' => null,
                'claim_token' => null,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);

            return true;
        });
    }

    /**
     * คืนการจองหลังความล้มเหลวที่ยังไม่มีร่าง — null draft_reserved_at/draft_day/
     * claimed_at/claim_token ให้ slot ว่างคืน คืน true เฉพาะ token ตรงกับ claim_token
     * ที่เก็บ และร่างยังไม่ถูกผลิต (drafted_at = null) — ร่างที่ผลิตแล้วคืน slot ไม่ได้
     */
    public function releaseDraft(LeadRecoveryLog $log, string $claimToken, CarbonImmutable $now): bool
    {
        $now = $now->tz(FollowUpCapabilities::TIMEZONE)->startOfSecond();

        return DB::transaction(function () use ($log, $claimToken, $now): bool {
            $row = DB::table('lead_recovery_logs')
                ->where('id', $log->id)
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->claim_token === null || ! hash_equals($row->claim_token, $claimToken)) {
                return false;
            }

            if ($row->drafted_at !== null) {
                return false;
            }

            DB::table('lead_recovery_logs')->where('id', $row->id)->update([
                'draft_day' => null,
                'draft_reserved_at' => null,
                'claimed_at' => null,
                'claim_token' => null,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);

            return true;
        });
    }

    /** หาแถว candidate ของคู่ (ห้อง, ข้อความลูกค้าสุดท้าย) — unique key ของตาราง */
    private function findBySilence(int $conversationId, int $silenceMessageId): ?LeadRecoveryLog
    {
        return LeadRecoveryLog::query()
            ->where('conversation_id', $conversationId)
            ->where('silence_user_message_id', $silenceMessageId)
            ->first();
    }
}
