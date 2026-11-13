<?php

namespace Tests\Feature\Outage;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * การ์ด t_44675510 — outage:send-recovery-notices ต้องถูก schedule ทุก 5 นาที
 * (สไตล์เดียวกับ HandoverAlertScheduleDisabledTest: เช็คผ่าน Schedule events จริง)
 */
class OutageRecoveryNoticeScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_notices_command_is_scheduled_every_five_minutes(): void
    {
        $this->artisan('schedule:list')->assertExitCode(0);

        $events = collect(app(Schedule::class)->events());

        $entry = $events->first(
            fn ($event) => str_contains((string) ($event->command ?? ''), 'outage:send-recovery-notices')
        );
        $this->assertNotNull($entry, 'outage:send-recovery-notices ต้องถูก schedule');
        $this->assertSame('*/5 * * * *', $entry->expression, 'ต้องรันทุก 5 นาที');

        // entry เดิมที่มีอยู่ต้องยังอยู่ครบ
        $scheduled = $events->map(fn ($event) => $event->command ?? $event->getSummaryForDisplay());

        $this->assertTrue(
            $scheduled->contains(fn ($entry) => str_contains((string) $entry, 'conversations:auto-enable-bots')),
            'conversations:auto-enable-bots ยังต้องถูก schedule ตามเดิม'
        );
        $this->assertTrue(
            $scheduled->contains(fn ($entry) => str_contains((string) $entry, 'health:check-realtime')),
            'health:check-realtime ยังต้องถูก schedule ตามเดิม'
        );
        $this->assertTrue(
            $scheduled->contains(fn ($entry) => str_contains((string) $entry, 'stock:sync-pool')),
            'stock:sync-pool ยังต้องถูก schedule ตามเดิม'
        );
    }
}
