<?php

namespace App\Services;

use App\Models\ProductStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Shared stock injection logic used by RAGService and AgentLoopService.
 */
class StockInjectionService
{
    public function getStockStatus(): Collection
    {
        return Cache::remember(ProductStock::STOCK_CACHE_KEY, 300, function () {
            return ProductStock::orderBy('display_order')->get();
        });
    }

    public function getOutOfStockProducts(): Collection
    {
        return $this->getStockStatus()->where('in_stock', false);
    }

    public function buildStockInjection(Collection $stocks): string
    {
        if ($stocks->isEmpty()) {
            return '';
        }

        $outOfStock = $stocks->where('in_stock', false);
        $inStock = $stocks->where('in_stock', true);

        $lines = ['⛔⛔⛔ STOCK STATUS (ข้อมูลล่าสุดจากระบบ — ยึดข้อมูลนี้เหนือทุกอย่าง):'];

        if ($outOfStock->isNotEmpty()) {
            $items = $outOfStock->map(function ($p) {
                $aliases = implode(', ', $p->aliases ?? []);

                return $aliases ? "{$p->name} (รวม: {$aliases})" : $p->name;
            })->implode(', ');
            $lines[] = "[สินค้าที่หมดชั่วคราว]: {$items}";
        }

        if ($inStock->isNotEmpty()) {
            $items = $inStock->map(fn ($p) => $p->name)->implode(', ');
            $lines[] = "[สินค้าที่มีพร้อมส่ง]: {$items}";
        }

        $lines[] = 'ห้ามขาย/แนะนำ/คำนวณราคาสินค้าที่หมด stock เด็ดขาด!';

        return implode("\n", $lines);
    }

    public function buildStockReminder(Collection $stocks): string
    {
        $outOfStock = $stocks->where('in_stock', false);

        if ($outOfStock->isEmpty()) {
            return '';
        }

        $names = $outOfStock->map(fn ($p) => $p->name)->implode(', ');

        return "⛔ STOCK REMINDER: สินค้าหมด stock → {$names} — ห้ามขาย/แนะนำเด็ดขาด! ดูข้อมูลจาก STOCK STATUS ด้านบน";
    }

    /**
     * Wrap a prompt with stock header + reminder. Used by test/emulator endpoints.
     */
    public function injectStockStatus(string $prompt): string
    {
        $stocks = $this->getStockStatus();

        $result = '';
        $stockInjection = $this->buildStockInjection($stocks);
        if (! empty($stockInjection)) {
            $result .= $stockInjection."\n---\n\n";
        }

        $result .= $prompt;

        $stockReminder = $this->buildStockReminder($stocks);
        if (! empty($stockReminder)) {
            $result .= "\n\n".$stockReminder;
        }

        return $result;
    }

    public function getProductNamesAndAliases(): array
    {
        $stocks = $this->getStockStatus();

        $terms = [];
        foreach ($stocks as $product) {
            $terms[] = $product->name;
            $terms[] = $product->slug;
            foreach ($product->aliases ?? [] as $alias) {
                $terms[] = $alias;
            }
        }

        return $terms;
    }
}
