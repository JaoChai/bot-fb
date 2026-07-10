<?php

namespace App\Console\Commands;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\FlowPlugin;
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
            $orphans = $pool->orphanedReservedRows($activeRefs);
            // order_ref ของบอทเบิกภายนอกไม่ใช่ตัวเลข (ใช้ items_reserved ร่วมกัน) — กรองก่อน
            // เข้า whereIn('id', ...) ไม่งั้น Postgres โยน invalid bigint แล้วกลืน alert ทั้งลูป
            $numericRefs = array_values(array_filter(
                array_column($orphans, 'order_ref'),
                fn ($ref) => ctype_digit((string) $ref),
            ));
            $deliveries = AccountDelivery::whereIn('id', $numericRefs)->get()->keyBy('id');
            foreach ($orphans as $row) {
                $delivery = ctype_digit((string) $row['order_ref'])
                    ? $deliveries->get((int) $row['order_ref'])
                    : null;
                // งาน delivered ที่ยังมีของค้าง = ส่งลูกค้าแล้วแต่ markSold ไม่สำเร็จ — ห้ามคืน/ขายซ้ำ
                $problems[] = $delivery?->status === AccountDelivery::STATUS_DELIVERED
                    ? "⚠️ ของจอง #{$row['id']} ({$row['name']}) — ส่งลูกค้าไปแล้ว (งาน #{$delivery->id}) แต่ยังไม่ย้ายเข้า sold: ต้องย้ายเข้า items_sold เอง ห้ามขายซ้ำ"
                    : "ของจองค้าง #{$row['id']} ({$row['name']}) order_ref={$row['order_ref']} ไม่มีงาน active — คืน stock ได้";
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

    /** ส่งเข้า Telegram ผ่าน plugin ของงานล่าสุด — ไม่มีงานเลยก็ fallback ไปหา plugin จาก config('delivery.bot_ids') */
    private function notifyTelegram(TelegramAlertBotService $alertBot, AccountDeliveryService $deliveryService, array $problems): void
    {
        $delivery = AccountDelivery::with('bot', 'conversation')->latest('id')->first();
        $plugin = $delivery ? $deliveryService->telegramPlugin($delivery) : null;
        $plugin ??= $this->fallbackPlugin();
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

    /** ไม่มีงานส่งของเลย → หา plugin จาก bot ที่เปิดใช้ delivery ใน config */
    private function fallbackPlugin(): ?FlowPlugin
    {
        foreach (config('delivery.bot_ids', []) as $botId) {
            $flow = Bot::find($botId)?->defaultFlow;
            $plugin = $flow?->plugins()->where('type', 'telegram')->where('enabled', true)->first();
            if ($plugin) {
                return $plugin;
            }
        }

        return null;
    }
}
