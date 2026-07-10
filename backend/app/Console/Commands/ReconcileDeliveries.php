<?php

namespace App\Console\Commands;

use App\Models\AccountDelivery;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\Delivery\StockPoolService;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ตรวจของค้างระหว่าง bot-fb กับ mhha_acc_db แล้วแจ้งเตือน (ไม่แก้เองอัตโนมัติ):
 * 1. งานค้าง 'reserving' > 10 นาที = job ตายกลางคัน
 * 2. งานค้าง 'delivering' > 10 นาที = process ตายระหว่างส่ง — ห้ามคืน stock อัตโนมัติ ให้เจ้าของเช็คแชทก่อน
 * 3. แถว items_reserved ที่ไม่มีงาน active (reserved/delivering) ชี้อยู่ = ของหลุดอยู่ใน limbo
 */
class ReconcileDeliveries extends Command
{
    protected $signature = 'delivery:reconcile';

    protected $description = 'ตรวจงานส่งบัญชี/ของจองที่ค้างผิดปกติ แล้วแจ้ง Telegram';

    public function handle(StockPoolService $pool, TelegramAlertBotService $alertBot, AccountDeliveryService $deliveryService): int
    {
        $problems = [];

        $stuck = AccountDelivery::whereIn('status', [
            AccountDelivery::STATUS_RESERVING,
            AccountDelivery::STATUS_DELIVERING,
        ])
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->get();
        foreach ($stuck as $d) {
            $hint = $d->status === AccountDelivery::STATUS_DELIVERING
                ? 'process อาจตายระหว่างส่ง — เช็คแชทก่อนว่าลูกค้าได้ของหรือยัง ห้ามรีบคืน stock'
                : 'job อาจตายกลางคัน';
            $problems[] = "งาน #{$d->id} ค้างสถานะ {$d->status} ตั้งแต่ {$d->updated_at} ({$hint})";
        }

        $activeRefs = AccountDelivery::whereIn('status', [
            AccountDelivery::STATUS_RESERVING,
            AccountDelivery::STATUS_RESERVED,
            AccountDelivery::STATUS_DELIVERING,
        ])->pluck('id')->map(fn ($id) => (string) $id)->all();

        try {
            foreach ($pool->orphanedReservedRows($activeRefs) as $row) {
                $problems[] = "ของจองค้าง #{$row['id']} ({$row['name']}) order_ref={$row['order_ref']} ไม่มีงาน active";
            }
        } catch (\Throwable $e) {
            Log::error('Reconcile: cannot read items_reserved', ['error' => $e->getMessage()]);
            $problems[] = 'อ่าน items_reserved ไม่ได้ — เช็ค mhha_acc_db';
        }

        if ($problems === []) {
            $this->info('clean');

            return self::SUCCESS;
        }

        $this->alert(implode("\n", $problems));
        $this->notifyTelegram($alertBot, $deliveryService, $problems);

        return self::SUCCESS;
    }

    /** ส่งเข้า Telegram ผ่าน plugin ของงานล่าสุด (ไม่มีก็แค่ log) */
    private function notifyTelegram(TelegramAlertBotService $alertBot, AccountDeliveryService $deliveryService, array $problems): void
    {
        $delivery = AccountDelivery::with('bot', 'conversation')->latest('id')->first();
        $plugin = $delivery ? $deliveryService->telegramPlugin($delivery) : null;
        if (! $plugin) {
            Log::warning('Reconcile: no telegram plugin to notify', ['problems' => $problems]);

            return;
        }

        $alertBot->sendMessage(
            $plugin->config['access_token'] ?? '',
            (string) ($plugin->config['chat_id'] ?? ''),
            "🧯 ตรวจพบของค้างในระบบส่งบัญชี:\n".implode("\n", $problems)."\nรบกวนเช็คใน DB/แจ้งทีม dev",
        );
    }
}
