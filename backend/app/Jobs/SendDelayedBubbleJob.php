<?php

namespace App\Jobs;

use App\Models\Bot;
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
     * Create a new job instance.
     *
     * @param  Bot  $bot  The bot sending the message
     * @param  string  $userId  LINE user ID to receive the message
     * @param  string  $bubbleContent  The text content of the bubble
     * @param  int  $bubbleIndex  Index of this bubble (1-indexed, for logging)
     * @param  int  $totalBubbles  Total bubbles being sent (for logging)
     */
    public function __construct(
        public Bot $bot,
        public string $userId,
        public string $bubbleContent,
        public int $bubbleIndex,
        public int $totalBubbles
    ) {}

    /**
     * Execute the job.
     */
    public function handle(LINEService $lineService): void
    {
        try {
            $lineService->push($this->bot, $this->userId, [$this->bubbleContent]);

            Log::debug('Delayed bubble sent successfully', [
                'bot_id' => $this->bot->id,
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
