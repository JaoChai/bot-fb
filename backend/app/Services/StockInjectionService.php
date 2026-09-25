<?php

namespace App\Services;

use App\Models\ProductStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Shared stock injection logic used by RAGService.
 */
class StockInjectionService
{
    /** เหลือ ≤ ค่านี้ = ใกล้หมด บอกจำนวนลูกค้าได้ (เร่งปิดการขาย) / เกินกว่านี้ = ห้ามเปิดเผยตัวเลข */
    public const DISCLOSE_QTY_THRESHOLD = 10;

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

    /** มีสินค้า in-stock ที่ต้องคุมโควตาจำนวนไหม — ใช้โดย RAGService ตัดสินใจฉีด stock block */
    public function hasQtyToEnforce(Collection $stocks): bool
    {
        return $this->inStockWithQty($stocks)->isNotEmpty();
    }

    /** สินค้า in-stock ที่มีจำนวนคงเหลือจาก pool (available_count null = ไม่ใช่สินค้า stock pool) */
    private function inStockWithQty(Collection $stocks): Collection
    {
        return $stocks->where('in_stock', true)->filter(fn ($p) => $p->available_count !== null);
    }

    /** ป้ายกำกับให้ LLM ไม่ต้องเทียบตัวเลขเอง */
    private function qtyLabel(int $count): string
    {
        return $count <= self::DISCLOSE_QTY_THRESHOLD ? '(ใกล้หมด — บอกจำนวนได้)' : '(ของเยอะ — ห้ามบอกตัวเลข)';
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
            $lines[] = '[สินค้าที่มีพร้อมส่ง]: '.$inStock->pluck('name')->implode(', ');
        }

        $withQty = $this->inStockWithQty($stocks);
        if ($withQty->isNotEmpty()) {
            $lines[] = '[จำนวนพร้อมส่ง]: '
                .$withQty->map(fn ($p) => "{$p->name} = {$p->available_count} ชิ้น {$this->qtyLabel($p->available_count)}")->implode(', ');
            $lines[] = 'ห้ามรับออเดอร์/เพิ่มตะกร้า/สรุปยอดเกินจำนวนพร้อมส่งเด็ดขาด!';
            $lines[] = '- ลูกค้าถามจำนวน/มีกี่ตัว → ทำตามป้าย: "ห้ามบอกตัวเลข" = ตอบว่ามีพร้อมส่งเยอะ ห้ามบอกเลข / "บอกจำนวนได้" = บอกจำนวนที่เหลือได้';
            $lines[] = '- ลูกค้าสั่งไม่เกินจำนวนพร้อมส่ง → ขายปกติ ไม่ต้องพูดถึงจำนวนคงเหลือเอง';
            $lines[] = '- ลูกค้าสั่งเกิน → เสนอขายเท่าที่มี บอกจำนวนที่พร้อมส่งตอนนี้'
                .' และแจ้งว่าส่วนที่เหลือของเข้าแล้วจะรีบแจ้ง (สรุปยอด/ราคาตามจำนวนที่ขายจริงเท่านั้น)';
        }

        $lines[] = 'ห้ามขาย/เพิ่มตะกร้า/สร้างออเดอร์สินค้าที่หมด stock เด็ดขาด! (ตอบราคาและรายละเอียดได้ถ้าลูกค้าถาม แต่ต้องแจ้งว่าหมดชั่วคราว)';

        // ไม่ใส่เหตุผลที่หมด/สินค้าทดแทน — สคริปต์การพูดเป็นของ prompt ที่เจ้าของร้านคุมเอง
        // (เหตุผลที่เคยฉีดไว้ตั้งแต่ เม.ย. 2026 ขัดกับ prompt ที่แก้ 21 ส.ค. ซึ่งสั่งห้ามพูด)
        return implode("\n", $lines);
    }

    public function buildStockReminder(Collection $stocks): string
    {
        $parts = [];

        $outOfStock = $stocks->where('in_stock', false);
        if ($outOfStock->isNotEmpty()) {
            $names = $outOfStock->pluck('name')->implode(', ');

            $parts[] = "⛔ STOCK REMINDER: สินค้าหมด stock → {$names} — ห้ามขาย/เพิ่มตะกร้า/สร้างออเดอร์เด็ดขาด! ตอบราคา/รายละเอียดได้ถ้าลูกค้าถาม + ต้องแจ้งว่าหมดชั่วคราว";
        }

        // double-injection กติกาจำนวน — LLM มักลืมกติกาที่อยู่ต้น prompt
        $withQty = $this->inStockWithQty($stocks);
        if ($withQty->isNotEmpty()) {
            $qtyList = $withQty->map(fn ($p) => "{$p->name} = {$p->available_count} {$this->qtyLabel($p->available_count)}")->implode(', ');
            $parts[] = "⛔ QTY REMINDER: จำนวนพร้อมส่ง → {$qtyList} — ห้ามรับออเดอร์เกินจำนวนนี้!"
                .' ลูกค้าสั่งเกิน → เสนอขายเท่าที่มี + แจ้งว่าส่วนที่เหลือของเข้าแล้วจะรีบแจ้ง'
                .' (ลูกค้าถามจำนวน → ทำตามป้าย / สั่งไม่เกิน → ขายปกติ ไม่พูดถึงจำนวนเอง)';
        }

        return implode("\n", $parts);
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
