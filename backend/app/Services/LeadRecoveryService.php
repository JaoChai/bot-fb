<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\LeadRecoveryLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class LeadRecoveryService
{
    protected const DEFAULT_TIMEOUT_HOURS = 24;
    protected const DEFAULT_MAX_ATTEMPTS = 3;
    protected const DEFAULT_MESSAGE = 'สวัสดีค่ะ ไม่ทราบว่ายังสนใจอยู่ไหมคะ? หากมีข้อสงสัยสามารถสอบถามได้เลยนะคะ';

    public function __construct(
        protected LINEService $lineService,
        protected TelegramService $telegramService,
        protected FacebookService $facebookService,
        protected ResponseHoursService $responseHoursService,
        protected OpenRouterService $openRouterService
    ) {}

    /**
     * Find conversations that need recovery for a specific bot.
     *
     * @param Bot $bot
     * @return Collection<int, Conversation>
     */
    public function findEligibleConversations(Bot $bot): Collection
    {
        // Get HITL settings for timeout and max attempts
        $settings = $bot->settings?->hitlSettings;

        $timeoutHours = $settings?->lead_recovery_timeout_hours ?? self::DEFAULT_TIMEOUT_HOURS;
        $maxAttempts = $settings?->lead_recovery_max_attempts ?? self::DEFAULT_MAX_ATTEMPTS;

        return Conversation::query()
            ->forBot($bot->id)
            ->needsRecovery($timeoutHours, $maxAttempts)
            ->with('customerProfile')
            ->get();
    }

    /**
     * Process recovery for a conversation.
     * Main entry point for lead recovery.
     *
     * @param Conversation $conversation
     * @return bool Success status
     */
    public function processRecovery(Conversation $conversation): bool
    {
        $bot = $conversation->bot;

        // Check response hours
        $responseCheck = $this->responseHoursService->checkResponseHours($bot);
        if (!$responseCheck['allowed']) {
            Log::debug('Lead recovery skipped: outside response hours', [
                'conversation_id' => $conversation->id,
                'bot_id' => $bot->id,
                'status' => $responseCheck['status'],
            ]);
            return false;
        }

        // Get HITL settings
        $settings = $bot->settings?->hitlSettings;
        $mode = $settings?->lead_recovery_mode ?? 'static';

        try {
            // Generate message based on mode
            $message = match ($mode) {
                'ai' => $this->generateAIFollowUp($conversation) ?? $this->generateStaticMessage($bot),
                default => $this->generateStaticMessage($bot),
            };

            // Send the follow-up message
            $success = $this->sendStaticFollowUp($conversation, $message);

            // Log the attempt
            $this->logRecoveryAttempt(
                $conversation,
                $mode,
                $message,
                $success ? 'sent' : 'failed',
                $success ? null : 'Failed to send message'
            );

            // Update conversation after recovery attempt
            if ($success) {
                $this->updateConversationAfterRecovery($conversation);
            }

            return $success;
        } catch (\Exception $e) {
            Log::error('Lead recovery failed', [
                'conversation_id' => $conversation->id,
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);

            $this->logRecoveryAttempt(
                $conversation,
                $mode,
                '',
                'failed',
                $e->getMessage()
            );

            return false;
        }
    }

    /**
     * Send a static follow-up message via the appropriate channel.
     *
     * @param Conversation $conversation
     * @param string $message
     * @return bool Success status
     */
    public function sendStaticFollowUp(Conversation $conversation, string $message): bool
    {
        $bot = $conversation->bot;
        $channelType = $conversation->channel_type;
        $externalCustomerId = $conversation->external_customer_id;

        if (empty($externalCustomerId)) {
            Log::warning('Lead recovery: no external customer ID', [
                'conversation_id' => $conversation->id,
            ]);
            return false;
        }

        try {
            return match ($channelType) {
                'line' => $this->sendViaLINE($bot, $externalCustomerId, $message),
                'telegram' => $this->sendViaTelegram($bot, $externalCustomerId, $message),
                'facebook' => $this->sendViaFacebook($bot, $externalCustomerId, $message),
                default => $this->handleUnsupportedChannel($channelType, $conversation->id),
            };
        } catch (\Exception $e) {
            Log::error('Lead recovery send failed', [
                'conversation_id' => $conversation->id,
                'channel_type' => $channelType,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send message via LINE channel.
     */
    protected function sendViaLINE(Bot $bot, string $userId, string $message): bool
    {
        // Use retry key for idempotency (LINE best practice)
        $retryKey = $this->lineService->generateRetryKey();
        $this->lineService->push($bot, $userId, [$message], $retryKey);
        return true;
    }

    /**
     * Send message via Telegram channel.
     */
    protected function sendViaTelegram(Bot $bot, string $chatId, string $message): bool
    {
        $this->telegramService->sendMessage($bot, $chatId, $message);
        return true;
    }

    /**
     * Send message via Facebook channel.
     */
    protected function sendViaFacebook(Bot $bot, string $recipientId, string $message): bool
    {
        $this->facebookService->sendMessage($bot, $recipientId, $message);
        return true;
    }

    /**
     * Handle unsupported channel type.
     */
    protected function handleUnsupportedChannel(string $channelType, int $conversationId): bool
    {
        Log::warning('Lead recovery: unsupported channel type', [
            'conversation_id' => $conversationId,
            'channel_type' => $channelType,
        ]);
        return false;
    }

    /**
     * Generate a static follow-up message for the bot.
     *
     * @param Bot $bot
     * @return string
     */
    public function generateStaticMessage(Bot $bot): string
    {
        $settings = $bot->settings?->hitlSettings;
        $customMessage = $settings?->lead_recovery_message;

        if (!empty($customMessage)) {
            return $customMessage;
        }

        return $this->getDefaultMessage();
    }

    /**
     * Log a recovery attempt to the database.
     *
     * @param Conversation $conversation
     * @param string $mode 'static' or 'ai'
     * @param string $message The message that was sent
     * @param string $status 'sent', 'failed', 'pending'
     * @param string|null $error Error message if failed
     * @return LeadRecoveryLog
     */
    public function logRecoveryAttempt(
        Conversation $conversation,
        string $mode,
        string $message,
        string $status,
        ?string $error = null
    ): LeadRecoveryLog {
        return LeadRecoveryLog::create([
            'conversation_id' => $conversation->id,
            'bot_id' => $conversation->bot_id,
            'attempt_number' => ($conversation->recovery_attempts ?? 0) + 1,
            'message_mode' => $mode,
            'message_sent' => $message,
            'sent_at' => now(),
            'delivery_status' => $status,
            'error_message' => $error,
            'customer_responded' => false,
        ]);
    }

    /**
     * Update conversation after a successful recovery attempt.
     *
     * @param Conversation $conversation
     * @return void
     */
    public function updateConversationAfterRecovery(Conversation $conversation): void
    {
        $conversation->update([
            'recovery_attempts' => ($conversation->recovery_attempts ?? 0) + 1,
            'last_recovery_at' => now(),
        ]);
    }

    /**
     * Get the default Thai follow-up message.
     *
     * @return string
     */
    public function getDefaultMessage(): string
    {
        return self::DEFAULT_MESSAGE;
    }

    // =========================================================================
    // AI Mode Methods
    // =========================================================================

    /**
     * Generate an AI-powered follow-up message.
     *
     * @param Conversation $conversation
     * @return string|null Returns null if AI generation fails
     */
    public function generateAIFollowUp(Conversation $conversation): ?string
    {
        try {
            $bot = $conversation->bot;

            // Get system prompt from default flow
            $systemPrompt = $this->getSystemPromptFromDefaultFlow($bot);
            if (empty($systemPrompt)) {
                Log::debug('Lead recovery AI: no system prompt, falling back to static', [
                    'conversation_id' => $conversation->id,
                    'bot_id' => $bot->id,
                ]);
                return null;
            }

            // Get conversation context (last 5 messages)
            $context = $this->getConversationContext($conversation, 5);

            // Format context for prompt
            $contextFormatted = '';
            foreach ($context as $msg) {
                $role = $msg['role'] === 'customer' ? 'ลูกค้า' : 'บอท';
                $contextFormatted .= "{$role}: {$msg['content']}\n";
            }

            // Build the prompt
            $prompt = "{$systemPrompt}

## Task
ลูกค้าเคยสนทนาแต่หายไป ให้ส่งข้อความติดตามสั้นๆ 1-2 ประโยค

## Recent Conversation
{$contextFormatted}

## Rules
- เป็นมิตร ไม่กดดัน
- อ้างอิงสิ่งที่ลูกค้าสนใจ (ถ้ามี)
- รักษา personality จาก system prompt
- ตอบเป็นภาษาไทย";

            // Call OpenRouter to generate message
            $messages = [
                ['role' => 'user', 'content' => $prompt],
            ];

            $result = $this->openRouterService->chat(
                messages: $messages,
                model: 'openai/gpt-4o-mini',
                temperature: 0.7,
                maxTokens: 150,
                useFallback: true
            );

            $generatedMessage = trim($result['content'] ?? '');

            if (empty($generatedMessage)) {
                Log::warning('Lead recovery AI: empty response from OpenRouter', [
                    'conversation_id' => $conversation->id,
                    'bot_id' => $bot->id,
                ]);
                return null;
            }

            Log::info('Lead recovery AI: generated follow-up message', [
                'conversation_id' => $conversation->id,
                'bot_id' => $bot->id,
                'message_length' => strlen($generatedMessage),
                'model_used' => $result['model'] ?? 'unknown',
            ]);

            return $generatedMessage;
        } catch (\Exception $e) {
            Log::error('Lead recovery AI: failed to generate message', [
                'conversation_id' => $conversation->id,
                'bot_id' => $conversation->bot_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get the system prompt from the bot's default flow.
     *
     * @param Bot $bot
     * @return string|null
     */
    public function getSystemPromptFromDefaultFlow(Bot $bot): ?string
    {
        try {
            // Load default flow if not already loaded
            if (!$bot->relationLoaded('defaultFlow')) {
                $bot->load('defaultFlow');
            }

            $defaultFlow = $bot->defaultFlow;

            if (!$defaultFlow) {
                Log::debug('Lead recovery: bot has no default flow', [
                    'bot_id' => $bot->id,
                ]);
                return null;
            }

            $systemPrompt = $defaultFlow->system_prompt;

            if (empty($systemPrompt)) {
                Log::debug('Lead recovery: default flow has no system prompt', [
                    'bot_id' => $bot->id,
                    'flow_id' => $defaultFlow->id,
                ]);
                return null;
            }

            return $systemPrompt;
        } catch (\Exception $e) {
            Log::error('Lead recovery: failed to get system prompt', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Mark that a customer has responded to a lead recovery attempt.
     *
     * Finds the most recent LeadRecoveryLog for this conversation where
     * customer_responded = false and updates it to mark the response.
     *
     * @param Conversation $conversation
     * @return void
     */
    public function markCustomerResponded(Conversation $conversation): void
    {
        $log = LeadRecoveryLog::where('conversation_id', $conversation->id)
            ->where('customer_responded', false)
            ->orderByDesc('sent_at')
            ->first();

        if ($log) {
            $log->update([
                'customer_responded' => true,
                'responded_at' => now(),
            ]);

            Log::info('Lead recovery: customer responded', [
                'conversation_id' => $conversation->id,
                'log_id' => $log->id,
                'attempt_number' => $log->attempt_number,
            ]);
        }
    }

    /**
     * Get recent conversation context for AI message generation.
     *
     * @param Conversation $conversation
     * @param int $limit Number of recent messages to retrieve
     * @return array Array of messages with role (customer/assistant) and content
     */
    public function getConversationContext(Conversation $conversation, int $limit = 5): array
    {
        try {
            // Get last N messages ordered from oldest to newest
            $messages = $conversation->messages()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();

            $context = [];
            foreach ($messages as $message) {
                // Map sender to role (user -> customer, bot/agent -> assistant)
                $role = $message->sender === 'user' ? 'customer' : 'assistant';

                $context[] = [
                    'role' => $role,
                    'content' => $message->content ?? '',
                ];
            }

            Log::debug('Lead recovery: retrieved conversation context', [
                'conversation_id' => $conversation->id,
                'message_count' => count($context),
            ]);

            return $context;
        } catch (\Exception $e) {
            Log::error('Lead recovery: failed to get conversation context', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
