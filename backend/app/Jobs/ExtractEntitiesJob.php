<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\EntityExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async job to extract customer entities from conversation messages.
 *
 * Dispatched after bot generates a response. Throttled to run every 5 messages
 * to balance extraction quality vs cost.
 */
class ExtractEntitiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 10;

    /**
     * Message interval for extraction (extract every N messages).
     */
    public const EXTRACT_EVERY_N_MESSAGES = 5;

    public function __construct(
        protected Conversation $conversation
    ) {
        $this->onQueue('low');
    }

    /**
     * Determine if the job should run based on message count throttle.
     */
    public static function shouldExtract(Conversation $conversation): bool
    {
        $messageCount = $conversation->message_count ?? $conversation->messages()->count();

        return $messageCount > 0 && $messageCount % self::EXTRACT_EVERY_N_MESSAGES === 0;
    }

    /**
     * Execute the job.
     */
    public function handle(EntityExtractionService $service): void
    {
        Log::debug('ExtractEntitiesJob: Starting extraction', [
            'conversation_id' => $this->conversation->id,
        ]);

        $result = $service->extractAndSave($this->conversation);

        Log::debug('ExtractEntitiesJob: Completed', [
            'conversation_id' => $this->conversation->id,
            'extracted_count' => count($result['extracted']),
            'saved_count' => $result['saved_count'],
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('ExtractEntitiesJob: Failed', [
            'conversation_id' => $this->conversation->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
