<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\CommerceSafety\FinancialOutputGuard;
use App\Services\LINEService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to send a delayed bubble message to LINE user.
 * Used for async multiple bubbles delivery without blocking the main thread.
 */
class SendDelayedBubbleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Low retries - partial message delivery is acceptable vs spam.
     */
    public int $tries = 2;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 2;

    /**
     * Persisted conversation identity for execution-time scope validation.
     * Nullable for delayed jobs serialized before this field existed.
     */
    public ?int $conversationId = null;

    /**
     * Create a new job instance.
     *
     * @param  Bot  $bot  The bot sending the message
     * @param  string  $userId  LINE user ID to receive the message
     * @param  string  $bubbleContent  The text content of the bubble
     * @param  int  $bubbleIndex  Index of this bubble (1-indexed, for logging)
     * @param  int  $totalBubbles  Total bubbles being sent (for logging)
     * @param  int|null  $conversationId  Conversation that originated the bubble
     */
    public function __construct(
        public Bot $bot,
        public string $userId,
        public string $bubbleContent,
        public int $bubbleIndex,
        public int $totalBubbles,
        ?int $conversationId = null,
    ) {
        $this->conversationId = $conversationId;
    }

    /**
     * Execute the job.
     */
    public function handle(LINEService $lineService): void
    {
        try {
            $bot = $this->currentBot();
            $conversation = $this->currentConversation($bot);
            $content = app(FinancialOutputGuard::class)->text($bot, $this->bubbleContent);

            // Use retry key for idempotency (LINE best practice)
            $retryKey = $lineService->generateRetryKey();
            $lineService->push($bot, $this->userId, [$content], $retryKey);

            Log::debug('Delayed bubble sent successfully', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation?->id,
                'user_id' => $this->userId,
                'bubble_index' => $this->bubbleIndex,
                'total_bubbles' => $this->totalBubbles,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send delayed bubble', [
                'bot_id' => $this->bot->id,
                'user_id' => $this->userId,
                'bubble_index' => $this->bubbleIndex,
                'total_bubbles' => $this->totalBubbles,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Re-throw for retry logic
        }
    }

    private function currentBot(): Bot
    {
        // Unsaved models are supported by the direct unit-test contract only.
        // Persisted queue payloads must always reload current database state.
        return $this->bot->exists
            ? Bot::query()->findOrFail($this->bot->getKey())
            : $this->bot;
    }

    private function currentConversation(Bot $bot): ?Conversation
    {
        if (! $bot->exists) {
            return null;
        }

        return Conversation::query()
            ->where('bot_id', $bot->getKey())
            ->where('external_customer_id', $this->userId)
            ->where('channel_type', 'line')
            ->when(
                $this->conversationId !== null,
                fn ($query) => $query->whereKey($this->conversationId),
            )
            ->latest('id')
            ->first();
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendDelayedBubbleJob failed permanently', [
            'bot_id' => $this->bot->id,
            'user_id' => $this->userId,
            'bubble_index' => $this->bubbleIndex,
            'total_bubbles' => $this->totalBubbles,
            'error' => $exception->getMessage(),
        ]);
    }
}
