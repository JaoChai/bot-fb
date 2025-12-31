<?php

namespace App\Jobs\Improvement;

use App\Models\ImprovementSession;
use App\Models\User;
use App\Services\Improvement\ImprovementAgentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunImprovementAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600; // 10 minutes
    public array $backoff = [60, 120];

    public function __construct(
        public ImprovementSession $session,
        public ?int $userId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ImprovementAgentService $agentService): void
    {
        Log::info("Starting improvement agent job", [
            'session_id' => $this->session->id,
            'user_id' => $this->userId,
        ]);

        try {
            $apiKey = $this->getUserApiKey();
            $agentService->analyzeEvaluation($this->session, $apiKey);

            Log::info("Improvement agent job completed", [
                'session_id' => $this->session->id,
                'suggestions_count' => $this->session->suggestions()->count(),
            ]);
        } catch (\Exception $e) {
            Log::error("Improvement agent job failed", [
                'session_id' => $this->session->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get API key from user settings
     */
    protected function getUserApiKey(): ?string
    {
        if (!$this->userId) {
            return null;
        }

        $user = User::find($this->userId);
        if (!$user) {
            return null;
        }

        // Check user settings for API key
        $settings = $user->settings ?? [];
        return $settings['openrouter_api_key'] ?? null;
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("Improvement agent job failed permanently", [
            'session_id' => $this->session->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->session->markAsFailed($exception?->getMessage() ?? 'Unknown error');
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'improvement',
            "improvement:{$this->session->id}",
            "bot:{$this->session->bot_id}",
        ];
    }
}
