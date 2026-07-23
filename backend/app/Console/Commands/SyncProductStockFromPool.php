<?php

namespace App\Console\Commands;

use App\Models\ProductStock;
use App\Models\RagCache;
use App\Services\Delivery\StockPoolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * sync สวิตช์ product_stocks.in_stock จากจำนวนของจริงใน mhha items_available
 * (บอทจะหยุด/กลับมาเชียร์ขายเองผ่านกลไก stock injection เดิม)
 * ล้าง cache ตาม pattern ของ ProductStockController::update()
 */
class SyncProductStockFromPool extends Command
{
    protected $signature = 'stock:sync-pool';

    protected $description = 'เปิด/ปิดสวิตช์ขายตามจำนวนของจริงใน stock DB';

    public function handle(StockPoolService $pool): int
    {
        try {
            $counts = $pool->countAvailable();
        } catch (\Throwable $e) {
            Log::error('stock:sync-pool cannot read pool', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $changed = 0;
        $products = ProductStock::where('delivery_method', 'stock')->whereNotNull('stock_code')->get();
        foreach ($products as $product) {
            $count = (int) ($counts[$product->stock_code] ?? 0);
            // ปิดค้างที่เจ้าของสั่งเองต้องชนะ pool (แต่ pool ว่างยังบังคับปิด กัน oversell)
            $shouldBeInStock = $count > 0 && ! $product->manual_off;
            $toggled = $product->in_stock !== $shouldBeInStock;
            if (! $toggled && $product->available_count === $count) {
                continue;
            }

            DB::transaction(function () use ($product, $shouldBeInStock, $count, $toggled) {
                $product->update(['in_stock' => $shouldBeInStock, 'available_count' => $count]);
                // RagCache ล้างเฉพาะตอนสวิตช์เปลี่ยน — จำนวนเปลี่ยนอย่างเดียวไม่กระทบคำตอบใน RAG cache
                if ($toggled) {
                    RagCache::purgeForProduct($product);
                }
            });
            $changed++;
            // log เฉพาะตอนสวิตช์เปลี่ยน — จำนวนขยับทุกรอบ cron ถ้า log หมดจะบวมเปล่า
            if ($toggled) {
                Log::info('stock:sync-pool toggled', [
                    'slug' => $product->slug, 'in_stock' => $shouldBeInStock, 'available_count' => $count,
                ]);
            }
        }

        if ($changed > 0) {
            Cache::forget(ProductStock::STOCK_CACHE_KEY);
        }
        $this->info("changed: {$changed}");

        return self::SUCCESS;
    }
}
