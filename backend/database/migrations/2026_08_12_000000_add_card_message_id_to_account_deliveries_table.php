<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_deliveries', function (Blueprint $table) {
            // message_id ของการ์ดปุ่มใบแรกใน Telegram — ใบเตือนซ้ำ reply มาที่ใบนี้แทน
            // การส่งการ์ดใบใหม่ เพื่อให้ 1 งานมีปุ่มกดอยู่ชุดเดียวเสมอ (กันกดส่งซ้ำ)
            // null = การ์ดใบแรกไม่เคยออก → รอบเตือนจะส่งการ์ดเต็มพร้อมปุ่มแทน
            $table->unsignedBigInteger('card_message_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('account_deliveries', function (Blueprint $table) {
            $table->dropColumn('card_message_id');
        });
    }
};
