<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ค่าที่อนุญาตของ bot_settings.follow_up_mode (plan §3.1)
     * BotSetting.php เป็นของการ์ด B5 — ค่าคงที่พักไว้ที่นี่ก่อน แล้ว B5 จะย้ายไปบนโมเดล
     */
    public const FOLLOW_UP_MODES = ['approve', 'automatic'];

    public function up(): void
    {
        $this->guardLockTime();

        Schema::table('bot_settings', function (Blueprint $table) {
            $table->boolean('follow_up_enabled')->default(false);
            $table->string('follow_up_mode', 16)->default('approve');
            // เปิดครั้งแรกสำเร็จเท่านั้น — ไม่ใช่ค่าที่ client เขียนได้ และไม่รีเซ็ตเมื่อปิด
            $table->timestamp('follow_up_started_at')->nullable();
            // ภาพระดับสูงสุดของ messages.id ตอนเปิด — ตั้งใจไม่ทำ FK (ลบ message ต้องไม่ลบคัตออฟ)
            $table->unsignedBigInteger('follow_up_activation_message_id')->nullable();
            // คัตออฟแบ็คล็อกครั้งแรก ใช้ค่าเดียวกับ started_at ตอนเปิดครั้งแรก (ชื่อแยกให้ความหมายชัด)
            $table->timestamp('follow_up_activated_at')->nullable();
        });

        $this->addFollowUpModeCheck();
    }

    public function down(): void
    {
        $this->guardLockTime();

        $this->dropFollowUpModeCheck();

        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'follow_up_enabled',
                'follow_up_mode',
                'follow_up_started_at',
                'follow_up_activation_message_id',
                'follow_up_activated_at',
            ]);
        });
    }

    /**
     * CHECK ของ follow_up_mode เพิ่มเฉพาะ PostgreSQL (ALTER TABLE ADD CONSTRAINT)
     * SQLite ของสภาพแวดล้อมทดสอบเพิ่ม constraint ในตารางเดิมไม่ได้ — เข้มงวดที่ชั้นแอปพลิเคชันแทน
     * (ประกาศเส้นทาง SQLite นี้ใน PR body ตามสัญญาของการ์ด)
     */
    private function addFollowUpModeCheck(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $modes = implode(', ', array_map(
            fn (string $mode): string => "'".$mode."'",
            self::FOLLOW_UP_MODES
        ));

        Schema::getConnection()->statement(
            "alter table bot_settings add constraint bot_settings_follow_up_mode_check check (follow_up_mode in ({$modes}))"
        );
    }

    private function dropFollowUpModeCheck(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        Schema::getConnection()->statement(
            'alter table bot_settings drop constraint if exists bot_settings_follow_up_mode_check'
        );
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }

    /**
     * Postgres ยังต้องล็อกสั้นๆ ตอน ALTER TABLE — จำกัดไว้ระดับ transaction-local เท่านั้น
     * (SQLite ของ environment ทดสอบไม่มีคำสั่งเหล่านี้ ห้ามยิง)
     */
    private function guardLockTime(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        Schema::getConnection()->statement("set local lock_timeout = '5s'");
        Schema::getConnection()->statement("set local statement_timeout = '30s'");
    }
};
