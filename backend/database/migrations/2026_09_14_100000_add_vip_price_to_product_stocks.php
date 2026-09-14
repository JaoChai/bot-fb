<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VIP_PRICES = [
        'NLMP' => 1000,
        'NLMBM' => 1000,
    ];

    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->decimal('vip_price', 10, 2)->nullable()->after('price');
        });

        foreach (self::VIP_PRICES as $stockCode => $price) {
            DB::table('product_stocks')
                ->where('stock_code', $stockCode)
                ->orWhere('slug', $stockCode === 'NLMP' ? 'personal' : 'bm')
                ->update(['vip_price' => $price]);
        }
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn('vip_price');
        });
    }
};
