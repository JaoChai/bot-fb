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
        $this->apiKey = config_string('services.openrouter.api_key');
        $this->baseUrl = config_string('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        $this->defaultModel = config_string('services.openrouter.default_model', 'anthropic/claude-3.5-sonnet');
        $this->fallbackModel = config_string('services.openrouter.fallback_model', 'openai/gpt-4o-mini');
        $this->siteUrl = config_string('services.openrouter.site_url', config_string('app.url'));
        $this->siteName = config_string('services.openrouter.site_name', config_string('app.name', 'BotFacebook'));
        $this->timeout = config_int('services.openrouter.timeout', 60);
        $this->maxTokens = config_int('services.openrouter.max_tokens', 4096);
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
     * @param int|null $timeout Request timeout in seconds (null uses default)
     * @param array|null $reasoning Reasoning config for o1/deepseek-r1 models: ['effort' => 'low'|'medium'|'high']
     */
    public function chat(
        array $messages,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        bool $useFallback = true,
        ?string $apiKeyOverride = null,
        ?string $fallbackModelOverride = null,
        ?int $timeout = null,
        ?array $reasoning = null
    ): array {
        $model = $model ?? $this->defaultModel;
        $temperature = $temperature ?? 0.7;
        $maxTokens = $maxTokens ?? $this->maxTokens;
        $apiKey = $apiKeyOverride ?? $this->apiKey;
        $fallbackModel = $fallbackModelOverride ?? $this->fallbackModel;
        $requestTimeout = $timeout ?? $this->timeout;

        try {
            // Build payload with native fallback support (OpenRouter Best Practice)
            $payload = [
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                // Enable detailed usage tracking (OpenRouter Best Practice)
                'usage' => [
                    'include' => true,
                ],
            ];

            // Use models array for server-side fallback (faster than client-side retry)
            if ($useFallback && $fallbackModel && $model !== $fallbackModel) {
                $payload['models'] = [$model, $fallbackModel];
                Log::debug('Using native fallback', ['primary' => $model, 'fallback' => $fallbackModel]);
            } else {
                $payload['model'] = $model;
            }

            // Add reasoning config for supported models (o1, o1-mini, deepseek-r1)
            $modelConfig = config("llm-models.models.{$model}");
            if ($reasoning || ($modelConfig['supports_reasoning'] ?? false)) {
                $payload['reasoning'] = $reasoning ?? [
                    'effort' => $modelConfig['default_reasoning_effort'] ?? 'medium',
                ];
                Log::debug('Using reasoning mode', ['model' => $model, 'reasoning' => $payload['reasoning']]);
            }

            // Add provider preferences for routing optimization (OpenRouter Best Practice)
            $providerPrefs = $this->buildProviderPreferences();
            if (! empty($providerPrefs)) {
                $payload['provider'] = $providerPrefs;
            }

            $response = $this->client($apiKey, $requestTimeout)->post('/chat/completions', $payload);

            if ($response->failed()) {
                $error = $response->json('error.message', 'Unknown error');

                Log::warning('OpenRouter API failed', [
                    'model' => $model,
                    'status' => $response->status(),
                    'error' => $error,
                ]);

                throw new OpenRouterException("OpenRouter API error: {$error}", $response->status());
            }

            $data = $response->json();

            return $this->parseResponse($data, $model);
        } catch (OpenRouterException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('OpenRouter request failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

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
     * @param bool $useFallback Whether to try fallback model on failure
     * @param string|null $fallbackModelOverride Override fallback model
     * @return array Response with possible tool_calls
     */
    public function chatWithTools(
        array $messages,
        array $tools,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?string $apiKeyOverride = null,
        string $toolChoice = 'auto',
        bool $useFallback = true,
        ?string $fallbackModelOverride = null,
        ?int $timeout = null
    ): array {
        $model = $model ?? $this->defaultModel;
        $temperature = $temperature ?? 0.7;
        $maxTokens = $maxTokens ?? $this->maxTokens;
        $apiKey = $apiKeyOverride ?? $this->apiKey;
        $fallbackModel = $fallbackModelOverride ?? $this->fallbackModel;
        $requestTimeout = $timeout ?? $this->timeout;

        try {
            // Build payload with native fallback support (OpenRouter Best Practice)
            $payload = [
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                // Enable detailed usage tracking (OpenRouter Best Practice)
                'usage' => [
                    'include' => true,
                ],
            ];

            // Use models array for server-side fallback
            if ($useFallback && $fallbackModel && $model !== $fallbackModel) {
                $payload['models'] = [$model, $fallbackModel];
                Log::debug('Using native fallback for tools', ['primary' => $model, 'fallback' => $fallbackModel]);
            } else {
                $payload['model'] = $model;
            }

            // Add tools if provided
            if (! empty($tools)) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = $toolChoice;
            }

            // Add provider preferences for routing optimization (OpenRouter Best Practice)
            $providerPrefs = $this->buildProviderPreferences();
            if (! empty($providerPrefs)) {
                $payload['provider'] = $providerPrefs;
            }

            $response = $this->client($apiKey, $requestTimeout)->post('/chat/completions', $payload);

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

            return $this->parseToolResponse($data, $model);
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
     * @param bool $useFallback Whether to try fallback model on failure
     * @param string|null $fallbackModelOverride Override fallback model (must be vision-capable)
     * @return array Response with content and usage
     */
    public function chatWithVision(
        array $messages,
        array $imageUrls,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?string $apiKeyOverride = null,
        bool $useFallback = true,
        ?string $fallbackModelOverride = null
    ): array {
        if (! $model) {
            throw new \InvalidArgumentException('Vision model is required');
        }

        $temperature = $temperature ?? 0.7;
        $maxTokens = $maxTokens ?? $this->maxTokens;
        $apiKey = $apiKeyOverride ?? $this->apiKey;
        $fallbackModel = $fallbackModelOverride;

        // Build multimodal messages
        $visionMessages = $this->buildVisionMessages($messages, $imageUrls);

        try {
            // Build payload with native fallback support (OpenRouter Best Practice)
            $payload = [
                'messages' => $visionMessages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                // Enable detailed usage tracking (OpenRouter Best Practice)
                'usage' => [
                    'include' => true,
                ],
            ];

            // Use models array for server-side fallback
            if ($useFallback && $fallbackModel && $model !== $fallbackModel) {
                $payload['models'] = [$model, $fallbackModel];
                Log::debug('Using native fallback for vision', ['primary' => $model, 'fallback' => $fallbackModel]);
            } else {
                $payload['model'] = $model;
            }

            // Add provider preferences for routing optimization (OpenRouter Best Practice)
            $providerPrefs = $this->buildProviderPreferences();
            if (! empty($providerPrefs)) {
                $payload['provider'] = $providerPrefs;
            }

            $response = $this->client($apiKey)->post('/chat/completions', $payload);

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

            return $this->parseResponse($data, $model);
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
     * This method delegates to ModelCapabilityService which:
     * 1. Checks cache first (24hr TTL)
     * 2. Queries OpenRouter API if not cached
     * 3. Falls back to config (llm-models.php)
     * 4. Uses pattern-based detection as last resort
     *
     * @param string $model Model ID
     * @return bool Whether the model supports vision
     */
    public function supportsVision(string $model): bool
    {
        return app(ModelCapabilityService::class)->supportsVision($model);
    }

    /**
     * Check if a model supports reasoning (o1, o1-mini, deepseek-r1).
     *
     * Reasoning models can show their thought process through 'reasoning' field
     * in the response. The effort level (low/medium/high) controls reasoning depth.
     *
     * @param string $model Model ID
     * @return bool Whether the model supports reasoning
     */
    public function supportsReasoning(string $model): bool
    {
        $config = config("llm-models.models.{$model}");

        return $config['supports_reasoning'] ?? false;
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
     * Parse OpenRouter API response with enhanced usage tracking.
     *
     * Extracts standard response fields plus OpenRouter-specific usage details:
     * - cached_tokens: Tokens read from prompt cache (cheaper pricing)
     * - reasoning_tokens: Tokens used for reasoning (o1, deepseek-r1)
     * - cost: Actual cost charged by OpenRouter
     *
     * @param array $data Raw API response
     * @param string $requestedModel The model that was requested
     * @return array Parsed response with enhanced usage data
     */
    protected function parseResponse(array $data, string $requestedModel): array
    {
        $usage = $data['usage'] ?? [];
        $promptDetails = $usage['prompt_tokens_details'] ?? [];
        $completionDetails = $usage['completion_tokens_details'] ?? [];
        $message = $data['choices'][0]['message'] ?? [];

        return [
            'content' => $message['content'] ?? '',
            // Reasoning content from o1/deepseek-r1 models (OpenRouter Best Practice)
            'reasoning' => $message['reasoning'] ?? null,
            'model' => $data['model'] ?? $requestedModel,
            'usage' => [
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
                // Enhanced usage tracking (OpenRouter Best Practice)
                'cached_tokens' => $promptDetails['cached_tokens'] ?? 0,
                'reasoning_tokens' => $completionDetails['reasoning_tokens'] ?? 0,
                'cost' => $usage['cost'] ?? null,
            ],
            'id' => $data['id'] ?? null,
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
        ];
    }

    /**
     * Parse OpenRouter API response for tool calls.
     *
     * Similar to parseResponse() but includes tool_calls field.
     *
     * @param array $data Raw API response
     * @param string $requestedModel The model that was requested
     * @return array Parsed response with tool calls
     */
    protected function parseToolResponse(array $data, string $requestedModel): array
    {
        $response = $this->parseResponse($data, $requestedModel);

        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $finishReason = $choice['finish_reason'] ?? 'stop';

        $response['tool_calls'] = null;

        // Include tool calls if present
        if ($finishReason === 'tool_calls' && isset($message['tool_calls'])) {
            $response['tool_calls'] = $message['tool_calls'];
        }

        return $response;
    }

    /**
     * Build provider preferences for OpenRouter routing optimization.
     *
     * Returns preferences for:
     * - preferred_max_latency: Max acceptable latency in seconds
     * - preferred_min_throughput: Min tokens per second
     * - data_collection: 'allow' or 'deny' for model providers
     *
     * @return array Provider preferences (empty if none configured)
     */
    protected function buildProviderPreferences(): array
    {
        $config = config('services.openrouter.provider_preferences', []);
        $provider = [];

        if (! empty($config['preferred_max_latency'])) {
            $provider['preferred_max_latency'] = (int) $config['preferred_max_latency'];
        }

        if (! empty($config['preferred_min_throughput'])) {
            $provider['preferred_min_throughput'] = (int) $config['preferred_min_throughput'];
        }

        if (! empty($config['data_collection'])) {
            $provider['data_collection'] = $config['data_collection'];
        }

        return $provider;
    }

    /**
     * Get configured HTTP client.
     *
     * @param string|null $apiKey API key (uses default from config if not provided)
     * @param int|null $timeout Request timeout in seconds (null uses default)
     */
    protected function client(?string $apiKey = null, ?int $timeout = null): PendingRequest
    {
        $key = $apiKey ?? $this->apiKey;
        $requestTimeout = $timeout ?? $this->timeout;

        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'HTTP-Referer' => $this->siteUrl,
                'X-Title' => $this->siteName,
            ])
            ->timeout($requestTimeout)
            ->retry(3, function (int $attempt) {
                return $attempt * 200;
            }, throw: false, when: function (\Exception $e, $response) {
                return $response?->status() === 429 || $response?->status() >= 500;
            })
            ->acceptJson();
    }
}
