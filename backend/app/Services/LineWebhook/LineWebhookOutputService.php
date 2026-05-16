<?php

namespace App\Services\LineWebhook;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Services\FlowPluginService;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\MultipleBubblesService;
use App\Services\PaymentFlexService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LineWebhookOutputService
{
    public function __construct(
        private readonly LINEService $line,
        private readonly LeadRecoveryService $leadRecovery,
        private readonly MultipleBubblesService $bubbles,
        private readonly PaymentFlexService $paymentFlex,
        private readonly FlowPluginService $flowPlugin,
    ) {}

    /**
     * Stage 4: Dispatch output side-effects after Stage 3 response generation.
     *
     * Ports post-AI side-effects from ProcessLINEWebhook::processEvent (lines 468-604),
     * handleStickerReply (894-971), and handleImageAnalysis (1106-1157).
     */
    public function dispatch(WebhookContext $ctx): void
    {
        $conv = $ctx->conversation;
        $userMessage = $ctx->userMessage;

        // --- No-response path ---
        if ($ctx->response === null) {
            if ($conv) {
                $this->leadRecovery->markCustomerResponded($conv);
                $conv->refresh();
                $conversationData = $this->buildConversationData($conv);
            }

            if ($userMessage) {
                broadcast(new MessageSent($userMessage, $conversationData ?? null))->toOthers();
            }
            if ($conv) {
                broadcast(new ConversationUpdated($conv, 'message_received'))->toOthers();
            }

            return;
        }

        // --- Response path: branch by message type ---
        $messageType = $ctx->messageType();

        match ($messageType) {
            'text' => $this->dispatchText($ctx),
            'sticker' => $this->dispatchSticker($ctx),
            'image' => $this->dispatchImage($ctx),
            default => $this->dispatchNoResponse($ctx),
        };
    }

    // -------------------------------------------------------------------------
    // Text path (legacy lines 468-604)
    // -------------------------------------------------------------------------

    private function dispatchText(WebhookContext $ctx): void
    {
        $conv = $ctx->conversation;
        $userMessage = $ctx->userMessage;
        $bot = $ctx->bot;

        if (! $conv || ! $userMessage) {
            return;
        }

        /** @var \App\Models\Message|null $botMessage */
        $botMessage = $ctx->metadata['bot_message'] ?? null;
        if ($botMessage === null) {
            Log::error('Output stage invoked without bot_message in metadata', [
                'bot_id' => $ctx->bot->id,
                'message_type' => $ctx->messageType(),
            ]);

            return;
        }

        try {
            $content = $botMessage->content ?? '';

            if ($content !== '') {
                $transformed = $this->paymentFlex->tryConvertToFlex($content, $conv);

                if (is_array($transformed)) {
                    // Flex detected → send as Flex message
                    $retryKey = $this->line->generateRetryKey();
                    $this->line->replyWithFallback($bot, $ctx->replyToken(), $ctx->userId(), [$transformed], $retryKey);
                } elseif ($this->bubbles->isEnabled($bot)) {
                    // Bubbles enabled → parse + sendBubbles
                    $bubbleList = $this->bubbles->parseIntoBubbles($content, $bot);
                    $this->bubbles->sendBubbles($bot, $ctx->userId(), $ctx->replyToken(), $bubbleList, $conv);
                } else {
                    // Plain text
                    $retryKey = $this->line->generateRetryKey();
                    $this->line->replyWithFallback($bot, $ctx->replyToken(), $ctx->userId(), [$content], $retryKey);
                }
            }

            // Flow plugins (legacy lines 530-541)
            try {
                $this->flowPlugin->executePlugins($bot, $conv, $botMessage);
            } catch (\Exception $e) {
                Log::warning('Flow plugin execution failed in LINE webhook', [
                    'conversation_id' => $conv->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Stats (legacy lines 543-544)
            $isNewConversation = (bool) ($ctx->metadata['is_new_conversation'] ?? false);
            $this->updateStatsInBatch($ctx, $conv, $isNewConversation);
        } catch (\Exception $e) {
            Log::error('Failed to generate/send AI response after transaction', [
                'bot_id' => $bot->id,
                'conversation_id' => $conv->id,
                'error' => $e->getMessage(),
                ...(! app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
            ]);

            throw $e;
        }

        // Broadcasts (legacy lines 581-603)
        $conv->refresh();
        $conversationData = $this->buildConversationData($conv);

        $this->leadRecovery->markCustomerResponded($conv);

        if ($userMessage) {
            broadcast(new MessageSent($userMessage, $conversationData))->toOthers();
        }

        broadcast(new MessageSent($botMessage, $conversationData))->toOthers();
        broadcast(new ConversationUpdated($conv, 'message_received'))->toOthers();
    }

    // -------------------------------------------------------------------------
    // Sticker path (legacy lines 922-956, T8 already saved bot Message)
    // -------------------------------------------------------------------------

    private function dispatchSticker(WebhookContext $ctx): void
    {
        $conv = $ctx->conversation;
        $bot = $ctx->bot;

        if (! $conv) {
            return;
        }

        /** @var \App\Models\Message|null $botMessage */
        $botMessage = $ctx->metadata['bot_message'] ?? null;
        if ($botMessage === null) {
            Log::error('Output stage invoked without bot_message in metadata', [
                'bot_id' => $ctx->bot->id,
                'message_type' => $ctx->messageType(),
            ]);

            return;
        }
        $content = $ctx->response->payload;

        // Push (legacy line 922-924) — note: T8 saved first, push is second (inverted vs legacy)
        $retryKey = $this->line->generateRetryKey();
        $this->line->replyWithFallback($bot, $ctx->replyToken(), $ctx->userId(), [$content], $retryKey);

        // Stats — sticker-specific DB::raw (legacy lines 939-947)
        $conv->update([
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
            'last_message_id' => $botMessage->id,
        ]);
        $bot->update([
            'total_messages' => DB::raw('total_messages + 1'),
            'last_active_at' => now(),
        ]);

        // Broadcast (legacy lines 950-956)
        $conv->refresh();
        broadcast(new MessageSent($botMessage, $this->buildConversationData($conv)))->toOthers();

        // Lead recovery
        $this->leadRecovery->markCustomerResponded($conv);
    }

    // -------------------------------------------------------------------------
    // Image path (legacy lines 1093-1139, T8 already saved bot Message)
    // -------------------------------------------------------------------------

    private function dispatchImage(WebhookContext $ctx): void
    {
        $conv = $ctx->conversation;
        $bot = $ctx->bot;

        if (! $conv) {
            return;
        }

        /** @var \App\Models\Message|null $botMessage */
        $botMessage = $ctx->metadata['bot_message'] ?? null;
        if ($botMessage === null) {
            Log::error('Output stage invoked without bot_message in metadata', [
                'bot_id' => $ctx->bot->id,
                'message_type' => $ctx->messageType(),
            ]);

            return;
        }
        $content = $ctx->response->payload;

        // Stats (legacy lines 1094-1104)
        $conv->update([
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
            'last_message_id' => $botMessage->id,
        ]);
        $bot->update([
            'total_messages' => DB::raw('total_messages + 1'),
            'last_active_at' => now(),
        ]);

        // Push (legacy lines 1106-1116)
        if ($this->bubbles->isEnabled($bot)) {
            $bubbleList = $this->bubbles->parseIntoBubbles($content, $bot);
            $this->bubbles->sendBubbles($bot, $ctx->userId(), $ctx->replyToken(), $bubbleList, $conv);
        } else {
            $transformed = $this->paymentFlex->tryConvertToFlex($content, $conv);
            $retryKey = $this->line->generateRetryKey();
            $this->line->replyWithFallback($bot, $ctx->replyToken(), $ctx->userId(), [$transformed], $retryKey);
        }

        // Broadcasts (legacy lines 1118-1128)
        $conv->refresh();
        $updatedData = $this->buildConversationData($conv);

        broadcast(new MessageSent($botMessage, $updatedData))->toOthers();
        broadcast(new ConversationUpdated($conv, 'message_received'))->toOthers();

        // Flow plugins (legacy lines 1130-1139)
        try {
            $this->flowPlugin->executePlugins($bot, $conv, $botMessage);
        } catch (\Exception $e) {
            Log::warning('Flow plugin execution failed after image analysis', [
                'conversation_id' => $conv->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Lead recovery
        $this->leadRecovery->markCustomerResponded($conv);
    }

    // -------------------------------------------------------------------------
    // No-response with a response object (non-text/sticker/image that has response)
    // -------------------------------------------------------------------------

    private function dispatchNoResponse(WebhookContext $ctx): void
    {
        // Non-text/sticker/image message types with a response envelope should
        // not reach here in the current pipeline, but handle defensively.
        $conv = $ctx->conversation;
        $userMessage = $ctx->userMessage;

        if ($conv) {
            $this->leadRecovery->markCustomerResponded($conv);
            $conv->refresh();
            $conversationData = $this->buildConversationData($conv);
        }

        if ($userMessage) {
            broadcast(new MessageSent($userMessage, $conversationData ?? null))->toOthers();
        }
        if ($conv) {
            broadcast(new ConversationUpdated($conv, 'message_received'))->toOthers();
        }
    }

    // -------------------------------------------------------------------------
    // Stats helpers
    // -------------------------------------------------------------------------

    /**
     * Port of ProcessLINEWebhook::updateStatsInBatch (lines 665-686).
     * Used by text path only (sticker/image have their own DB::raw expressions).
     */
    private function updateStatsInBatch(WebhookContext $ctx, Conversation $conv, bool $isNewConversation): void
    {
        $conv->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 2'),
            'last_message_at' => now(),
        ]);

        $botUpdate = [
            'total_messages' => DB::raw('total_messages + 2'),
            'last_active_at' => now(),
        ];

        if ($isNewConversation) {
            $botUpdate['total_conversations'] = DB::raw('total_conversations + 1');
        }

        $ctx->bot->update($botUpdate);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildConversationData(Conversation $conv): array
    {
        return [
            'id' => $conv->id,
            'message_count' => $conv->message_count,
            'last_message_at' => $conv->last_message_at?->toISOString(),
            'unread_count' => $conv->unread_count,
        ];
    }
}
