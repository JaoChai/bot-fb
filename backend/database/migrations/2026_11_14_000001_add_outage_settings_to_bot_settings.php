<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->guardLockTime();
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->boolean('outage_recovery_enabled')->default(true);
            $table->text('outage_recovery_message_th')->nullable();
            $table->text('outage_recovery_message_en')->nullable();
            $table->text('outage_down_message')->nullable();
        });
    }

    public function down(): void
    {
        $this->guardLockTime();
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'outage_recovery_enabled',
                'outage_recovery_message_th',
                'outage_recovery_message_en',
                'outage_down_message',
            ]);
        });
    }

    /**
     * Postgres ยังต้องล็อกสั้นๆ ตอน ALTER TABLE — จำกัดไว้ระดับ transaction-local เท่านั้น
     * (SQLite ของ environment ทดสอบไม่มีคำสั่งเหล่านี้ ห้ามยิง)
     */
    private function guardLockTime(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement("set local lock_timeout = '5s'");
        Schema::getConnection()->statement("set local statement_timeout = '30s'");
    }
};
