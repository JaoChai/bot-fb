<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Payment\SlipRetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * ตรวจสลิปซ้ำหลัง SLIP_PENDING (delayed). tries=1 — จัดการ retry เองผ่าน re-dispatch
 * ใน SlipRetryService ตามตาราง delay จึงไม่พึ่ง framework retry
 */
class RetrySlipVerification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $botId,
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly string $imageUrl,
        public readonly int $attempt,
    ) {}

    public function handle(SlipRetryService $service): void
    {
        $bot = Bot::find($this->botId);
        $conversation = Conversation::find($this->conversationId);
        $message = Message::find($this->messageId);
        if (! $bot || ! $conversation || ! $message) {
            return;
        }

        $service->retry($bot, $conversation, $message, $this->imageUrl, $this->attempt);
    }
}
