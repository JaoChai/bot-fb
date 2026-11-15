<?php

namespace App\Jobs;

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
 * ส่งข้อความที่เจ้านายกดอนุมัติแล้วให้ลูกค้า — การ์ด B6e (plan §3.6, คำตัดสิน D101-D105)
 *
 * ทำงานเรียบง่าย: เรียกตัวส่งครั้งเดียว (B4) อ่านสถานะปลายทางของแถวแล้วระบายการ์ด
 * Telegram เมื่อแถวถึงจุดจบ (sent/cancelled/expired) เท่านั้น (D102) — แถวที่ถูกคืน
 * กลับ approved คงการ์ด ⏳ เดิม ยิง LINE แล้วยังไม่เข้า (retry) = คิวงานตัวเองซ้ำ
 * เลื่อนถึง next_attempt_at ของแถว (D103); งานหายให้ step E ของสแกนเป็นคนเก็บ
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

        // D102: ระบายการ์ดเมื่อแถวถึงจุดจบเท่านั้น — already_sent ด้วย (ซ่อมการ์ดที่ระบายพลาด)
        if ($approval !== null
            && in_array((string) $approval->status, ['sent', 'cancelled', 'expired'], true)) {
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
        $delaySeconds = (int) ceil($nextAttemptAt->diffInSeconds($now, true));

        self::dispatch($this->approvalId)->delay(max(1, $delaySeconds));
    }
}
