<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\FlowPlugin;
use App\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlowPluginService
{
    public function __construct(
        protected OpenRouterService $openRouter
    ) {}

    /**
     * Execute all enabled plugins for a flow after bot responds.
     */
    public function executePlugins(Bot $bot, Conversation $conversation, Message $botMessage): void
    {
        $flow = $conversation->currentFlow ?? $bot->defaultFlow;
        if (! $flow) {
            error_log("PLUGIN DEBUG: No flow found - conversation={$conversation->id}");

            return;
        }

        $plugins = $flow->plugins()->where('enabled', true)->get();
        if ($plugins->isEmpty()) {
            error_log("PLUGIN DEBUG: No enabled plugins - flow={$flow->id}");

            return;
        }
        error_log("PLUGIN DEBUG: Found {$plugins->count()} plugin(s) - flow={$flow->id}, conversation={$conversation->id}");

        // Eager load user.settings to avoid N+1 query during API key resolution
        if (! $bot->relationLoaded('user')) {
            $bot->load('user.settings');
        }

        foreach ($plugins as $plugin) {
            try {
                // Rate limit: max 1 execution per plugin per conversation per 60 seconds
                $cacheKey = "plugin_exec:{$plugin->id}:{$conversation->id}";
                if (Cache::has($cacheKey)) {
                    Log::debug('Plugin rate limited', ['plugin_id' => $plugin->id]);

                    continue;
                }

                $triggered = $this->evaluateAndExecute($plugin, $bot, $conversation, $botMessage);

                if ($triggered) {
                    Cache::put($cacheKey, true, 60);
                }
            } catch (\Exception $e) {
                error_log("PLUGIN DEBUG: Exception - plugin={$plugin->id}, error={$e->getMessage()}");
                Log::warning('Plugin execution failed', [
                    'plugin_id' => $plugin->id,
                    'plugin_type' => $plugin->type,
                    'flow_id' => $flow->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Check if bot message contains any trigger keywords (pre-filter).
     * Returns true if no keywords configured (backward compatible) or if any keyword matches.
     */
    protected function passesKeywordFilter(FlowPlugin $plugin, Message $botMessage): bool
    {
        $keywords = $plugin->config['trigger_keywords'] ?? [];

        // No keywords configured = skip filter (always pass)
        if (empty($keywords) || ! is_array($keywords)) {
            return true;
        }

        $content = mb_strtolower($botMessage->content ?? '');

        foreach ($keywords as $keyword) {
            if (is_string($keyword) && mb_strpos($content, mb_strtolower($keyword)) !== false) {
                return true;
            }
        }

        Log::debug('Plugin skipped: no keyword match', [
            'plugin_id' => $plugin->id,
            'keywords' => $keywords,
        ]);

        return false;
    }

    /**
     * Evaluate trigger condition and execute plugin if triggered.
     */
    protected function evaluateAndExecute(
        FlowPlugin $plugin,
        Bot $bot,
        Conversation $conversation,
        Message $botMessage
    ): bool {
        // Keyword pre-filter: skip AI call if bot message doesn't contain any trigger keywords
        if (! $this->passesKeywordFilter($plugin, $botMessage)) {
            error_log("PLUGIN DEBUG: Keyword filter failed - plugin={$plugin->id}");

            return false;
        }
        error_log("PLUGIN DEBUG: Keyword filter passed - plugin={$plugin->id}");

        // Load customer profile for metadata
        $conversation->loadMissing('customerProfile');
        $customerName = $conversation->customerProfile?->display_name ?? 'ไม่ทราบชื่อ';

        // Get last 5 messages for context
        $recentMessages = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->reverse()
            ->values();

        $conversationContext = $recentMessages->map(function ($msg) {
            $role = $msg->sender === 'bot' ? 'Assistant' : 'User';

            return "{$role}: {$msg->content}";
        })->implode("\n");

        // Extract variable names from message_template
        $template = $plugin->config['message_template'] ?? '';
        preg_match_all('/\{(\w+)\}/', $template, $matches);
        $variableNames = array_values(array_diff($matches[1] ?? [], ['datetime']));

        $variablesPrompt = ! empty($variableNames)
            ? 'Variables to extract: '.implode(', ', $variableNames)
            : 'No variables to extract';

        // Build evaluation prompt
        $messages = [
            [
                'role' => 'system',
                'content' => 'You evaluate whether a conversation has triggered a specific condition. Return ONLY valid JSON, no other text.',
            ],
            [
                'role' => 'user',
                'content' => <<<PROMPT
Conversation context:
Customer display name: {$customerName}
{$conversationContext}

Trigger condition: "{$plugin->trigger_condition}"
{$variablesPrompt}

Evaluate if the trigger condition is met based on the conversation. Return JSON only:
{"triggered": true/false, "variables": {"key": "value"}}
PROMPT,
            ],
        ];

        // Get API key
        $apiKey = $bot->user?->settings?->getOpenRouterApiKey() ?? config('services.openrouter.api_key');
        if (empty($apiKey)) {
            Log::warning('No API key for plugin evaluation', ['plugin_id' => $plugin->id]);

            return false;
        }

        // Use lightweight model for evaluation
        $result = $this->openRouter->chat(
            messages: $messages,
            model: 'openai/gpt-4o-mini',
            temperature: 0.1,
            maxTokens: 256,
            useFallback: false,
            apiKeyOverride: $apiKey,
            timeout: 15
        );

        $responseContent = $result['content'] ?? '';

        // Parse JSON from response (handle markdown code blocks)
        $jsonContent = $responseContent;
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $responseContent, $jsonMatch)) {
            $jsonContent = $jsonMatch[1];
        }

        $evaluation = json_decode(trim($jsonContent), true);

        // Validate JSON structure
        if (! is_array($evaluation)) {
            Log::warning('Plugin AI returned invalid JSON', [
                'plugin_id' => $plugin->id,
                'response' => mb_substr($responseContent, 0, 500),
            ]);

            return false;
        }

        if (! ($evaluation['triggered'] ?? false)) {
            error_log("PLUGIN DEBUG: AI said NOT triggered - plugin={$plugin->id}");

            return false;
        }
        error_log("PLUGIN DEBUG: AI said TRIGGERED - plugin={$plugin->id}");

        // Format message template with extracted variables
        $variables = $evaluation['variables'] ?? [];
        if (! is_array($variables)) {
            Log::warning('Plugin variables not an array', [
                'plugin_id' => $plugin->id,
            ]);
            $variables = [];
        }

        // Inject customer_name from DB (don't rely on AI extraction)
        $variables['customer_name'] = $variables['customer_name'] ?? $customerName;

        Log::debug('Plugin AI-extracted variables', [
            'plugin_id' => $plugin->id,
            'variables' => $variables,
        ]);

        $message = $template;
        $variables['datetime'] = now('Asia/Bangkok')->format('d/m/Y H:i');
        foreach ($variables as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $message = str_replace("{{$key}}", (string) $value, $message);
            }
        }

        // Fallback: extract unreplaced variables using regex from bot message
        $unreplacedVars = [];
        preg_match_all('/\{(\w+)\}/', $message, $unreplacedMatches);
        $unreplacedVars = $unreplacedMatches[1] ?? [];

        if (! empty($unreplacedVars)) {
            Log::info('Plugin unreplaced variables detected, trying regex fallback', [
                'plugin_id' => $plugin->id,
                'unreplaced' => $unreplacedVars,
            ]);

            $fallbackValues = $this->extractVariablesFallback($botMessage->content ?? '', $unreplacedVars);

            Log::debug('Plugin regex fallback results', [
                'plugin_id' => $plugin->id,
                'recovered' => $fallbackValues,
            ]);

            foreach ($fallbackValues as $key => $value) {
                $message = str_replace("{{$key}}", $value, $message);
            }

            // Final cleanup: replace any remaining {var} with "-"
            $message = preg_replace('/\{(\w+)\}/', '-', $message);
        }

        // Execute based on plugin type
        if ($plugin->type === 'telegram') {
            $this->sendTelegramNotification($plugin, $message);

            // Record order from extracted data
            try {
                app(OrderService::class)->createFromPluginExtraction(
                    $bot,
                    $conversation,
                    $botMessage ?? null,
                    array_merge($variables, $fallbackValues ?? [])
                );
            } catch (\Throwable $e) {
                Log::warning('Order recording failed', ['error' => $e->getMessage()]);
            }

            return true;
        }

        return false;
    }

    /**
     * Regex fallback to extract variables from bot message content.
     */
    private function extractVariablesFallback(string $botContent, array $variableNames): array
    {
        $extracted = [];

        foreach ($variableNames as $varName) {
            $value = match ($varName) {
                'amount' => $this->extractAmount($botContent),
                'product' => $this->extractProduct($botContent),
                'source_bank' => $this->extractBank($botContent),
                default => null,
            };

            if ($value !== null) {
                $extracted[$varName] = $value;
            }
        }

        return $extracted;
    }

    private function extractAmount(string $content): ?string
    {
        // Match "เงินเข้าแล้ว 1,100 บาท" or "1,100.00 บาท ✅"
        if (preg_match('/(?:เงินเข้าแล้ว\s*)([\d,]+\.?\d*)\s*บาท/u', $content, $m)) {
            return $m[1];
        }
        if (preg_match('/([\d,]+\.?\d*)\s*บาท\s*✅/u', $content, $m)) {
            return $m[1];
        }
        if (preg_match('/([\d,]+\.?\d*)\s*บาท/u', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractProduct(string $content): ?string
    {
        // Match "ออเดอร์:\n- Product line 1\n- Product line 2"
        if (preg_match('/ออเดอร์[:\s]*\n([\s\S]*?)(?=\n\n|\n\[|\nส่งใน|$)/u', $content, $m)) {
            $lines = array_filter(array_map(function ($line) {
                return trim(preg_replace('/^[-•]\s*/', '', trim($line)));
            }, explode("\n", trim($m[1]))));
            $product = implode(', ', $lines);

            return ! empty($product) ? $product : null;
        }

        return null;
    }

    private function extractBank(string $content): ?string
    {
        $banks = [
            'กสิกร' => 'กสิกรไทย (KBANK)',
            'KBANK' => 'กสิกรไทย (KBANK)',
            'K PLUS' => 'กสิกรไทย (KBANK)',
            'ไทยพาณิชย์' => 'ไทยพาณิชย์ (SCB)',
            'SCB' => 'ไทยพาณิชย์ (SCB)',
            'กรุงเทพ' => 'กรุงเทพ (BBL)',
            'BBL' => 'กรุงเทพ (BBL)',
            'กรุงไทย' => 'กรุงไทย (KTB)',
            'KTB' => 'กรุงไทย (KTB)',
            'กรุงศรี' => 'กรุงศรี (BAY)',
            'BAY' => 'กรุงศรี (BAY)',
            'ทหารไทยธนชาต' => 'ทหารไทยธนชาต (ttb)',
            'ttb' => 'ทหารไทยธนชาต (ttb)',
            'TMB' => 'ทหารไทยธนชาต (ttb)',
            'ออมสิน' => 'ออมสิน (GSB)',
            'GSB' => 'ออมสิน (GSB)',
            'PromptPay' => 'PromptPay',
            'พร้อมเพย์' => 'PromptPay',
        ];

        foreach ($banks as $keyword => $label) {
            if (mb_stripos($content, $keyword) !== false) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Send notification via Telegram Bot API.
     */
    protected function sendTelegramNotification(FlowPlugin $plugin, string $message): void
    {
        $token = $plugin->config['access_token'] ?? '';
        $chatId = $plugin->config['chat_id'] ?? '';

        if (empty($token) || empty($chatId)) {
            Log::warning('Telegram plugin missing config', ['plugin_id' => $plugin->id]);

            return;
        }

        $response = Http::retry(2, 500)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

        if ($response->successful()) {
            error_log("PLUGIN DEBUG: Telegram sent OK - plugin={$plugin->id}, chat_id={$chatId}");
            Log::info('Telegram plugin notification sent', [
                'plugin_id' => $plugin->id,
                'chat_id' => $chatId,
            ]);
        } else {
            $status = $response->status();
            $error = $response->json('description', 'Unknown error');
            error_log("PLUGIN DEBUG: Telegram FAILED - plugin={$plugin->id}, status={$status}, error={$error}");

            Log::warning('Telegram plugin notification failed', [
                'plugin_id' => $plugin->id,
                'chat_id' => $chatId,
                'status' => $status,
                'error' => $error,
            ]);

            // Auto-disable plugin on auth/not-found errors (invalid token or chat)
            if (in_array($status, [401, 403, 404])) {
                $plugin->update(['enabled' => false]);
                Log::error('Plugin auto-disabled: invalid token or chat_id', [
                    'plugin_id' => $plugin->id,
                ]);
            }
        }
    }
}
