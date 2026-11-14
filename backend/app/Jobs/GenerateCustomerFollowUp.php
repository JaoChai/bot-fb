<?php

namespace App\Jobs;

use App\Services\FollowUp\FollowUpPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * งานเตรียมร่าง "ตามลูกค้า" หนึ่งแถว — การ์ด B3c
 *
 * payload คือ id ของ lead_recovery_logs แถวเดียว พอ — โครงร่างอ่านจากฐานข้อมูลเอง
 * ล้ม = จบครั้งเดียว ($tries = 1) แถว sorting ค้างถูกกฎ stale-claim (10 นาที) เก็บเอง
 */
class GenerateCustomerFollowUp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ครั้งเดียว — stale-claim rule เป็นคนกู้แถว ไม่ใช่ retry ของ queue */
    public int $tries = 1;

    /** เพดานแข็ง 180 วินาที — sort/writer/check วัดจริงไว้ไม่เกิน ~5 วินาทีต่อห้อง */
    public int $timeout = 180;

    public function __construct(public int $logId) {}

    public function handle(FollowUpPipeline $pipeline): void
    {
        $pipeline->prepare($this->logId);
    }
}
