<?php

namespace Tests\Unit;

use App\Models\UserSetting;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserSettingQuietHoursTest extends TestCase
{
    private function settings(array $attrs): UserSetting
    {
        return (new UserSetting)->forceFill($attrs);
    }

    public function test_null_settings_uses_default_quiet_23_to_8(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 02:00'));
        $this->assertTrue(UserSetting::quietNow(null));

        Carbon::setTestNow(Carbon::parse('2026-07-21 12:00'));
        $this->assertFalse(UserSetting::quietNow(null));
    }

    public function test_boundaries_overnight_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 23:00'));
        $this->assertTrue(UserSetting::quietNow(null)); // เริ่มเงียบพอดี

        Carbon::setTestNow(Carbon::parse('2026-07-21 07:59'));
        $this->assertTrue(UserSetting::quietNow(null)); // ยังอยู่ในช่วง

        Carbon::setTestNow(Carbon::parse('2026-07-21 08:00'));
        $this->assertFalse(UserSetting::quietNow(null)); // พ้นช่วงพอดี
    }

    public function test_disabled_is_never_quiet(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 02:00'));
        $s = $this->settings([
            'quiet_hours_enabled' => false,
            'quiet_hours_start' => '23:00', 'quiet_hours_end' => '08:00',
        ]);
        $this->assertFalse(UserSetting::quietNow($s));
    }

    public function test_non_overnight_range(): void
    {
        $s = $this->settings([
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '12:00', 'quiet_hours_end' => '13:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-21 12:30'));
        $this->assertTrue(UserSetting::quietNow($s));

        Carbon::setTestNow(Carbon::parse('2026-07-21 13:00'));
        $this->assertFalse(UserSetting::quietNow($s));
    }

    public function test_postgres_time_format_with_seconds(): void
    {
        // Postgres คืน time เป็น HH:MM:SS — ต้องตัดเหลือ H:i ก่อนเทียบ
        $s = $this->settings([
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '23:00:00', 'quiet_hours_end' => '08:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-21 02:00'));
        $this->assertTrue(UserSetting::quietNow($s));
    }
}
