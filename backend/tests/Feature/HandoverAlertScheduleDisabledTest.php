<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * การ์ด t_94f5988f — ปิด schedule handover:alert-unanswered (ตัดสินใจเจ้านาย 11 พ.ย. 2026)
 *
 * ทำไมปิด: แอดมินตอบลูกค้าผ่าน LINE OA นอกระบบ ระบบไม่เห็นการตอบ คำสั่งนี้จึง
 * เตือน Telegram "ลูกค้ารอแอดมินตอบ" ทุกแชท handover เป็นเตือนเท็จ
 *
 * กู้คืนได้โดยเพิ่มบรรทัดเดิมกลับใน routes/console.php (ดูคอมเมนต์ในไฟล์นั้น)
 * คลาสคำสั่ง AlertUnansweredHandover, config/handover.php และเทสของมันถูกเก็บไว้ครบ
 */
class HandoverAlertScheduleDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_drops_handover_alert_but_keeps_other_every_minute_jobs(): void
    {
        $this->artisan('schedule:list')->assertExitCode(0);

        $scheduled = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? $event->getSummaryForDisplay());

        $this->assertFalse(
            $scheduled->contains(fn ($entry) => str_contains((string) $entry, 'handover:alert-unanswered')),
            'handover:alert-unanswered ต้องไม่ถูก schedule แล้ว (ปิดตามคำตัดสินใจเจ้านาย 11 พ.ย. 2026)'
        );

        $this->assertTrue(
            $scheduled->contains(fn ($entry) => str_contains((string) $entry, 'conversations:auto-enable-bots')),
            'conversations:auto-enable-bots ยังต้องถูก schedule ตามเดิม'
        );

        $this->assertTrue(
            $scheduled->contains(fn ($entry) => str_contains((string) $entry, 'health:check-realtime')),
            'health:check-realtime ยังต้องถูก schedule ตามเดิม'
        );
    }
}
