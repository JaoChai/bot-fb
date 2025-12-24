<?php

namespace App\Services;

use App\Exceptions\OpenRouterException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $defaultModel;
    protected string $fallbackModel;
    protected string $siteUrl;
    protected string $siteName;
    protected int $timeout;
    protected int $maxTokens;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key', '');
        $this->baseUrl = config('services.openrouter.base_url');
        $this->defaultModel = config('services.openrouter.default_model');
        $this->fallbackModel = config('services.openrouter.fallback_model');
        $this->siteUrl = config('services.openrouter.site_url');
        $this->siteName = config('services.openrouter.site_name');
        $this->timeout = config('services.openrouter.timeout');
        $this->maxTokens = config('services.openrouter.max_tokens');
    }

    /**
     * Send a chat completion request to OpenRouter.
     */
    public function chat(
        array $messages,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        bool $useFallback = true
    ): array {
        $model = $model ?? $this->defaultModel;
        $temperature = $temperature ?? 0.7;
        $maxTokens = $maxTokens ?? $this->maxTokens;

        try {
            $response = $this->client()->post('/chat/completions', [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

            if ($response->failed()) {
                $error = $response->json('error.message', 'Unknown error');

                Log::warning('OpenRouter API failed', [
                    'model' => $model,
                    'status' => $response->status(),
                    'error' => $error,
                ]);

                if ($useFallback && $model !== $this->fallbackModel) {
                    Log::info('Attempting fallback model', ['fallback' => $this->fallbackModel]);
                    return $this->chat($messages, $this->fallbackModel, $temperature, $maxTokens, false);
                }

                throw new OpenRouterException("OpenRouter API error: {$error}", $response->status());
            }

            $data = $response->json();

            return [
                'content' => $data['choices'][0]['message']['content'] ?? '',
                'model' => $data['model'] ?? $model,
                'usage' => [
                    'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                    'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
                    'total_tokens' => $data['usage']['total_tokens'] ?? 0,
                ],
                'id' => $data['id'] ?? null,
                'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
            ];
        } catch (OpenRouterException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('OpenRouter request failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            if ($useFallback && $model !== $this->fallbackModel) {
                Log::info('Attempting fallback model after exception', ['fallback' => $this->fallbackModel]);
                return $this->chat($messages, $this->fallbackModel, $temperature, $maxTokens, false);
            }

            throw new OpenRouterException("OpenRouter request failed: {$e->getMessage()}", 500, $e);
        }
    }

    /**
     * Simple chat method that returns just the response content.
     */
    public function chatSimple(
        array $messages,
        ?string $model = null,
        ?float $temperature = null
    ): string {
        $result = $this->chat($messages, $model, $temperature);
        return $result['content'];
    }

    /**
     * Generate a bot response with system prompt and conversation history.
     */
    public function generateBotResponse(
        string $userMessage,
        ?string $systemPrompt = null,
        array $conversationHistory = [],
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null
    ): array {
        $messages = [];

        // Add system prompt if provided
        if ($systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        // Add conversation history
        foreach ($conversationHistory as $msg) {
            $messages[] = [
                'role' => $msg['sender'] === 'user' ? 'user' : 'assistant',
                'content' => $msg['content'],
            ];
        }

        // Add current user message
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage,
        ];

        return $this->chat($messages, $model, $temperature, $maxTokens);
    }

    /**
     * List available models from OpenRouter.
     */
    public function listModels(): array
    {
        $response = $this->client()->get('/models');

        if ($response->failed()) {
            throw new OpenRouterException('Failed to fetch models', $response->status());
        }

        return $response->json('data', []);
    }

    /**
     * Get model information.
     */
    public function getModel(string $modelId): ?array
    {
        $models = $this->listModels();

        foreach ($models as $model) {
            if ($model['id'] === $modelId) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Estimate cost based on token usage.
     */
    public function estimateCost(int $promptTokens, int $completionTokens, string $model = null): float
    {
        // Pricing per 1M tokens (approximate, check OpenRouter for current prices)
        $pricing = [
            'anthropic/claude-3.5-sonnet' => ['prompt' => 3.00, 'completion' => 15.00],
            'anthropic/claude-3-opus' => ['prompt' => 15.00, 'completion' => 75.00],
            'openai/gpt-4o' => ['prompt' => 2.50, 'completion' => 10.00],
            'openai/gpt-4o-mini' => ['prompt' => 0.15, 'completion' => 0.60],
            'meta-llama/llama-3.1-70b-instruct' => ['prompt' => 0.52, 'completion' => 0.75],
        ];

        $model = $model ?? $this->defaultModel;
        $modelPricing = $pricing[$model] ?? ['prompt' => 1.00, 'completion' => 2.00];

        $promptCost = ($promptTokens / 1_000_000) * $modelPricing['prompt'];
        $completionCost = ($completionTokens / 1_000_000) * $modelPricing['completion'];

        return round($promptCost + $completionCost, 6);
    }

    /**
     * Check if the service is properly configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Test the API connection.
     */
    public function testConnection(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->chat([
                ['role' => 'user', 'content' => 'Say "OK" if you can read this.'],
            ], null, 0, 10, false);

            return ! empty($response['content']);
        } catch (\Exception $e) {
            Log::warning('OpenRouter connection test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get configured HTTP client.
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'HTTP-Referer' => $this->siteUrl,
                'X-Title' => $this->siteName,
            ])
            ->timeout($this->timeout)
            ->acceptJson();
    }
}
