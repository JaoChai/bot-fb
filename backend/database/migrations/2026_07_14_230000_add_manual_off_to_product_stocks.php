<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            // เจ้าของสั่งปิดค้างเอง — stock:sync-pool จะไม่เปิดกลับ (แต่ pool ว่างยังบังคับปิดได้ กัน oversell)
            $table->boolean('manual_off')->default(false)->after('in_stock');
        });
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn('manual_off');
        });
    }
};
