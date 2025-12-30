<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Services\AIService;
use App\Services\LINEService;
use App\Services\MessageAggregationService;
use App\Services\MultipleBubblesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAggregatedMessages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Set to 1 - if it fails, user can send another message.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Bot $bot,
        public Conversation $conversation,
        public string $groupId,
        public string $externalUserId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        MessageAggregationService $aggregationService,
        AIService $aiService,
        LINEService $lineService,
        MultipleBubblesService $bubblesService
    ): void {
        try {
            $this->processAggregatedMessages(
                $aggregationService,
                $aiService,
                $lineService,
                $bubblesService
            );
        } catch (\Exception $e) {
            Log::error('Failed to process aggregated messages', [
                'bot_id' => $this->bot->id,
                'conversation_id' => $this->conversation->id,
                'group_id' => $this->groupId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clear aggregation on failure so user can try again
            $aggregationService->clearAggregation($this->conversation->id);

            throw $e;
        }
    }

    /**
     * Process the aggregated messages.
     */
    protected function processAggregatedMessages(
        MessageAggregationService $aggregationService,
        AIService $aiService,
        LINEService $lineService,
        MultipleBubblesService $bubblesService
    ): void {
        $conversationId = $this->conversation->id;

        // Verify this group is still active (no newer messages came in)
        if (!$aggregationService->isActiveGroup($conversationId, $this->groupId)) {
            Log::debug('Aggregation group superseded by newer group', [
                'conversation_id' => $conversationId,
                'old_group_id' => $this->groupId,
            ]);
            return;
        }

        // Get merged content from all messages
        $mergedContent = $aggregationService->getMergedContent($conversationId);

        if (empty($mergedContent)) {
            Log::warning('No content found in aggregation group', [
                'conversation_id' => $conversationId,
                'group_id' => $this->groupId,
            ]);
            $aggregationService->clearAggregation($conversationId);
            return;
        }

        // Count messages for stats
        $messageIds = $aggregationService->getMessageIds($conversationId);
        $messageCount = count($messageIds);

        Log::info('Processing aggregated messages', [
            'conversation_id' => $conversationId,
            'group_id' => $this->groupId,
            'message_count' => $messageCount,
            'merged_length' => strlen($mergedContent),
        ]);

        $botMessage = null;

        // Generate AI response and send to LINE
        DB::transaction(function () use (
            $aiService,
            $lineService,
            $bubblesService,
            $mergedContent,
            $messageCount,
            &$botMessage
        ) {
            // Refresh conversation to get latest state
            $this->conversation->refresh();

            // Check if handover mode was enabled while waiting
            if ($this->conversation->is_handover) {
                Log::info('Conversation entered handover mode during aggregation wait', [
                    'conversation_id' => $this->conversation->id,
                ]);
                return;
            }

            // Generate AI response using merged content
            $result = $aiService->generateResponse(
                $this->bot,
                $mergedContent,
                $this->conversation
            );

            // Save bot response
            $botMessage = $this->conversation->messages()->create([
                'sender' => 'bot',
                'content' => $result['content'],
                'type' => 'text',
                'model_used' => $result['model'],
                'prompt_tokens' => $result['usage']['prompt_tokens'],
                'completion_tokens' => $result['usage']['completion_tokens'],
                'cost' => $result['cost'],
                'metadata' => $result['rag_metadata'] ?? null,
            ]);

            // Send response to LINE using push message
            // (reply token has likely expired after waiting)
            if ($botMessage->content) {
                if ($bubblesService->isEnabled($this->bot)) {
                    // Parse and send multiple bubbles
                    $bubbles = $bubblesService->parseIntoBubbles($botMessage->content, $this->bot);
                    // Use null for replyToken to force push message
                    $bubblesService->sendBubbles($this->bot, $this->externalUserId, null, $bubbles);
                } else {
                    // Single message via push
                    $lineService->push($this->bot, $this->externalUserId, [$botMessage->content]);
                }
            }

            // Update stats (only +1 for bot message, user messages already counted)
            $this->updateStats($messageCount);
        });

        // Clear aggregation data after successful processing
        $aggregationService->clearAggregation($conversationId);

        // Broadcast events after transaction
        if ($botMessage) {
            broadcast(new MessageSent($botMessage))->toOthers();
            broadcast(new ConversationUpdated($this->conversation->fresh(), 'message_received'))->toOthers();
        }
    }

    /**
     * Update conversation and bot statistics.
     */
    protected function updateStats(int $aggregatedMessageCount): void
    {
        // Update conversation stats
        // unread_count: +1 for bot response
        // message_count: already incremented for user messages, +1 for bot
        $this->conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
        ]);

        // Update bot stats
        // total_messages: +1 for bot response only (user messages already counted)
        $this->bot->update([
            'total_messages' => DB::raw('total_messages + 1'),
            'last_active_at' => now(),
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessAggregatedMessages job failed', [
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'group_id' => $this->groupId,
            'error' => $exception->getMessage(),
        ]);
    }
}
