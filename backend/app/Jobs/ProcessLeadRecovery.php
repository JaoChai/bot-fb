<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Services\LeadRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessLeadRecovery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(LeadRecoveryService $service): void
    {
        Log::info('LeadRecovery: Starting hourly lead recovery process');

        // Find all bots with lead recovery enabled
        // Relationship: Bot -> settings (BotSetting) -> hitlSettings (BotHITLSettings)
        $bots = Bot::whereHas('settings.hitlSettings', function ($query) {
            $query->where('lead_recovery_enabled', true);
        })->get();

        Log::info('LeadRecovery: Found bots with lead recovery enabled', [
            'count' => $bots->count(),
        ]);

        $processedCount = 0;
        $errorCount = 0;

        foreach ($bots as $bot) {
            try {
                $conversations = $service->findEligibleConversations($bot);

                Log::debug('LeadRecovery: Found eligible conversations for bot', [
                    'bot_id' => $bot->id,
                    'conversation_count' => $conversations->count(),
                ]);

                foreach ($conversations as $conversation) {
                    try {
                        $service->processRecovery($conversation);
                        $processedCount++;
                    } catch (\Exception $e) {
                        $errorCount++;
                        Log::error('LeadRecovery: Failed to process conversation', [
                            'conversation_id' => $conversation->id,
                            'bot_id' => $bot->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('LeadRecovery: Failed to process bot', [
                    'bot_id' => $bot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('LeadRecovery: Completed hourly lead recovery process', [
            'bots_processed' => $bots->count(),
            'conversations_processed' => $processedCount,
            'errors' => $errorCount,
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('LeadRecovery: Job failed permanently', [
            'error' => $exception->getMessage(),
            ...(! app()->environment('production') ? ['trace' => $exception->getTraceAsString()] : []),
        ]);
    }
}
