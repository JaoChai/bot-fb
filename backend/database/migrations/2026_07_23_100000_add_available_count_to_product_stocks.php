<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            // จำนวนคงเหลือจริงจาก mhha items_available (sync โดย stock:sync-pool ทุก 5 นาที)
            // null = สินค้าไม่ใช่แบบ stock pool (เช่น support_link) ไม่เกี่ยวกับจำนวน
            $table->integer('available_count')->nullable()->after('manual_off');
        });
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn('available_count');
        });
    }
};
