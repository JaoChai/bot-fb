<?php

namespace App\Services\Webhook\Channels\LINE;

use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Services\LINEService;
use App\Services\StickerReplyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LINE sticker-reply handler — verbatim move of the sticker method that
 * lived in App\Jobs\ProcessLINEWebhook (Task 4, 2026-08-26).
 *
 * Moved byte-for-byte (only `$this->x` → injected dependency / `$this->bot`):
 *   - handleStickerReply()      → reply()
 *
 * app(StickerReplyService::class) became constructor-injected — same binding
 * resolved at the call site.
 */
class StickerHandler
{
    public function __construct(
        private readonly Bot $bot,
        private readonly StickerReplyService $stickerReplyService,
    ) {}

    /**
     * reply() — the public entry point (mirrors handleStickerReply).
     *
     * Signature is identical to the old internal call. No logic was edited —
     * the body is a byte-for-byte move of handleStickerReply (see class
     * docblock for the $this->x substitutions).
     */
    public function reply(
        LINEService $lineService,
        Conversation $conversation,
        array $messageData,
        string $userId,
        string $replyToken,
        ?array $conversationData
    ): void {
        $settings = $this->bot->settings;
        if (! $settings?->reply_sticker_enabled) {
            return;
        }

        $mode = $settings->reply_sticker_mode ?? 'static';

        // Show loading indicator for AI mode
        if ($mode === 'ai') {
            $lineService->showLoadingIndicator($this->bot, $userId, 15);
        }

        try {
            $responseMessage = $this->stickerReplyService->generateReply($this->bot, $conversation, $messageData);

            if (! $responseMessage) {
                return;
            }

            // Send reply with fallback to push if token expired
            $retryKey = $lineService->generateRetryKey();
            $lineService->replyWithFallback($this->bot, $replyToken, $userId, [$responseMessage], $retryKey);

            // Save bot response
            $botMessage = $conversation->messages()->create([
                'sender' => 'bot',
                'content' => $responseMessage,
                'type' => 'text',
                'metadata' => [
                    'sticker_reply' => true,
                    'sticker_mode' => $mode,
                    'sticker_id' => $messageData['sticker_id'] ?? null,
                ],
            ]);

            // Update stats
            $conversation->update([
                'message_count' => DB::raw('message_count + 1'),
                'last_message_at' => now(),
                'last_message_id' => $botMessage->id,
            ]);
            $this->bot->update([
                'total_messages' => DB::raw('total_messages + 1'),
                'last_active_at' => now(),
            ]);

            // Broadcast
            $conversation->refresh();
            broadcast(new MessageSent($botMessage, [
                'id' => $conversation->id,
                'message_count' => $conversation->message_count,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'unread_count' => $conversation->unread_count,
            ]))->toOthers();

            Log::info('Replied to sticker', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $conversation->id,
                'mode' => $mode,
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to reply to sticker', [
                'bot_id' => $this->bot->id,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
