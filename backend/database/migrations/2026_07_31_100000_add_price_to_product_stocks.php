<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** ราคาต่อชิ้นที่ยืนยันจากข้อมูลจริง (prompt flow 24 + order_items ย้อนหลัง) */
    private const PRICES = [
        'personal' => 1100,
        'bm' => 1100,
        'page' => 199,
        'g3d' => 50,
    ];

    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            // ราคาต่อชิ้น — ใช้เป็นตัวตรวจว่าออเดอร์ที่ระบบสรุปเองรวมแล้วตรงยอดที่ลูกค้าโอนจริงไหม
            // null = ยังไม่ได้ตั้งราคา → สินค้านั้นจะไม่ถูกใช้ในการสรุปออเดอร์อัตโนมัติ
            $table->decimal('price', 10, 2)->nullable()->after('available_count');
        });

        foreach (self::PRICES as $slug => $price) {
            DB::table('product_stocks')->where('slug', $slug)->update(['price' => $price]);
        }
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
