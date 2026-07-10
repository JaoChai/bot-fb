<?php

namespace App\Jobs;

use App\Services\Delivery\StockPoolService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * ตามเก็บย้ายของเข้า items_sold เมื่อ markSold พังหลัง push ให้ลูกค้าสำเร็จ
 * (ลูกค้าได้ credential แล้ว แต่แถวยังค้าง items_reserved) — idempotent:
 * moveReservedRows ย้ายเฉพาะแถวที่ยังอยู่ items_reserved ถ้าถูกย้ายไปแล้วก็ no-op
 */
class MarkStockSold implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 1800];

    /**
     * @param  array<int, int>  $stockItemIds
     */
    public function __construct(
        public readonly array $stockItemIds,
        public readonly string $confirmedByName,
        public readonly string $username = 'bot-fb',
    ) {}

    public function handle(StockPoolService $pool): void
    {
        $pool->markSold($this->stockItemIds, $this->confirmedByName, $this->username);
    }

    /** retry หมดแล้วยังพัง — ของยังค้าง items_reserved (delivery:reconcile จะจับเป็น "ห้ามขายซ้ำ") */
    public function failed(\Throwable $e): void
    {
        Log::error('MarkStockSold: exhausted retries — stock ค้าง items_reserved', [
            'stock_item_ids' => $this->stockItemIds, 'error' => $e->getMessage(),
        ]);
    }
}
