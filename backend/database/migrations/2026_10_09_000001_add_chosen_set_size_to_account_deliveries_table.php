<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ขนาดชุดที่แอดมินเลือกจากปุ่มบนการ์ด (dv|id|setSize) — null = พฤติกรรมเดิม (แบ่งครึ่ง)
// ตั้งใหม่ทุกครั้งที่กดส่ง (ส่งซ้ำหลังพังด้วยขนาดของปุ่มล่าสุด); ค่า null จึงเป็นแค่ "ครั้งนี้ไม่ได้เลือก"
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
