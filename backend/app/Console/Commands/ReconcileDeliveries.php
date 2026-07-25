<?php

namespace App\Console\Commands;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\FlowPlugin;
use App\Models\SlipVerification;
use App\Models\UserSetting;
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
 * 4. สลิปที่ผ่านแล้วแต่ไม่มีงานส่งของผูกอยู่เลย = เงินเข้าแต่ของไม่ออก
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
        ])->pluck('id')->map(fn ($id) => StockPoolService::orderRef($id))->all();

        try {
            // orphanedReservedRows คืนเฉพาะแถวของ bot-fb (prefix bfb:) แล้ว — order_ref แปลงเป็น delivery id ได้เสมอ
            $orphans = $pool->orphanedReservedRows($activeRefs);
            $refIds = array_values(array_filter(array_map(
                fn ($row) => StockPoolService::deliveryIdFromRef($row['order_ref']),
                $orphans,
            ), fn ($id) => $id !== null));
            $deliveries = AccountDelivery::whereIn('id', $refIds)->get()->keyBy('id');
            foreach ($orphans as $row) {
                $deliveryId = StockPoolService::deliveryIdFromRef($row['order_ref']);
                $delivery = $deliveryId !== null ? $deliveries->get($deliveryId) : null;
                // งาน delivered ที่ยังมีของค้าง = ส่งลูกค้าแล้วแต่ markSold ไม่สำเร็จ — ห้ามคืน/ขายซ้ำ
                $problems[] = $delivery?->status === AccountDelivery::STATUS_DELIVERED
                    ? "⚠️ ของจอง #{$row['id']} ({$row['name']}) — ส่งลูกค้าไปแล้ว (งาน #{$delivery->id}) แต่ยังไม่ย้ายเข้า sold: ต้องย้ายเข้า items_sold เอง ห้ามขายซ้ำ"
                    : "ของจองค้าง #{$row['id']} ({$row['name']}) order_ref={$row['order_ref']} ไม่มีงาน active — คืน stock ได้";
            }
        } catch (\Throwable $e) {
            Log::error('Reconcile: cannot read items_reserved', ['error' => $e->getMessage()]);
            $problems[] = 'อ่าน items_reserved ไม่ได้ — เช็ค mhha_acc_db';
        }

        $problems = array_merge($problems, $this->paidSlipsWithoutDelivery());

        if ($problems === []) {
            $this->info('clean');

            return self::SUCCESS;
        }

        $this->alert(implode("\n", $problems));
        $this->notifyTelegram($alertBot, $deliveryService, $problems);

        return self::SUCCESS;
    }

    /**
     * เงินเข้าแล้วแต่ไม่มีงานส่งของผูกอยู่เลย — ตาข่ายสุดท้ายกันออเดอร์หายเงียบ:
     * เช็คอื่นในคำสั่งนี้เริ่มจากแถวงานส่งของ จึงมองไม่เห็นเคสที่งานไม่เคยถูกสร้าง
     * (กันซ้ำบล็อก / สวิตช์ปิด / ยิงการ์ดไม่ผ่าน) ซึ่งเงียบจนกว่าลูกค้าจะทวง
     *
     * grace 10 นาที เผื่อ job หน่วงการ์ด + เวลาจอง; มองย้อนแค่ 3 ชั่วโมงเพื่อไม่ให้
     * สลิปเก่าค้างเตือนทุกชั่วโมงตลอดไป (คำสั่งนี้รัน hourly → เตือนราว 3 รอบแล้วหยุด)
     *
     * @return array<int, string>
     */
    private function paidSlipsWithoutDelivery(): array
    {
        $autoDeliveryBotIds = Bot::where('auto_delivery_enabled', true)->pluck('id');
        if ($autoDeliveryBotIds->isEmpty()) {
            return [];
        }

        $handledSlipIds = AccountDelivery::whereNotNull('slip_verification_id')
            ->where('created_at', '>=', now()->subHours(4))
            ->pluck('slip_verification_id');

        return SlipVerification::whereIn('bot_id', $autoDeliveryBotIds)
            ->whereIn('status', ['passed', 'manual_confirmed'])
            ->whereBetween('created_at', [now()->subHours(3), now()->subMinutes(10)])
            ->whereNotIn('id', $handledSlipIds)
            ->get()
            ->map(fn (SlipVerification $slip) => "⚠️ สลิป #{$slip->id} (แชท #{$slip->conversation_id}, "
                .number_format((float) $slip->amount).' บาท) เงินเข้าแล้วแต่ยังไม่มีงานส่งของ'
                .' — เช็คว่าลูกค้าได้ของหรือยัง')
            ->all();
    }

    /** ส่งเข้า Telegram ผ่าน plugin ของงานล่าสุด — ไม่มีงานเลยก็ fallback ไปหา plugin จากบอทที่เปิด auto_delivery_enabled */
    private function notifyTelegram(TelegramAlertBotService $alertBot, AccountDeliveryService $deliveryService, array $problems): void
    {
        $delivery = AccountDelivery::with('bot', 'conversation')->latest('id')->first();
        $plugin = $delivery ? $deliveryService->telegramPlugin($delivery) : null;
        $plugin ??= $this->fallbackPlugin();
        if (! $plugin) {
            Log::warning('Reconcile: no telegram plugin to notify', ['problems' => $problems]);

            return;
        }

        if (UserSetting::quietNow($plugin->flow?->bot?->user?->settings)) {
            Log::info('Reconcile: quiet hours, skipped telegram alert', ['problems' => count($problems)]);

            return;
        }

        $escaped = array_map(fn ($p) => TelegramAlertBotService::esc($p), $problems);
        $alertBot->sendMessage(
            $plugin->config['access_token'] ?? '',
            (string) ($plugin->config['chat_id'] ?? ''),
            "🧯 <b>ตรวจพบของค้างในระบบส่งบัญชี</b>\n<blockquote>".implode("\n", $escaped)."</blockquote>\nรบกวนเช็คใน DB/แจ้งทีม dev",
        );
    }

    /** ไม่มีงานส่งของเลย → หา plugin จาก bot ที่เปิดสวิตช์ส่งของ auto */
    private function fallbackPlugin(): ?FlowPlugin
    {
        foreach (Bot::where('auto_delivery_enabled', true)->get() as $bot) {
            $flow = $bot->defaultFlow;
            $plugin = $flow?->plugins()->where('type', 'telegram')->where('enabled', true)->first();
            if ($plugin) {
                return $plugin;
            }
        }

        return null;
    }
}
