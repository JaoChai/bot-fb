<?php

namespace App\Jobs;

use App\Models\FollowUpApproval;
use App\Services\FollowUp\FollowUpPipeline;
use App\Services\FollowUp\FollowUpTelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * งานเตรียมร่าง "ตามลูกค้า" หนึ่งแถว — การ์ด B3c, เสนอการ์ดเองตั้งแต่ B6d (D98)
 *
 * payload คือ id ของ lead_recovery_logs แถวเดียว พอ — โครงร่างอ่านจากฐานข้อมูลเอง
 * ล้ม = จบครั้งเดียว ($tries = 1) แถว sorting ค้างถูกกฎ stale-claim (10 นาที) เก็บเอง
 *
 * D98: prepare จบด้วย approval pending ที่ state new แปลว่าร่างพร้อม — งานนี้เสนอ
 * การ์ดทันที (publish หนึ่งครั้ง) ไม่รอสแกนถัดไป; การ์ดที่โผล่มาจากสแกนจึงเหลือ
 * เฉพาะใบที่ค้างจากรอบก่อน ผลลัพธ์อื่นของ prepare (excluded/cancelled/deferred/
 * sort_retry/candidate) ไม่มี approval จึงไม่มีอะไรเสนอ และ exception ที่หลุดจาก
 * prepare หยุดงานก่อนถึงการเสนอเสมอ
 */
class GenerateCustomerFollowUp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ครั้งเดียว — stale-claim rule เป็นคนกู้แถว ไม่ใช่ retry ของ queue */
    public int $tries = 1;

    /** เพดานแข็ง 180 วินาที — sort/writer/check วัดจริงไว้ไม่เกิน ~5 วินาทีต่อห้อง */
    public int $timeout = 180;

    public function __construct(public int $logId) {}

    public function handle(FollowUpPipeline $pipeline, FollowUpTelegramService $telegram): void
    {
        $pipeline->prepare($this->logId);

        // D98: ร่างพร้อม = เสนอการ์ดทันที — approval ของแถวนี้คือใบเดียวของงานนี้
        // (unique บน lead_recovery_log_id); state อื่นที่ไม่ใช่ new แปลว่ามีสายอื่น
        // กำลังพาการ์ดอยู่แล้ว งานนี้ไม่ยุ่ง
        $approval = DB::table('follow_up_approvals')
            ->where('lead_recovery_log_id', $this->logId)
            ->orderByDesc('id')
            ->first();

        if ($approval === null
            || (string) $approval->status !== FollowUpApproval::STATUS_PENDING
            || (string) $approval->telegram_delivery_state !== FollowUpApproval::TELEGRAM_STATE_NEW
        ) {
            return;
        }

        $telegram->publish((int) $approval->id);
    }
}
