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
            $shouldBeInStock = ($counts[$product->stock_code] ?? 0) > 0;
            if ($product->in_stock === $shouldBeInStock) {
                continue;
            }

            DB::transaction(function () use ($product, $shouldBeInStock) {
                $product->update(['in_stock' => $shouldBeInStock]);
                $this->clearRagCacheFor($product);
            });
            $changed++;
            Log::info('stock:sync-pool toggled', [
                'slug' => $product->slug, 'in_stock' => $shouldBeInStock,
            ]);
        }

        if ($changed > 0) {
            Cache::forget(ProductStock::STOCK_CACHE_KEY);
        }
        $this->info("changed: {$changed}");

        return self::SUCCESS;
    }

    private function clearRagCacheFor(ProductStock $product): void
    {
        // sqlite (เทสต์) ไม่มี ILIKE — LIKE ของ sqlite case-insensitive อยู่แล้ว
        $op = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $escapedName = '%'.addcslashes($product->name, '%_').'%';
        $query = RagCache::where('query_text', $op, $escapedName);
        foreach ($product->aliases ?? [] as $alias) {
            $query->orWhere('query_text', $op, '%'.addcslashes($alias, '%_').'%');
        }
        $query->delete();
    }
}
