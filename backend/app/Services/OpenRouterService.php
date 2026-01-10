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
        // Use ?? to ensure string type even when config returns null
        $this->apiKey = config('services.openrouter.api_key') ?? '';
        $this->baseUrl = config('services.openrouter.base_url') ?? 'https://openrouter.ai/api/v1';
        $this->defaultModel = config('services.openrouter.default_model') ?? 'anthropic/claude-3.5-sonnet';
        $this->fallbackModel = config('services.openrouter.fallback_model') ?? 'openai/gpt-4o-mini';
        $this->siteUrl = config('services.openrouter.site_url') ?? config('app.url') ?? '';
        $this->siteName = config('services.openrouter.site_name') ?? config('app.name') ?? 'BotFacebook';
        $this->timeout = (int) (config('services.openrouter.timeout') ?? 60);
        $this->maxTokens = (int) (config('services.openrouter.max_tokens') ?? 4096);
    }

    /**
     * Send a chat completion request to OpenRouter.
     *
     * @param array $messages Chat messages
     * @param string|null $model Model ID (e.g., 'openai/gpt-4o-mini')
     * @param float|null $temperature Sampling temperature
     * @param int|null $maxTokens Maximum tokens in response
     * @param bool $useFallback Whether to try fallback model on failure
     * @param string|null $apiKeyOverride Override API key (from user settings)
     * @param string|null $fallbackModelOverride Override fallback model (from bot settings)
     */
    public function chat(
        array $messages,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        bool $useFallback = true,
        ?string $apiKeyOverride = null,
        ?string $fallbackModelOverride = null
    ): array {
        $model = $model ?? $this->defaultModel;
        $temperature = $temperature ?? 0.7;
        $maxTokens = $maxTokens ?? $this->maxTokens;
        $apiKey = $apiKeyOverride ?? $this->apiKey;
        $fallbackModel = $fallbackModelOverride ?? $this->fallbackModel;

        try {
            $response = $this->client($apiKey)->post('/chat/completions', [
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

                if ($useFallback && $model !== $fallbackModel) {
                    Log::info('Attempting fallback model', ['fallback' => $fallbackModel]);
                    return $this->chat($messages, $fallbackModel, $temperature, $maxTokens, false, $apiKeyOverride, null);
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

            if ($useFallback && $model !== $fallbackModel) {
                Log::info('Attempting fallback model after exception', ['fallback' => $fallbackModel]);
                return $this->chat($messages, $fallbackModel, $temperature, $maxTokens, false, $apiKeyOverride, null);
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
     * Send a chat completion request with tools (function calling).
     *
     * Used for agentic mode where AI can decide to call tools.
     *
     * @param array $messages Chat messages
     * @param array $tools Tool definitions in OpenAI format
     * @param string|null $model Model ID
     * @param float|null $temperature Sampling temperature
     * @param int|null $maxTokens Maximum tokens in response
     * @param string|null $apiKeyOverride Override API key
     * @param string $toolChoice Tool calling behavior: 'auto', 'none', 'required'
     * @return array Response with possible tool_calls
     */
    public function chatWithTools(
        array $messages,
        array $tools,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?string $apiKeyOverride = null,
        string $toolChoice = 'auto'
    ): array {
        $model = $model ?? $this->defaultModel;
        $temperature = $temperature ?? 0.7;
        $maxTokens = $maxTokens ?? $this->maxTokens;
        $apiKey = $apiKeyOverride ?? $this->apiKey;

        try {
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ];

            // Add tools if provided
            if (!empty($tools)) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = $toolChoice;
            }

            $response = $this->client($apiKey)->post('/chat/completions', $payload);

            if ($response->failed()) {
                $error = $response->json('error.message', 'Unknown error');

                Log::warning('OpenRouter API with tools failed', [
                    'model' => $model,
                    'status' => $response->status(),
                    'error' => $error,
                ]);

                throw new OpenRouterException("OpenRouter API error: {$error}", $response->status());
            }

            $data = $response->json();
            $choice = $data['choices'][0] ?? [];
            $message = $choice['message'] ?? [];
            $finishReason = $choice['finish_reason'] ?? 'stop';

            // Build response
            $result = [
                'content' => $message['content'] ?? '',
                'model' => $data['model'] ?? $model,
                'usage' => [
                    'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                    'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
                    'total_tokens' => $data['usage']['total_tokens'] ?? 0,
                ],
                'id' => $data['id'] ?? null,
                'finish_reason' => $finishReason,
                'tool_calls' => null,
            ];

            // Include tool calls if present
            if ($finishReason === 'tool_calls' && isset($message['tool_calls'])) {
                $result['tool_calls'] = $message['tool_calls'];
            }

            return $result;
        } catch (OpenRouterException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('OpenRouter request with tools failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            throw new OpenRouterException("OpenRouter request failed: {$e->getMessage()}", 500, $e);
        }
    }

    /**
     * Generate a bot response with system prompt and conversation history.
     *
     * @param string $userMessage The user's message
     * @param string|null $systemPrompt System prompt for the bot
     * @param array $conversationHistory Previous messages
     * @param string|null $model Primary model to use
     * @param string|null $fallbackModel Fallback model if primary fails
     * @param float|null $temperature Sampling temperature
     * @param int|null $maxTokens Maximum tokens in response
     * @param string|null $apiKeyOverride Override API key
     */
    public function generateBotResponse(
        string $userMessage,
        ?string $systemPrompt = null,
        array $conversationHistory = [],
        ?string $model = null,
        ?string $fallbackModel = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?string $apiKeyOverride = null
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

        return $this->chat($messages, $model, $temperature, $maxTokens, true, $apiKeyOverride, $fallbackModel);
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
    public function estimateCost(int $promptTokens, int $completionTokens, ?string $model = null): float
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
     * Send a chat completion request with vision/image analysis support.
     *
     * @param array $messages Chat messages (can include multimodal content)
     * @param array $imageUrls Array of image URLs to analyze
     * @param string|null $model Model ID (must be vision-capable)
     * @param float|null $temperature Sampling temperature
     * @param int|null $maxTokens Maximum tokens in response
     * @param string|null $apiKeyOverride Override API key
     * @return array Response with content and usage
     */
    public function chatWithVision(
        array $messages,
        array $imageUrls,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?string $apiKeyOverride = null
    ): array {
        $model = $model ?? 'google/gemini-2.0-flash-001'; // Default to Gemini for vision
        $temperature = $temperature ?? 0.7;
        $maxTokens = $maxTokens ?? $this->maxTokens;
        $apiKey = $apiKeyOverride ?? $this->apiKey;

        // Build multimodal messages
        $visionMessages = $this->buildVisionMessages($messages, $imageUrls);

        try {
            $response = $this->client($apiKey)->post('/chat/completions', [
                'model' => $model,
                'messages' => $visionMessages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

            if ($response->failed()) {
                $error = $response->json('error.message', 'Unknown error');

                Log::warning('OpenRouter Vision API failed', [
                    'model' => $model,
                    'status' => $response->status(),
                    'error' => $error,
                ]);

                throw new OpenRouterException("OpenRouter Vision API error: {$error}", $response->status());
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
            Log::error('OpenRouter Vision request failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            throw new OpenRouterException("OpenRouter Vision request failed: {$e->getMessage()}", 500, $e);
        }
    }

    /**
     * Build multimodal messages with image URLs for vision models.
     *
     * Converts standard messages to multimodal format when images are present.
     * Format follows OpenRouter/OpenAI vision API spec.
     *
     * @param array $messages Original messages
     * @param array $imageUrls Array of image URLs
     * @return array Multimodal messages
     */
    protected function buildVisionMessages(array $messages, array $imageUrls): array
    {
        if (empty($imageUrls)) {
            return $messages;
        }

        $visionMessages = [];

        foreach ($messages as $message) {
            // Only convert user messages with text content to multimodal
            if ($message['role'] === 'user' && is_string($message['content'])) {
                // Build multimodal content array
                $content = [
                    ['type' => 'text', 'text' => $message['content']],
                ];

                // Add all images to this message
                foreach ($imageUrls as $imageUrl) {
                    $content[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => $imageUrl],
                    ];
                }

                $visionMessages[] = [
                    'role' => 'user',
                    'content' => $content,
                ];
            } else {
                // Keep system and assistant messages as-is
                $visionMessages[] = $message;
            }
        }

        return $visionMessages;
    }

    /**
     * Check if a model supports vision/image analysis.
     *
     * Vision-capable models:
     * - Google Gemini (all versions)
     * - OpenAI GPT-4o, GPT-4 Turbo, GPT-4 Vision
     * - Anthropic Claude 3 and 3.5 (all versions)
     *
     * @param string $model Model ID
     * @return bool Whether the model supports vision
     */
    public function supportsVision(string $model): bool
    {
        $model = strtolower($model);

        // Gemini models (all support vision)
        if (str_starts_with($model, 'google/gemini')) {
            return true;
        }

        // OpenAI vision models
        if (str_starts_with($model, 'openai/gpt-4o') ||
            str_starts_with($model, 'openai/gpt-4-turbo') ||
            str_starts_with($model, 'openai/gpt-4-vision') ||
            str_starts_with($model, 'openai/chatgpt-4o')) {
            return true;
        }

        // Claude 3 and 3.5 models (all support vision)
        if (str_starts_with($model, 'anthropic/claude-3') ||
            str_starts_with($model, 'anthropic/claude-3.5')) {
            return true;
        }

        return false;
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
     *
     * @param string|null $apiKey API key (uses default from config if not provided)
     */
    protected function client(?string $apiKey = null): PendingRequest
    {
        $key = $apiKey ?? $this->apiKey;

        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'HTTP-Referer' => $this->siteUrl,
                'X-Title' => $this->siteName,
            ])
            ->timeout($this->timeout)
            ->acceptJson();
    }
}
