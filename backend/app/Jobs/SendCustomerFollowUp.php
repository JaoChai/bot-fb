<?php

namespace App\Jobs;

use App\Models\FollowUpApproval;
use App\Models\LeadRecoveryLog;
use App\Services\FollowUp\FollowUpCapabilities;
use App\Services\FollowUp\FollowUpSendService;
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
 * ส่งข้อความที่เจ้านายกดอนุมัติแล้วให้ลูกค้า — การ์ด B6e (plan §3.6, D101-D105)
 * + B3b: เส้นทางของแถว automatic (โหมดส่งเอง — ยังหลับ ทุกบอทยัง approve)
 *
 * ทำงานเรียบง่าย: เรียกตัวส่งครั้งเดียว (B4) อ่านสถานะปลายทาง "ที่ commit แล้ว" ของแถว
 * แถวมนุษย์: ระบายการ์ด Telegram เมื่อถึงจุดจบ (sent/cancelled/expired) เท่านั้น (D102)
 * แถว automatic: sent/sent = แจ้งห้องเตือนครั้งเดียว (B2 publishAutomaticNotice, CAS
 * กันยิงซ้ำ) ไม่มีวันระบายการ์ด; pending/pending state new reason auto_* = คืนการ์ด
 * ปกติ (publish) กรณี revert ของตัวส่ง publish ไม่สำเร็จ; จุดจบอื่นและ sending/retry =
 * เงียบ — แถวที่ยิงแล้ว (first_push set) ไม่มีวันกลายเป็นการ์ด (A11/A16)
 *
 * แถวที่ถูกคืนกลับ approved คงการ์ด ⏳ เดิม ยิง LINE แล้วยังไม่เข้า (retry) = คิวงาน
 * ตัวเองซ้ำ เลื่อนถึง next_attempt_at ของแถว (D103); งานหายให้ step E ของสแกนเป็นคนเก็บ
 *
 * tries 1 + timeout 60 วินาที คิวปริยายของระบบ payload ต่างกันเฉพาะ approvalId
 * log ทุกบรรทัดไม่พาข้อความ / ลูกค้า / retry key / token — มีแต่ approval id
 */
class SendCustomerFollowUp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ยิงครั้งเดียว — พลาดให้ step E ของสแกนเป็นคนเก็บงาน (D103) */
    public int $tries = 1;

    /** เพดานแข็ง 60 วินาที — LINE ตั้ง connect 3s/timeout 5s ต่อ request */
    public int $timeout = 60;

    public function __construct(public int $approvalId) {}

    public function handle(FollowUpSendService $sender, FollowUpTelegramService $telegram): void
    {
        // ส่งก่อนเสมอ — exception ของตัวส่งโยนออกจาก job ทั้งบรรทัด (D105)
        $result = $sender->send($this->approvalId);

        $approval = DB::table('follow_up_approvals')
            ->where('id', $this->approvalId)
            ->first();
        $log = $approval === null
            ? null
            : DB::table('lead_recovery_logs')
                ->where('id', (int) $approval->lead_recovery_log_id)
                ->first();

        if ($log !== null
            && (string) ($log->owner_action ?? '') === LeadRecoveryLog::OWNER_ACTION_AUTOMATIC) {
            $this->routeAutomaticRow($telegram, $approval, $log);
        } elseif ($approval !== null
            && in_array((string) $approval->status, ['sent', 'cancelled', 'expired'], true)) {
            // D102: ระบายการ์ดเมื่อแถวถึงจุดจบเท่านั้น — already_sent ด้วย (ซ่อมการ์ดที่ระบายพลาด)
            try {
                $telegram->refreshCard($this->approvalId);
            } catch (Throwable $repaintFailure) {
                // การ์ดพังไม่เปลี่ยนผลการส่ง — เตือนบรรทัดเดียว เฉพาะชื่อ class (D105)
                Log::warning('Customer follow-up card repaint failed', [
                    'approval_id' => $this->approvalId,
                    'exception_class' => $repaintFailure::class,
                ]);
            }
        }

        if ($result !== 'retry' || $approval === null) {
            return;
        }

        // D103: retry = คิวงานตัวเองซ้ำ เลื่อนถึง next_attempt_at ของแถว
        $log = DB::table('lead_recovery_logs')
            ->where('id', (int) $approval->lead_recovery_log_id)
            ->first();

        if ($log === null || $log->next_attempt_at === null) {
            return;
        }

        $now = CarbonImmutable::now(FollowUpCapabilities::TIMEZONE)->startOfSecond();
        $nextAttemptAt = CarbonImmutable::parse((string) $log->next_attempt_at, FollowUpCapabilities::TIMEZONE)
            ->startOfSecond();
        $delaySeconds = (int) ceil($now->diffInSeconds($nextAttemptAt, false));

        self::dispatch($this->approvalId)->delay(max(1, $delaySeconds));
    }

    /**
     * เส้นทางแถว automatic หลังตัวส่งจบ — อ่านสถานะ commit แล้ว ไม่ยิง LINE เอง
     * ทุกการแจ้ง/การ์ดผ่านบริการ Telegram เดิม และพังก็ไม่เปลี่ยนผลการส่ง (A5/A9):
     * log เตือนบรรทัดเดียว เฉพาะชื่อ class ไม่พาข้อความลูกค้า
     */
    private function routeAutomaticRow(FollowUpTelegramService $telegram, ?object $approval, ?object $log): void
    {
        if ($approval === null || $log === null) {
            return;
        }

        $pair = [(string) $log->delivery_status, (string) $approval->status];

        // ส่งถึงลูกค้าสำเร็จ = แจ้งห้องเตือนครั้งเดียว (B2) — ไม่มีวันระบายการ์ดทับข้อความแจ้ง
        if ($pair === [LeadRecoveryLog::DELIVERY_STATUS_SENT, FollowUpApproval::STATUS_SENT]) {
            try {
                $telegram->publishAutomaticNotice($this->approvalId);
            } catch (Throwable $noticeFailure) {
                Log::warning('Customer follow-up automatic notice failed', [
                    'approval_id' => $this->approvalId,
                    'exception_class' => $noticeFailure::class,
                ]);
            }

            return;
        }

        // คืนการ์ดปกติ (fallback): revert ของตัวส่งยังไม่ได้ publish — เห็นได้จาก
        // state new + reason auto_* (revert ล้าง owner_action เหลือ reason_code ไว้)
        if ($pair === [LeadRecoveryLog::DELIVERY_STATUS_PENDING, FollowUpApproval::STATUS_PENDING]
            && (string) $approval->telegram_delivery_state === FollowUpApproval::TELEGRAM_STATE_NEW
            && str_starts_with((string) ($log->reason_code ?? ''), 'auto_')) {
            try {
                $telegram->publish($this->approvalId);
            } catch (Throwable $publishFailure) {
                Log::warning('Customer follow-up automatic fallback card publish failed', [
                    'approval_id' => $this->approvalId,
                    'exception_class' => $publishFailure::class,
                ]);
            }

            return;
        }

        // จุดจบอื่น (cancelled/expired) และ sending/retry: เงียบ — แถวที่ยิงแล้ว
        // ไม่มีวันกลายเป็นการ์ดที่กดได้ (A11/A16) และ retry คิวตัวเองตาม D103 แล้ว
    }
}
