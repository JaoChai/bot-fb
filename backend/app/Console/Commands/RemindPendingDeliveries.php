<?php

namespace App\Console\Commands;

use App\Models\AccountDelivery;
use App\Models\UserSetting;
use App\Services\Delivery\AccountDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * เตือนงานส่งของที่เจ้าของยังไม่กดยืนยันใน Telegram (นโยบาย: ไม่คืนของอัตโนมัติ เตือนจนกว่าจะกด)
 */
class RemindPendingDeliveries extends Command
{
    protected $signature = 'delivery:remind';

    protected $description = 'เตือนงานส่งบัญชีที่ค้างกดยืนยันใน Telegram';

    public function handle(AccountDeliveryService $service): int
    {
        $threshold = now()->subMinutes(config_int('delivery.remind_after_minutes', 30));

        $pending = AccountDelivery::with('items', 'bot.user.settings', 'conversation')
            ->where('status', AccountDelivery::STATUS_RESERVED)
            ->where('created_at', '<=', $threshold)
            ->where(fn ($q) => $q->whereNull('last_reminded_at')
                ->orWhere('last_reminded_at', '<=', $threshold))
            ->get();

        $skipped = 0;
        foreach ($pending as $delivery) {
            if (UserSetting::quietNow($delivery->bot?->user?->settings)) {
                $skipped++;

                continue;
            }

            $ageMinutes = (int) $delivery->created_at->diffInMinutes(now());
            $service->sendCard($delivery, "⏰ <b>เตือน:</b> งานส่งของค้างมา <code>{$ageMinutes}</code> นาทีแล้ว ยังไม่ได้กดส่ง\n\n");
            $delivery->update(['last_reminded_at' => now()]);
        }

        if ($skipped > 0) {
            Log::info("Delivery remind: quiet hours, skipped {$skipped}");
        }

        $this->info('reminded: '.($pending->count() - $skipped).", quiet-skipped: {$skipped}");

        return self::SUCCESS;
    }
}
