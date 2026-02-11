<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\FlowPlugin;
use App\Models\Message;
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
        if (!$flow) {
            return;
        }

        $plugins = $flow->plugins()->where('enabled', true)->get();
        if ($plugins->isEmpty()) {
            return;
        }

        foreach ($plugins as $plugin) {
            try {
                $this->evaluateAndExecute($plugin, $bot, $conversation, $botMessage);
            } catch (\Exception $e) {
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
     * Evaluate trigger condition and execute plugin if triggered.
     */
    protected function evaluateAndExecute(
        FlowPlugin $plugin,
        Bot $bot,
        Conversation $conversation,
        Message $botMessage
    ): void {
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
        $variableNames = $matches[1] ?? [];

        $variablesPrompt = !empty($variableNames)
            ? 'Variables to extract: ' . implode(', ', $variableNames)
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
            return;
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
        if (!$evaluation || !($evaluation['triggered'] ?? false)) {
            return;
        }

        // Format message template with extracted variables
        $variables = $evaluation['variables'] ?? [];
        $message = $template;
        foreach ($variables as $key => $value) {
            $message = str_replace("{{$key}}", $value, $message);
        }

        // Execute based on plugin type
        if ($plugin->type === 'telegram') {
            $this->sendTelegramNotification($plugin, $message);
        }
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
            Log::info('Telegram plugin notification sent', [
                'plugin_id' => $plugin->id,
                'chat_id' => $chatId,
            ]);
        } else {
            Log::warning('Telegram plugin notification failed', [
                'plugin_id' => $plugin->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
