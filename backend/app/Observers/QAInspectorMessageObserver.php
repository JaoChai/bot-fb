<?php

namespace App\Observers;

use App\Jobs\EvaluateConversationJob;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

class QAInspectorMessageObserver
{
    public function created(Message $message): void
    {
        // Only evaluate bot responses (sender = 'bot')
        if ($message->sender !== 'bot') {
            return;
        }

        // Get the bot from conversation
        $conversation = $message->conversation;
        if (!$conversation) {
            return;
        }

        $bot = $conversation->bot;
        if (!$bot) {
            return;
        }

        // Check if QA Inspector is enabled
        if (!$bot->qa_inspector_enabled) {
            return;
        }

        // Dispatch evaluation job with small delay
        EvaluateConversationJob::dispatch($message, $bot)
            ->delay(now()->addSeconds(2));

        Log::debug('QA Inspector: Evaluation job dispatched', [
            'message_id' => $message->id,
            'bot_id' => $bot->id,
        ]);
    }
}
