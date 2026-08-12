<?php

namespace App\Console\Commands;

use App\Models\AccountDelivery;
use App\Models\UserSetting;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * เตือนงานส่งของที่เจ้าของยังไม่กดยืนยันใน Telegram (นโยบาย: ไม่คืนของอัตโนมัติ เตือนจนกว่าจะกด)
 */
class RemindPendingDeliveries extends Command
{
    protected $signature = 'delivery:remind';

    protected $description = 'เตือนงานส่งบัญชีที่ค้างกดยืนยันใน Telegram';

    public function handle(AccountDeliveryService $service): int
    {
        $threshold = now()->subMinutes(config_int('delivery.remind_after_minutes', 30));

        $pending = AccountDelivery::with('items', 'bot.user.settings', 'conversation')
            ->where('status', AccountDelivery::STATUS_RESERVED)
            ->where('created_at', '<=', $threshold)
            ->where(fn ($q) => $q->whereNull('last_reminded_at')
                ->orWhere('last_reminded_at', '<=', $threshold))
            ->get();

        $skipped = 0;
        $sent = 0;
        foreach ($pending as $delivery) {
            // เตือนรอบแรกเสมอ แม้อยู่ในช่วงเงียบ — งานที่ยังไม่เคยเตือนแปลว่าการ์ดตอนสร้างงาน
            // อาจไม่เคยไปถึงเลย ปล่อยเงียบ = ลูกค้าที่จ่ายเงินแล้วรอข้ามคืน (เคส #49 ค้าง 9 ชม.)
            // ส่วนการเตือนซ้ำยังเคารพช่วงเงียบเหมือนเดิม
            if ($delivery->last_reminded_at !== null
                && UserSetting::quietNow($delivery->bot?->user?->settings)) {
                $skipped++;

                continue;
            }

            $ageMinutes = (int) $delivery->created_at->diffInMinutes(now());
            if (! $service->sendReminder($delivery, $ageMinutes)) {
                // ห้ามประทับเวลาเตือนเมื่อใบเตือนไม่ออก ไม่งั้นงานจะเสียสิทธิ์ทะลุช่วงเงียบครั้งเดียว
                // ไปฟรีๆ แล้วเงียบยาวถึง 08:00 แบบเคส #49 — ตาข่ายสุดท้ายดับตอนที่ต้องใช้พอดี
                // ยอมให้พยายามซ้ำทุกรอบดีกว่าปล่อยออเดอร์ที่ลูกค้าจ่ายเงินแล้วค้างข้ามคืน
                Log::error('Delivery: reminder never reached Telegram', [
                    'delivery_id' => $delivery->id,
                ]);

                continue;
            }

            $delivery->update(['last_reminded_at' => now()]);
            $sent++;
        }

        if ($skipped > 0) {
            Log::info("Delivery remind: quiet hours, skipped {$skipped}");
        }

        // นับเฉพาะใบที่การ์ดออกจริง — เดิมนับ "ที่พยายาม" ซึ่งกลบเคสส่งไม่สำเร็จไปทั้งหมด
        $this->info("reminded: {$sent}, quiet-skipped: {$skipped}");

        return self::SUCCESS;
    }
}
