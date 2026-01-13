<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Message;
use App\Services\QAInspector\QAInspectorService;
use App\Services\QAInspector\RealtimeEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateConversationJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $timeout = 60;

    public function __construct(
        public Message $message,
        public Bot $bot,
    ) {
        $this->onQueue('qa-evaluation');
    }

    public function handle(
        QAInspectorService $qaInspectorService,
        RealtimeEvaluator $realtimeEvaluator,
    ): void {
        // Check if QA Inspector is still enabled
        if (!$qaInspectorService->isEnabled($this->bot)) {
            Log::info('QA Inspector: Skipped evaluation - disabled', [
                'bot_id' => $this->bot->id,
            ]);
            return;
        }

        // Check sampling rate
        if (!$qaInspectorService->shouldEvaluate($this->bot)) {
            Log::debug('QA Inspector: Skipped evaluation - sampling', [
                'bot_id' => $this->bot->id,
                'sampling_rate' => $this->bot->qa_sampling_rate,
            ]);
            return;
        }

        // Run evaluation
        $result = $realtimeEvaluator->evaluate($this->message, $this->bot);

        if ($result) {
            Log::info('QA Inspector: Evaluation completed', [
                'bot_id' => $this->bot->id,
                'evaluation_id' => $result->id,
                'overall_score' => $result->overall_score,
                'is_flagged' => $result->is_flagged,
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('QA Inspector: Job failed', [
            'message_id' => $this->message->id,
            'bot_id' => $this->bot->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
