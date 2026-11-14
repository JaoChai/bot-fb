<?php

namespace Tests\Feature\Outage;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด outage-settings Task B1 — migration เพิ่มคอลัมน์ตั้งค่าบอทล่ม
 *
 * R1: up() เพิ่ม outage_recovery_enabled (NOT NULL default true) + ข้อความ 3 ช่อง (nullable text)
 *     — ไม่มี backfill, ไม่มี index, ไม่แตะคอลัมน์เดิม; down() ลดพอดี 4 คอลัมน์นี้
 *     และข้อมูลเดิมต้องรอดทั้ง up และ down
 */
class OutageSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_11_14_000001_add_outage_settings_to_bot_settings.php';

    private const OUTAGE_COLUMNS = [
        'outage_recovery_enabled',
        'outage_recovery_message_th',
        'outage_recovery_message_en',
        'outage_down_message',
    ];

    #[Test]
    public function test_existing_rows_survive_up_and_down(): void
    {
        $migration = require base_path(self::MIGRATION_PATH);

        // เริ่มจาก DB ที่ migrate ครบแล้ว (RefreshDatabase) — เอาคอลัมน์ใหม่ออกก่อนเพื่อจำลอง DB เก่า
        $migration->down();
        $this->assertNoneOfTheColumnsExist();

        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        DB::table('bot_settings')->insert([
            'bot_id' => $bot->id,
            'daily_message_limit' => 1000,
            'offline_message' => 'ข้อความเดิมก่อน migration',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $legacyRowId = (int) DB::table('bot_settings')->where('bot_id', $bot->id)->value('id');

        try {
            $migration->up();
            $this->assertAllOfTheColumnsExist();

            $fresh = BotSetting::query()->findOrFail($legacyRowId);
            $this->assertSame(true, $fresh->outage_recovery_enabled, 'new boolean must default to true');
            $this->assertNull($fresh->outage_recovery_message_th);
            $this->assertNull($fresh->outage_recovery_message_en);
            $this->assertNull($fresh->outage_down_message);
            $this->assertSame('ข้อความเดิมก่อน migration', $fresh->offline_message, 'legacy value must survive up');

            $migration->down();
            $this->assertNoneOfTheColumnsExist();
            $survivor = DB::table('bot_settings')->where('id', $legacyRowId)->first();
            $this->assertNotNull($survivor, 'row must survive down');
            $this->assertSame('ข้อความเดิมก่อน migration', $survivor->offline_message, 'legacy value must survive down');
        } finally {
            // กู้สคีมากลับสู่สถานะ migrate ครบเสมอ ไม่งั้นทดสอบอื่นใน DB เดียวกันจะพัง
            $migration->up();
            $this->assertAllOfTheColumnsExist();
        }
    }

    #[Test]
    public function test_fresh_model_casts_enabled_false_to_boolean(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        DB::table('bot_settings')->insert([
            'bot_id' => $bot->id,
            'outage_recovery_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(false, BotSetting::query()->where('bot_id', $bot->id)->firstOrFail()->outage_recovery_enabled);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function assertAllOfTheColumnsExist(): void
    {
        foreach (self::OUTAGE_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('bot_settings', $column), "column {$column} must exist after up");
        }
    }

    private function assertNoneOfTheColumnsExist(): void
    {
        foreach (self::OUTAGE_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('bot_settings', $column), "column {$column} must be gone after down");
        }
    }
}
