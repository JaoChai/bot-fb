<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slip_verifications', function (Blueprint $table) {
            // ด่านไหนที่หาออเดอร์เจอ: summary (regex สรุปยอด) | confirm (regex ข้อความยืนยัน)
            // | llm (ระบบสรุปเองจากบทสนทนา) | null (หาไม่เจอ) — ไว้วัดผลว่าด่านใหม่ช่วยจริงไหม
            $table->string('order_source', 16)->nullable()->after('status');
            // ออเดอร์ที่ระบบสรุปเอง + ตัวเลือกตอนกำกวม (ให้ปุ่ม Telegram อ่านไปสร้างตัวเลือก)
            $table->jsonb('reconstructed')->nullable()->after('order_source');
        });
    }

    public function down(): void
    {
        Schema::table('slip_verifications', function (Blueprint $table) {
            $table->dropColumn(['order_source', 'reconstructed']);
        });
    }
};
