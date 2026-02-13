<?php

namespace App\Jobs\Evaluation;

use App\Models\Evaluation;
use App\Models\User;
use App\Services\Evaluation\EvaluationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunEvaluationJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 120, 300]; // 1min, 2min, 5min
    public int $timeout = 1800; // 30 minutes max

    public function __construct(
        public Evaluation $evaluation,
        public ?int $userId = null
    ) {}

    public function handle(EvaluationService $evaluationService): void
    {
        Log::info('Starting evaluation job', [
            'evaluation_id' => $this->evaluation->id,
            'bot_id' => $this->evaluation->bot_id,
            'flow_id' => $this->evaluation->flow_id,
        ]);

        try {
            // Get user's API key
            $apiKey = $this->getUserApiKey();

            // Run the complete evaluation pipeline
            $evaluationService->runEvaluation($this->evaluation, $apiKey);

            Log::info('Evaluation completed successfully', [
                'evaluation_id' => $this->evaluation->id,
                'overall_score' => $this->evaluation->fresh()->overall_score,
            ]);

        } catch (Throwable $e) {
            Log::error('Evaluation job failed', [
                'evaluation_id' => $this->evaluation->id,
                'error' => $e->getMessage(),
                ...(!app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
            ]);

            // Mark as failed (will be handled by failed() method if all retries exhausted)
            throw $e;
        }
    }

    /**
     * Get user's API key from settings
     * Priority: User Settings > ENV
     */
    protected function getUserApiKey(): ?string
    {
        // Try user's settings first (using safe getter to handle decryption errors)
        if ($this->userId) {
            $user = User::find($this->userId);
            $apiKey = $user?->settings?->getOpenRouterApiKey();
            if (!empty($apiKey)) {
                return $apiKey;
            }
        }

        // Fall back to env config
        return config('services.openrouter.api_key');
    }

    /**
     * Handle a job failure
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Evaluation job failed permanently', [
            'evaluation_id' => $this->evaluation->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->evaluation->markAsFailed(
            $exception?->getMessage() ?? 'Unknown error'
        );
    }

    /**
     * Determine the middleware the job should pass through
     */
    public function middleware(): array
    {
        return [];
    }

    /**
     * Get the tags that should be assigned to the job
     */
    public function tags(): array
    {
        return [
            'evaluation',
            'evaluation:' . $this->evaluation->id,
            'bot:' . $this->evaluation->bot_id,
        ];
    }
}
