<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductStock;
use App\Models\RagCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductStockController extends Controller
{
    /**
     * List all product stocks ordered by display_order.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isOwner()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $products = ProductStock::orderBy('display_order')->get();

        return response()->json(['data' => $products]);
    }

    /**
     * Toggle in_stock for a product by slug.
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (! $user->isOwner()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $product = ProductStock::where('slug', $slug)->firstOrFail();

        $validated = $request->validate([
            'in_stock' => 'required|boolean',
        ]);

        DB::transaction(function () use ($product, $validated) {
            // ปิดมือ = ปักหมุดปิด (stock:sync-pool จะไม่เปิดกลับ); เปิดมือ = คืนสิทธิ์ให้ auto-sync
            $product->update([
                'in_stock' => $validated['in_stock'],
                'manual_off' => ! $validated['in_stock'],
            ]);

            // Clear semantic cache entries atomically with the stock update
            if ($product->wasChanged('in_stock')) {
                RagCache::purgeForProduct($product);
            }
        });

        // Invalidate stock cache after transaction commits
        Cache::forget(ProductStock::STOCK_CACHE_KEY);

        return response()->json(['data' => $product->fresh()]);
    }
}
