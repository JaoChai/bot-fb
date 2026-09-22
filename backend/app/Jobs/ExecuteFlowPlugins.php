<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\FlowPluginService;
use App\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExecuteFlowPlugins implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 75;

    public function __construct(
        public int $botId,
        public int $conversationId,
        public int $messageId,
        public ?int $flowId = null,
        public array $contextMessageIds = [],
    ) {
        $this->onQueue(QueueRouter::QUEUE_LLM);
    }

    public function handle(FlowPluginService $plugins): void
    {
        $bot = Bot::find($this->botId);
        $conversation = Conversation::find($this->conversationId);
        $message = Message::find($this->messageId);

        if (! $bot || ! $conversation || ! $message
            || (int) $conversation->bot_id !== (int) $bot->id
            || (int) $message->conversation_id !== (int) $conversation->id) {
            Log::warning('ExecuteFlowPlugins skipped: records missing or out of scope', [
                'bot_id' => $this->botId,
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $idempotencyKey = "flow_plugins:message:{$message->id}";
        if (! Cache::add($idempotencyKey, true, 86400)) {
            Log::debug('ExecuteFlowPlugins skipped duplicate message', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        try {
            if ($this->flowId !== null || $this->contextMessageIds !== []) {
                $plugins->executePluginsSnapshot(
                    $bot,
                    $conversation,
                    $message,
                    $this->flowId,
                    $this->contextMessageIds,
                );
            } else {
                $plugins->executePlugins($bot, $conversation, $message);
            }
        } catch (\Throwable $e) {
            Log::warning('ExecuteFlowPlugins failed', [
                'bot_id' => $this->botId,
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
                'exception_type' => $e::class,
                'exception_code' => $e->getCode(),
            ]);
        }
    }
}
