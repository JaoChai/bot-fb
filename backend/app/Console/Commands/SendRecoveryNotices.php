<?php

namespace App\Console\Commands;

use App\Services\OutageRecoveryService;
use Illuminate\Console\Command;

/**
 * การ์ด t_44675510 — หลังบอทกลับมาตอบได้ (กุญแจ OpenRouter ใช้ได้อีกครั้ง):
 * ส่งข้อความขอให้ลูกค้าที่ค้างตอนล่มพิมพ์ใหม่ (1 ข้อความ/ห้อง, เฉพาะ 08:00-22:00)
 * แล้วสรุปยอดต่อบอทให้เจ้าของทาง Telegram กฎทั้งหมดอยู่ใน OutageRecoveryService
 * เพื่อให้เทสต์เรียกถึง logic ได้ตรงๆ
 */
class SendRecoveryNotices extends Command
{
    protected $signature = 'outage:send-recovery-notices';

    protected $description = 'ส่งข้อความขอให้ลูกค้าพิมพ์ใหม่ในห้องที่ค้างจากตอนบอทล่ม (OpenRouter ปฏิเสธกุญแจ) แล้วสรุปให้เจ้าของ';

    public function handle(OutageRecoveryService $recovery): int
    {
        $recovery->run();

        return self::SUCCESS;
    }
}
