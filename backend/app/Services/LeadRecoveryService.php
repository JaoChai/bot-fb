<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\LeadRecoveryLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class LeadRecoveryService
{
    /**
     * How long after a sent follow-up a customer reply still counts (B1).
     */
    protected const RESPONSE_WINDOW_HOURS = 72;

    public function __construct(
        protected LINEService $lineService,
        protected TelegramService $telegramService,
        protected FacebookService $facebookService,
        protected ResponseHoursService $responseHoursService,
        protected OpenRouterService $openRouterService
    ) {}

    /**
     * Find conversations that need recovery for a specific bot.
     *
     * Retired in B1: the old autonomous follow-up never picks rooms anymore.
     * The new eligibility query lands with B2 (follow_up_sales_signals).
     *
     * @return Collection<int, Conversation>
     */
    public function findEligibleConversations(Bot $bot): Collection
    {
        return new Collection;
    }

    /**
     * Process recovery for a conversation.
     *
     * Retired in B1: a hard no-op. No model read, no channel send, no log row,
     * no counter change — even when the legacy lead_recovery_enabled toggle is
     * still on. Kept only so existing callers resolve to a clean false.
     */
    public function processRecovery(Conversation $conversation): bool
    {
        return false;
    }

    /**
     * Send a static follow-up message via the appropriate channel.
     *
     * @return bool Success status
     */
    public function sendStaticFollowUp(Conversation $conversation, string $message, bool $plainText = false): bool
    {
        $bot = $conversation->bot;
        $channelType = $conversation->channel_type;
        $externalCustomerId = $conversation->external_customer_id;

        if (empty($externalCustomerId)) {
            Log::warning('Lead recovery: no external customer ID', [
                'conversation_id' => $conversation->id,
            ]);

            return false;
        }

        try {
            return match ($channelType) {
                'line' => $this->sendViaLINE($bot, $externalCustomerId, $message),
                'telegram' => $this->sendViaTelegram($bot, $externalCustomerId, $message, $plainText),
                'facebook' => $this->sendViaFacebook($bot, $externalCustomerId, $message),
                default => $this->handleUnsupportedChannel($channelType, $conversation->id),
            };
        } catch (\Exception $e) {
            Log::error('Lead recovery send failed', [
                'conversation_id' => $conversation->id,
                'channel_type' => $channelType,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send message via LINE channel.
     */
    protected function sendViaLINE(Bot $bot, string $userId, string $message): bool
    {
        // Use retry key for idempotency (LINE best practice)
        $retryKey = $this->lineService->generateRetryKey();
        $this->lineService->push($bot, $userId, [$message], $retryKey);

        return true;
    }

    /**
     * Send message via Telegram channel.
     *
     * $plainText (F18): ข้อความที่เจ้าของตั้งเองได้ (ป้าย/แจ้งหลังบอทล่ม) ต้องไป
     * ตรงตัวทุกตัวอักษร จึงตัด parse_mode ออก — lead recovery ปกติคง parse_mode=HTML
     */
    protected function sendViaTelegram(Bot $bot, string $chatId, string $message, bool $plainText = false): bool
    {
        $this->telegramService->sendMessage($bot, $chatId, $message, $plainText ? ['parse_mode' => null] : []);

        return true;
    }

    /**
     * Send message via Facebook channel.
     */
    protected function sendViaFacebook(Bot $bot, string $recipientId, string $message): bool
    {
        $this->facebookService->sendMessage($bot, $recipientId, $message);

        return true;
    }

    /**
     * Handle unsupported channel type.
     */
    protected function handleUnsupportedChannel(string $channelType, int $conversationId): bool
    {
        Log::warning('Lead recovery: unsupported channel type', [
            'conversation_id' => $conversationId,
            'channel_type' => $channelType,
        ]);

        return false;
    }

    /**
     * Mark that a customer has responded to a lead recovery attempt.
     *
     * B1 rule: only the newest log row that was actually SENT, at most 72
     * hours old and not yet marked may flip to responded — and only when a
     * real customer message arrived after that row was sent. Failed and
     * unsent rows never count, bot output never counts, and a second call
     * finds nothing left to mark (idempotent).
     */
    public function markCustomerResponded(Conversation $conversation): void
    {
        $log = LeadRecoveryLog::query()
            ->where('conversation_id', $conversation->id)
            ->where('customer_responded', false)
            ->where('delivery_status', 'sent')
            ->where('sent_at', '>=', now()->subHours(self::RESPONSE_WINDOW_HOURS))
            ->orderByDesc('sent_at')
            ->first();

        if ($log === null) {
            return;
        }

        // Only an actual customer message newer than the follow-up counts
        // as the reply; the row carries that real message's timestamp.
        $reply = $conversation->messages()
            ->where('sender', 'user')
            ->where('created_at', '>', $log->sent_at)
            ->orderBy('created_at')
            ->first();

        if ($reply === null) {
            return;
        }

        $log->update([
            'customer_responded' => true,
            'responded_at' => $reply->created_at,
        ]);

        Log::info('Lead recovery: customer responded', [
            'conversation_id' => $conversation->id,
            'log_id' => $log->id,
            'attempt_number' => $log->attempt_number,
        ]);
    }

    /**
     * Get recent conversation context for AI message generation.
     *
     * @param  int  $limit  Number of recent messages to retrieve
     * @return array Array of messages with role (customer/assistant) and content
     */
    public function getConversationContext(Conversation $conversation, int $limit = 5): array
    {
        try {
            // Get last N messages ordered from oldest to newest
            $messages = $conversation->messages()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->filter(fn ($message) => $message->content !== null && $message->content !== '') // drop suppressed handover rows (empty content) — Lead widening 2026-11-12
                ->reverse()
                ->values();

            $context = [];
            foreach ($messages as $message) {
                // Map sender to role (user -> customer, bot/agent -> assistant)
                $role = $message->sender === 'user' ? 'customer' : 'assistant';

                $context[] = [
                    'role' => $role,
                    'content' => $message->content ?? '',
                ];
            }

            Log::debug('Lead recovery: retrieved conversation context', [
                'conversation_id' => $conversation->id,
                'message_count' => count($context),
            ]);

            return $context;
        } catch (\Exception $e) {
            Log::error('Lead recovery: failed to get conversation context', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
