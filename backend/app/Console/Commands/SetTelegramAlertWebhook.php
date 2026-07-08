<?php

namespace App\Console\Commands;

use App\Models\FlowPlugin;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Console\Command;

class SetTelegramAlertWebhook extends Command
{
    protected $signature = 'telegram:alert-webhook';

    protected $description = 'ตั้ง Telegram webhook ให้ bot แจ้งเตือนสลิป (รับปุ่มยืนยันรับเงิน)';

    public function handle(TelegramAlertBotService $alertBot): int
    {
        $secret = (string) config('services.telegram_alert.secret');
        if ($secret === '') {
            $this->error('ยังไม่ได้ตั้ง TELEGRAM_ALERT_WEBHOOK_SECRET');

            return self::FAILURE;
        }

        $plugins = FlowPlugin::where('type', 'telegram')->where('enabled', true)->get();
        $seen = [];
        foreach ($plugins as $plugin) {
            $token = $plugin->config['access_token'] ?? '';
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;

            $url = rtrim((string) config('app.url'), '/').'/api/webhook/telegram-alert/'.$token;
            $ok = $alertBot->setWebhook($token, $url, $secret);
            $this->line(($ok ? '✅' : '❌').' plugin #'.$plugin->id.' token '.substr($token, 0, 8).'…');
        }

        return self::SUCCESS;
    }
}
