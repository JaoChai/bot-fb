<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Exceptions\OpenRouterException;
use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AIService;
use App\Services\Chat\ConversationContextService;
use App\Services\CommerceSafety\CartValidation;
use App\Services\CommerceSafety\CheckoutAuthority;
use App\Services\CommerceSafety\CheckoutOutcome;
use App\Services\CommerceSafety\CheckoutRenderer;
use App\Services\CommerceSafety\CustomerReplyGuard;
use App\Services\CommerceSafety\FinancialOutputDetector;
use App\Services\CommerceSafety\FinancialOutputGuard;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\FlowPluginService;
use App\Services\LINEService;
use App\Services\MessageAggregationService;
use App\Services\MultipleBubblesService;
use App\Services\PaymentFlexService;
use App\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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
     * The number of seconds the job can run before timing out.
     * Set to 200s to cover reasoning effort=high (primary 90s + fallback + intent).
     * Requires REDIS_QUEUE_RETRY_AFTER >= 210 in production (prod uses the Redis queue).
     */
    public int $timeout = 200;

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
                ...(! app()->environment('production') ? ['trace' => $e->getTraceAsString()] : []),
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

        // Validate and get content (with early exit checks)
        $validationResult = $this->validateAndGetContent($aggregationService);
        if ($validationResult === null) {
            return;
        }

        ['mergedContent' => $mergedContent, 'messageCount' => $messageCount, 'cachedMessageIds' => $cachedMessageIds] = $validationResult;

        // Check if bot is active and not in handover mode
        if (! $this->shouldGenerate()) {
            return;
        }

        // Safety check: skip if bot already responded
        if ($this->hasAlreadyResponded($conversationId, $cachedMessageIds)) {
            $aggregationService->clearAggregation($conversationId);

            return;
        }

        // Acquire lock and attempt to generate response
        $responseLock = $this->acquireResponseLock($aggregationService);
        if ($responseLock === null) {
            return;
        }

        try {
            $checkoutProcessing = $this->consumeCheckoutMessages(
                $cachedMessageIds,
                $lineService,
                $bubblesService,
            );
            $checkoutResponses = $checkoutProcessing['responses'];
            $remainingMessageIds = $checkoutProcessing['remaining_message_ids'];
            foreach ($checkoutResponses as $checkoutResponse) {
                $this->updateStats($messageCount, $checkoutResponse->id);
            }

            if ($remainingMessageIds === []) {
                $aggregationService->clearAggregation($conversationId);
                foreach ($checkoutResponses as $checkoutResponse) {
                    $this->broadcastResponse($checkoutResponse);
                }

                return;
            }

            $mergedContent = $this->mergedContentFor($remainingMessageIds);

            // Auto-clear stale context before AI generates response
            app(ConversationContextService::class)->autoClearIfIdle($this->conversation);

            // Generate and deliver bot response
            $botMessage = $this->generateAndDeliver(
                $mergedContent,
                $messageCount,
                $aiService,
                $lineService,
                $bubblesService,
                $remainingMessageIds
            );

            if ($botMessage) {
                $this->updateStats($messageCount, $botMessage->id);
            }
        } finally {
            $responseLock->release();
        }

        // Clear aggregation data after successful processing
        $aggregationService->clearAggregation($conversationId);

        // Broadcast events after processing
        if (isset($botMessage) && $botMessage) {
            foreach ($checkoutResponses ?? [] as $checkoutResponse) {
                $this->broadcastResponse($checkoutResponse);
            }
            $this->broadcastResponse($botMessage);
        }
    }

    /**
     * Validate and retrieve merged content from cached messages.
     * Returns array with mergedContent, messageCount, cachedMessageIds or null if validation fails.
     */
    private function validateAndGetContent(MessageAggregationService $aggregationService): ?array
    {
        $conversationId = $this->conversation->id;

        // Get all cache values at job start
        $cachedGroupId = $aggregationService->getCurrentGroupId($conversationId);
        $cachedMessageIds = $aggregationService->getMessageIds($conversationId);
        $startedAt = $aggregationService->getStartedAt($conversationId);

        Log::debug('[Aggregation] Job started', [
            'conversation_id' => $conversationId,
            'job_group_id' => $this->groupId,
            'cached_group_id' => $cachedGroupId,
            'group_id_match' => $cachedGroupId === $this->groupId,
            'cached_message_ids' => $cachedMessageIds,
            'message_count' => count($cachedMessageIds),
            'started_at' => $startedAt,
            'bot_id' => $this->bot->id,
        ]);

        // Verify this group is still active (no newer messages came in)
        if (! $aggregationService->isActiveGroup($conversationId, $this->groupId)) {
            $reason = $cachedGroupId === null ? 'cache_expired_or_missing' : 'newer_group_exists';
            Log::debug('[Aggregation] Early exit: group_id mismatch', [
                'reason' => $reason,
                'job_group_id' => $this->groupId,
                'cached_group_id' => $cachedGroupId,
                'conversation_id' => $conversationId,
            ]);

            return null;
        }

        $messagesById = Message::query()
            ->whereIn('id', $cachedMessageIds)
            ->get(['id', 'conversation_id', 'sender', 'content'])
            ->keyBy('id');
        $messages = collect($cachedMessageIds)
            ->map(fn ($messageId) => $messagesById->get($messageId))
            ->filter();
        if (count($cachedMessageIds) !== count(array_unique($cachedMessageIds))
            || $messages->count() !== count($cachedMessageIds)
            || $messages->contains(fn (Message $message): bool => (int) $message->conversation_id !== (int) $conversationId
                || $message->sender !== 'user')) {
            Log::warning('[Aggregation] Early exit: message identity or scope mismatch', [
                'conversation_id' => $conversationId,
                'message_ids' => $cachedMessageIds,
            ]);
            $aggregationService->clearAggregation($conversationId);

            return null;
        }

        // Build from the verified constituent rows, preserving their exact order.
        $mergedContent = $messages->pluck('content')->filter()->implode("\n");

        if (empty($mergedContent)) {
            $reason = empty($cachedMessageIds) ? 'message_ids_empty' : 'messages_not_found_in_db';
            Log::debug('[Aggregation] Early exit: no content', [
                'reason' => $reason,
                'message_ids' => $cachedMessageIds,
                'conversation_id' => $conversationId,
            ]);
            $aggregationService->clearAggregation($conversationId);

            return null;
        }

        return [
            'mergedContent' => $mergedContent,
            'messageCount' => count($cachedMessageIds),
            'cachedMessageIds' => $cachedMessageIds,
        ];
    }

    /**
     * Check if bot is active and conversation is not in handover mode.
     */
    private function shouldGenerate(): bool
    {
        // Read-only refresh of latest bot/conversation state — no writes or locks,
        // so no transaction needed (dropping it avoids per-job BEGIN/COMMIT round-trips).
        $this->conversation->refresh();
        $this->bot->refresh();

        // Check if bot was deactivated while waiting
        if ($this->bot->status !== 'active') {
            Log::debug('[Aggregation] Early exit: bot inactive', [
                'bot_id' => $this->bot->id,
                'status' => $this->bot->status,
                'conversation_id' => $this->conversation->id,
            ]);

            return false;
        }

        // Check if handover mode was enabled while waiting
        if ($this->conversation->is_handover) {
            Log::debug('[Aggregation] Early exit: handover mode enabled', [
                'conversation_id' => $this->conversation->id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Check if bot already responded after the latest message in this group.
     */
    private function hasAlreadyResponded(int $conversationId, array $cachedMessageIds): bool
    {
        if (empty($cachedMessageIds)) {
            return false;
        }

        $latestMessageId = max($cachedMessageIds);
        $latestTimestamp = Message::where('id', $latestMessageId)->value('created_at');

        if (! $latestTimestamp) {
            return false;
        }

        $alreadyResponded = Message::where('conversation_id', $conversationId)
            ->where('sender', 'bot')
            ->where('created_at', '>=', $latestTimestamp)
            ->exists();

        if ($alreadyResponded) {
            Log::info('Safety net: bot already responded after latest message, skipping aggregation response', [
                'conversation_id' => $conversationId,
                'group_id' => $this->groupId,
                'latest_message_id' => $latestMessageId,
                'skipped_message_ids' => $cachedMessageIds,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Acquire per-conversation response lock to prevent concurrent AI responses.
     * Returns lock on success, null if lock could not be acquired or max retries exceeded.
     */
    private function acquireResponseLock(MessageAggregationService $aggregationService): ?Lock
    {
        $conversationId = $this->conversation->id;
        $responseLock = Cache::lock("ai_response:{$conversationId}", 30);

        if (! $responseLock->get()) {
            // Limit re-dispatch attempts to prevent infinite loop
            $redispatchKey = "ai_response_redispatch:{$conversationId}:{$this->groupId}";
            $attempts = (int) Cache::get($redispatchKey, 0);

            if ($attempts >= 3) {
                Log::warning('Aggregation: max re-dispatch attempts reached', [
                    'conversation_id' => $conversationId,
                    'group_id' => $this->groupId,
                    'attempts' => $attempts,
                ]);
                Cache::forget($redispatchKey);
                $aggregationService->clearAggregation($conversationId);

                return null;
            }

            Cache::put($redispatchKey, $attempts + 1, now()->addMinutes(5));

            Log::info('Aggregation: response lock held, re-dispatching', [
                'conversation_id' => $conversationId,
                'attempt' => $attempts + 1,
            ]);
            // Re-dispatch with shorter delay
            ProcessAggregatedMessages::dispatch(
                $this->bot, $this->conversation, $this->groupId, $this->externalUserId
            )->onConnection(QueueRouter::connection())->onQueue(QueueRouter::llmQueue())->delay(now()->addSeconds(5));

            return null;
        }

        // Clean up re-dispatch counter on successful lock acquisition
        Cache::forget("ai_response_redispatch:{$conversationId}:{$this->groupId}");

        return $responseLock;
    }

    /**
     * Generate AI response and deliver to channel.
     */
    private function generateAndDeliver(
        string $mergedContent,
        int $messageCount,
        AIService $aiService,
        LINEService $lineService,
        MultipleBubblesService $bubblesService,
        array $currentTurnMessageIds = []
    ): ?Message {
        Log::debug('[Aggregation] Generating AI response', [
            'conversation_id' => $this->conversation->id,
            'content_length' => strlen($mergedContent),
        ]);

        // Generate AI response using merged content
        // exclude ข้อความของ turn นี้ออกจาก history — mergedContent เป็นตัวแทน turn ปัจจุบันอยู่แล้ว
        try {
            $result = $aiService->generateResponse(
                $this->bot,
                $mergedContent,
                $this->conversation,
                excludeMessageIds: $currentTurnMessageIds
            );
        } catch (OpenRouterException $e) {
            // Unlike the synchronous webhook path, this job calls generateResponse() (which
            // does not catch) with tries=1 — so a failure here would send the customer NOTHING.
            // Send the same friendly Thai fallback instead of silence.
            Log::error('[Aggregation] AI generation failed — sending friendly fallback', [
                'conversation_id' => $this->conversation->id,
                'error' => $e->getMessage(),
            ]);

            $botMessage = $this->conversation->messages()->create([
                'sender' => 'bot',
                'content' => $aiService->getErrorMessage($e),
                'type' => 'text',
            ]);
            $this->deliverToChannel($botMessage, $lineService, $bubblesService);

            return $botMessage;
        }

        $result = app(FinancialOutputGuard::class)->generated($this->bot, $result);
        $result = app(CustomerReplyGuard::class)->generated($this->bot, $result, $this->conversation);
        $checkoutOutcome = $this->checkoutProposal($result);
        if ($checkoutOutcome !== null) {
            $result['content'] = $checkoutOutcome->customerText
                ?? app(CheckoutRenderer::class)->render($checkoutOutcome->checkout, $checkoutOutcome->action);
            $result['order_payload'] = $checkoutOutcome->action === 'payment' && $checkoutOutcome->checkout
                ? $this->serverOrderPayload($checkoutOutcome->checkout)
                : null;
            $result['checkout_presentation'] = $checkoutOutcome->checkout ? [
                'checkout_id' => $checkoutOutcome->checkout->getKey(),
                'revision' => $checkoutOutcome->checkout->revision,
                'action' => $checkoutOutcome->action,
            ] : null;
        }

        // Save bot response
        $botMessage = $this->conversation->messages()->create([
            'sender' => 'bot',
            'content' => $result['content'],
            'type' => 'text',
            'model_used' => $result['model'],
            'prompt_tokens' => $result['usage']['prompt_tokens'],
            'completion_tokens' => $result['usage']['completion_tokens'],
            'cost' => $result['cost'],
            'metadata' => array_filter([
                ...($result['rag_metadata'] ?? []),
                'order_payload' => $result['order_payload'] ?? null,
                // เก็บไว้ตรวจย้อนหลังว่า guard ไปแก้คำตอบอะไรของบอทบ้าง
                'stock_guard' => $result['stock_guard'] ?? null,
                'checkout_presentation' => $result['checkout_presentation'] ?? null,
            ]) ?: null,
        ]);

        if ($checkoutOutcome?->checkout) {
            app(CheckoutAuthority::class)->pending(
                $checkoutOutcome->checkout,
                $checkoutOutcome->checkout->revision,
                $botMessage,
                $checkoutOutcome->action,
            );
        }

        // Send response to channel
        if ($botMessage->content) {
            $delivered = $this->deliverToChannel($botMessage, $lineService, $bubblesService);
            if ($delivered && $checkoutOutcome?->checkout) {
                app(CheckoutAuthority::class)->presented(
                    $checkoutOutcome->checkout,
                    $checkoutOutcome->checkout->revision,
                    $botMessage,
                );
            }
        }

        // Execute flow plugins (e.g., Telegram notifications)
        if ($botMessage) {
            try {
                app(FlowPluginService::class)
                    ->executePlugins($this->bot, $this->conversation, $botMessage);
            } catch (\Exception $e) {
                Log::warning('Flow plugin execution failed in aggregation', [
                    'conversation_id' => $this->conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $botMessage;
    }

    /** @return array{responses:list<Message>,remaining_message_ids:list<int>} */
    private function consumeCheckoutMessages(
        array $messageIds,
        LINEService $lineService,
        MultipleBubblesService $bubblesService,
    ): array {
        if (app(SafetyScope::class)->mode($this->bot) !== 'enforce') {
            return ['responses' => [], 'remaining_message_ids' => array_values($messageIds)];
        }

        $messagesById = Message::query()
            ->whereIn('id', $messageIds)
            ->where('conversation_id', $this->conversation->getKey())
            ->where('sender', 'user')
            ->get()
            ->keyBy('id');
        $messages = collect($messageIds)
            ->map(fn ($messageId) => $messagesById->get($messageId))
            ->filter();
        $authority = app(CheckoutAuthority::class);
        $responses = [];
        $remainingMessageIds = [];
        foreach ($messages as $message) {
            // Once ordinary input is reached, preserve the entire suffix for AI/B1.
            if ($remainingMessageIds !== [] || ! $authority->isStateOnlyReply($message)) {
                $remainingMessageIds[] = (int) $message->getKey();

                continue;
            }
            $outcome = $authority->accept($this->bot, $this->conversation, $message);
            if (! $this->outcomeConsumedMessage($outcome, $message)) {
                $remainingMessageIds[] = (int) $message->getKey();

                continue;
            }

            if (! $outcome->checkout) {
                $remainingMessageIds[] = (int) $message->getKey();

                continue;
            }

            $content = $outcome->customerText
                ?? app(CheckoutRenderer::class)->render($outcome->checkout, $outcome->action);
            $presentation = in_array($outcome->action, ['ack', 'confirm', 'support_delay', 'terms', 'payment'], true)
                && $outcome->checkout->state !== 'cancelled' ? [
                    'checkout_id' => $outcome->checkout->getKey(),
                    'revision' => $outcome->checkout->revision,
                    'action' => $outcome->action,
                ] : null;
            $botMessage = $this->conversation->messages()->create([
                'sender' => 'bot',
                'content' => $content,
                'type' => 'text',
                'metadata' => array_filter([
                    'order_payload' => $outcome->action === 'payment'
                        ? $this->serverOrderPayload($outcome->checkout)
                        : null,
                    'checkout_presentation' => $presentation,
                ]) ?: null,
            ]);
            if ($presentation !== null) {
                $authority->pending($outcome->checkout, $outcome->checkout->revision, $botMessage, $outcome->action);
            }
            $delivered = $this->deliverToChannel($botMessage, $lineService, $bubblesService);
            if ($delivered && $presentation !== null) {
                $authority->presented($outcome->checkout, $outcome->checkout->revision, $botMessage);
            }
            $responses[] = $botMessage;
        }

        return [
            'responses' => $responses,
            'remaining_message_ids' => $remainingMessageIds,
        ];
    }

    /** @param list<int> $messageIds */
    private function mergedContentFor(array $messageIds): string
    {
        $messagesById = Message::query()
            ->whereIn('id', $messageIds)
            ->where('conversation_id', $this->conversation->getKey())
            ->where('sender', 'user')
            ->get(['id', 'content'])
            ->keyBy('id');

        return collect($messageIds)
            ->map(fn ($messageId) => $messagesById->get($messageId)?->content)
            ->filter()
            ->implode("\n");
    }

    private function checkoutProposal(array $result): ?CheckoutOutcome
    {
        if (! in_array(app(SafetyScope::class)->mode($this->bot), ['enforce', 'hold'], true)) {
            return null;
        }

        if (app(FinancialOutputDetector::class)->detects($this->bot, (string) ($result['content'] ?? ''))) {
            return new CheckoutOutcome('manual_hold', null, FinancialOutputGuard::DENIAL);
        }
        $cart = $result['commerce_safety_cart_validation'] ?? null;
        if (! $cart instanceof CartValidation) {
            return null;
        }
        if (! $cart->valid) {
            return new CheckoutOutcome(
                'manual_hold',
                null,
                'ระบบตรวจสอบรายการนี้ไม่ได้อย่างชัดเจนครับ กรุณาระบุชื่อสินค้า จำนวน และวิธีรับสินค้าใหม่อีกครั้ง',
            );
        }

        return app(CheckoutAuthority::class)->propose($this->bot, $this->conversation, $cart);
    }

    private function outcomeConsumedMessage(CheckoutOutcome $outcome, Message $message): bool
    {
        return $outcome->customerText !== null
            || ($outcome->checkout !== null && in_array(
                (int) $message->getKey(),
                array_map('intval', array_values($outcome->checkout->accepted ?? [])),
                true,
            ));
    }

    private function serverOrderPayload(CheckoutSession $checkout): array
    {
        return [
            'items' => array_map(fn (array $item): array => [
                'name' => $item['name'].match ($item['method'] ?? 'none') {
                    'card' => ' (ผูกบัตร)',
                    'topup' => ' (เติมเงิน)',
                    default => '',
                },
                'qty' => $item['qty'],
                'price' => $item['price_minor'] % 100 === 0
                    ? intdiv($item['price_minor'], 100)
                    : $item['price_minor'] / 100,
            ], $checkout->items),
            'total' => $checkout->total_minor % 100 === 0
                ? intdiv($checkout->total_minor, 100)
                : $checkout->total_minor / 100,
        ];
    }

    /**
     * Deliver bot message to the appropriate channel (Flex, Bubbles, or plain text).
     */
    private function deliverToChannel(
        Message $botMessage,
        LINEService $lineService,
        MultipleBubblesService $bubblesService
    ): bool {
        $paymentFlex = app(PaymentFlexService::class);
        $guard = app(FinancialOutputGuard::class);
        $guard->message($this->bot, $this->conversation, $botMessage);
        app(CustomerReplyGuard::class)->message($this->bot, $this->conversation, $botMessage);
        $transformed = $guard->enforced($this->bot)
            ? $paymentFlex->tryConvertToFlex($botMessage->content, $this->conversation, $botMessage)
            : $paymentFlex->tryConvertToFlex($botMessage->content, $this->conversation);

        $plainText = $guard->enforced($this->bot) ? $transformed : $botMessage->content;

        if (is_array($transformed)) {
            // Flex detected on full text → send as single message
            $retryKey = $lineService->generateRetryKey();

            return $lineService->push($this->bot, $this->externalUserId, [$transformed], $retryKey) === true;
        } elseif ($bubblesService->isEnabled($this->bot)) {
            // No Flex match → normal bubble flow
            $bubbles = $bubblesService->parseIntoBubbles($plainText, $this->bot);

            return $bubblesService->sendBubbles(
                $this->bot,
                $this->externalUserId,
                null,
                $bubbles,
                $this->conversation,
            ) === true;
        } else {
            // No Flex, no bubbles → send as plain text
            $retryKey = $lineService->generateRetryKey();

            return $lineService->push(
                $this->bot,
                $this->externalUserId,
                [$plainText],
                $retryKey,
            ) === true;
        }
    }

    /**
     * Broadcast response events to connected clients.
     */
    private function broadcastResponse(Message $botMessage): void
    {
        // Refresh conversation to get actual DB values after DB::raw updates
        $this->conversation->refresh();
        $conversationData = [
            'id' => $this->conversation->id,
            'message_count' => $this->conversation->message_count,
            'last_message_at' => $this->conversation->last_message_at?->toISOString(),
            'unread_count' => $this->conversation->unread_count,
        ];
        broadcast(new MessageSent($botMessage, $conversationData))->toOthers();
        broadcast(new ConversationUpdated($this->conversation, 'message_received'))->toOthers();
    }

    /**
     * Update conversation and bot statistics.
     */
    protected function updateStats(int $aggregatedMessageCount, int $lastMessageId): void
    {
        // Update conversation stats
        // unread_count: +1 for bot response
        // message_count: already incremented for user messages, +1 for bot
        $this->conversation->update([
            'unread_count' => DB::raw('unread_count + 1'),
            'message_count' => DB::raw('message_count + 1'),
            'last_message_at' => now(),
            'last_message_id' => $lastMessageId,
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
