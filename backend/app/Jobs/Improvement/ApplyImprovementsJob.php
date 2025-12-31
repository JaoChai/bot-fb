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

class ApplyImprovementsJob implements ShouldQueue
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
        Log::info("Starting apply improvements job", [
            'session_id' => $this->session->id,
            'selected_count' => $this->session->getSelectedSuggestionsCount(),
        ]);

        try {
            $apiKey = $this->getUserApiKey();
            $agentService->applySuggestions($this->session, $apiKey);

            Log::info("Apply improvements job completed", [
                'session_id' => $this->session->id,
                're_evaluation_id' => $this->session->re_evaluation_id,
            ]);
        } catch (\Exception $e) {
            Log::error("Apply improvements job failed", [
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

        $settings = $user->settings ?? [];
        return $settings['openrouter_api_key'] ?? null;
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("Apply improvements job failed permanently", [
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
            'apply-improvements',
            "improvement:{$this->session->id}",
            "bot:{$this->session->bot_id}",
        ];
    }
}
