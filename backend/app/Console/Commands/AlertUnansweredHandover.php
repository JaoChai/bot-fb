<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Payment\TelegramAlertBotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * เตือนแอดมินทาง Telegram เมื่อลูกค้าในโหมด handover รอคำตอบเกินเกณฑ์
 * (เคสจริง: แชท #1653 ลูกค้าพยายามสั่งซื้อ 3+ ชม. ไม่มีใครตอบ)
 *
 * เตือน 1 ครั้ง/ตอนรอ: เก็บ message id ล่าสุดที่เตือนไปแล้วใน cache —
 * ลูกค้าตามข้อความใหม่โดยยังไม่มีใครตอบ = ตอนรอเดิม ไม่เตือนซ้ำ
 * แอดมิน/บอทตอบแล้วลูกค้าเขียนใหม่ = ตอนรอใหม่ เตือนได้อีก
 */
class AlertUnansweredHandover extends Command
{
    /** ค่าที่ใช้เมื่อไม่ได้ตั้ง config handover.alert_after_minutes */
    public const DEFAULT_ALERT_AFTER_MINUTES = 15;

    /** หน้าต่างหางานเก่า — เคสเก่ากว่านี้ไม่เตือนย้อนหลัง กันน้ำท่วมตอน deploy ครั้งแรก */
    public const MAX_WAIT_HOURS = 24;

    /** ตัดข้อความลูกค้าที่แปะในการแจ้งเตือน */
    public const QUOTE_MAX_CHARS = 200;

    /** TTL ของ cache กันเตือนซ้ำ — ยาวกว่าหน้าต่าง 24 ชม.เสมอ */
    private const ALERT_CACHE_TTL_DAYS = 2;

    protected $signature = 'handover:alert-unanswered';

    protected $description = 'แจ้งเตือนแอดมินทาง Telegram เมื่อลูกค้าในโหมด handover รอคำตอบเกินเกณฑ์';

    public function handle(TelegramAlertBotService $telegram): int
    {
        $thresholdMinutes = config_int('handover.alert_after_minutes', self::DEFAULT_ALERT_AFTER_MINUTES);
        $sent = 0;

        $conversations = Conversation::query()
            ->where('is_handover', true)
            // ผูกหน้าต่าง 24 ชม.ผ่าน index messages (conversation_id, created_at) —
            // handover ถาวร เซ็ตนี้โตทุกนาที ห้ามโหลดแชทที่ไม่มีกิจกรรมในหน้าต่าง
            ->whereHas('messages', fn ($q) => $q->where('created_at', '>=', now()->subHours(self::MAX_WAIT_HOURS)))
            ->with('currentFlow.plugins', 'bot.defaultFlow.plugins', 'customerProfile')
            ->get();

        // ข้อความล่าสุดของทุกแชทในหน้าต่างได้จาก 2 คิวรี่ (กัน N+1 ล่าสุด-ต่อ-แชท)
        $latestMessages = Message::query()
            ->whereIn('conversation_id', $conversations->modelKeys())
            ->whereIn('id', Message::query()
                ->selectRaw('max(id)')
                ->whereIn('conversation_id', $conversations->modelKeys())
                ->groupBy('conversation_id'))
            ->get()
            ->keyBy('conversation_id');

        foreach ($conversations as $conversation) {
            $lastMessage = $latestMessages->get($conversation->id);

            // มีคนตอบแล้ว (แอดมินหรือบอทเป็นข้อความล่าสุด) หรือแชทยังไม่มีข้อความ
            if (! $lastMessage || $lastMessage->sender !== 'user') {
                continue;
            }

            // ยังไม่ถึงเกณฑ์ หรือเก่าเกินหน้าต่าง 24 ชม.
            $waited = $lastMessage->created_at;
            if ($waited->gt(now()->subMinutes($thresholdMinutes))
                || $waited->lt(now()->subHours(self::MAX_WAIT_HOURS))) {
                continue;
            }

            $cacheKey = "handover_alert:{$conversation->id}";
            $alertedMessageId = Cache::get($cacheKey);

            if ($alertedMessageId === $lastMessage->id) {
                continue; // เตือนข้อความนี้ไปแล้ว
            }

            // เคยเตือนไปแล้วและยังไม่มีใครตอบหลังจากนั้น = ตอนรอเดิม ลูกค้าตามข้อความเพิ่มเท่านั้น
            if ($alertedMessageId !== null
                && ! $conversation->messages()
                    ->whereIn('sender', ['agent', 'bot'])
                    ->where('id', '>', $alertedMessageId)
                    ->exists()) {
                continue;
            }

            $alerted = $this->sendAlert($telegram, $conversation, $lastMessage);
            if (! $alerted) {
                continue; // ส่งไม่ออก = ไม่ประทับ cache ยอมลองรอบหน้าดีกว่าเสียสิทธิ์เตือนฟรีๆ
            }

            Cache::put($cacheKey, $lastMessage->id, now()->addDays(self::ALERT_CACHE_TTL_DAYS));
            $sent++;
        }

        $this->info("handover alerts sent: {$sent}");

        return self::SUCCESS;
    }

    private function sendAlert(
        TelegramAlertBotService $telegram,
        Conversation $conversation,
        Message $lastMessage,
    ): bool {
        $flow = $conversation->currentFlow ?? $conversation->bot?->defaultFlow;
        $plugin = $flow?->plugins
            ->first(fn ($p) => $p->type === 'telegram' && $p->enabled === true);

        if (! $plugin) {
            Log::info('Handover alert: no enabled telegram plugin, skipping', ['bot_id' => $conversation->bot_id]);

            return false;
        }

        $token = $plugin->config['access_token'] ?? '';
        $chatId = $plugin->config['chat_id'] ?? '';
        if (empty($token) || empty($chatId)) {
            Log::warning('Handover alert: telegram plugin missing config', ['plugin_id' => $plugin->id]);

            return false;
        }

        $customerName = $conversation->customerProfile?->display_name
            ?? $conversation->external_customer_id
            ?? "แชท #{$conversation->id}";
        $quote = mb_substr((string) $lastMessage->content, 0, self::QUOTE_MAX_CHARS);
        $minutes = (int) $lastMessage->created_at->diffInMinutes(now());

        $lines = [
            "⏰ <b>ลูกค้ารอแอดมินตอบ {$minutes} นาที</b>",
            '👤 '.TelegramAlertBotService::esc($customerName)." · แชท #{$conversation->id}",
            '💬 '.TelegramAlertBotService::esc($quote),
        ];

        $result = $telegram->sendMessage($token, $chatId, implode("\n", $lines));
        if ($result === null) {
            Log::error('Handover alert: never reached Telegram', [
                'conversation_id' => $conversation->id,
                'waited_minutes' => $minutes,
            ]);

            return false;
        }

        return true;
    }
}
