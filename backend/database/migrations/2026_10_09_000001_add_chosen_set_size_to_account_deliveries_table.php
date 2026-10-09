<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ขนาดชุดที่แอดมินเลือกจากปุ่มบนการ์ด (dv|id|setSize) — null = พฤติกรรมเดิม (แบ่งครึ่ง)
// จำสถานะไว้เพราะรอบหลังพังต้องกดส่งซ้ำด้วยขนาดชุดเดิม ไม่ใช่เริ่มแบ่งครึ่งใหม่เอง
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_deliveries', function (Blueprint $table) {
            $table->unsignedInteger('chosen_set_size')->nullable()->after('card_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('account_deliveries', function (Blueprint $table) {
            $table->dropColumn('chosen_set_size');
        });
    }
};
