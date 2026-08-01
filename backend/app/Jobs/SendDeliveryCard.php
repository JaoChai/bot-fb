<?php

namespace App\Jobs;

use App\Models\AccountDelivery;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * ส่งการ์ดปุ่มส่งของเข้า Telegram — แยกจาก ReserveAccountStock โดยตั้งใจ
 *
 * การจองสต๊อกต้องทำครั้งเดียว (tries=1) แต่การ์ดต้องส่งจนกว่าจะสำเร็จ
 * เหตุการณ์ 1 ส.ค. 2026: สองอย่างนี้เคยอยู่ใน job เดียวกัน พอ api.telegram.org
 * ค้าง ~30 วิ การ์ดหายแล้วยิงซ้ำไม่ได้เลยเพราะ retry จะจองสต๊อกซ้ำ
 * ออเดอร์เลยค้างเงียบจนเจ้าของต้องเบิกบัญชีส่งเอง
 *
 * backoff กระจายยาวกว่าหน้าต่างที่ Telegram เคยค้าง (30 วิ) โดยตั้งใจ
 */
#[Backoff([10, 30, 60, 300])]
class SendDeliveryCard implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $deliveryId,
        public readonly string $prefix = '',
    ) {}

    public function handle(AccountDeliveryService $service): void
    {
        $delivery = AccountDelivery::with('items')->find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        if (! $service->sendCard($delivery, $this->prefix)) {
            // โยนเพื่อให้ queue retry ตาม backoff — ทางเดียวที่การ์ดจะได้ไปต่อ
            throw new \RuntimeException("delivery card send failed (delivery {$this->deliveryId})");
        }
    }

    /**
     * ยิงครบทุกรอบแล้วการ์ดยังไม่ออก — ใช้ Log::error เพราะ production
     * กรอง log ที่ระดับ error ข้อความระดับ warning จะไม่ถูกบันทึกที่ไหนเลย
     */
    public function failed(\Throwable $e): void
    {
        Log::error('Delivery: card never reached Telegram', [
            'delivery_id' => $this->deliveryId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * dispatch แบบไม่ให้พังลาม flow จองสต๊อก — บน queue แบบ sync (เช่นในเทสต์)
     * exception จาก handle() จะเด้งกลับมาหาผู้ dispatch ทันที
     */
    public static function dispatchSafely(int $deliveryId, string $prefix = ''): void
    {
        try {
            self::dispatch($deliveryId, $prefix);
        } catch (\Throwable $e) {
            Log::error('Delivery: card dispatch failed', [
                'delivery_id' => $deliveryId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
