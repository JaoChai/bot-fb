<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('account_delivery_items', function (Blueprint $table) {
            // จำนวนที่ลูกค้าสั่งจริง — ต่างจาก qty (จำนวนที่ระบบจองให้จริง) เมื่อชน
            // เพดาน delivery.max_qty; เก็บไว้เพื่อให้การ์ดบอกเจ้าของได้ว่าขาดไปเท่าไร
            $table->unsignedInteger('requested_qty')->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('account_delivery_items', function (Blueprint $table) {
            $table->dropColumn('requested_qty');
        });
    }
};
