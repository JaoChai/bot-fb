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
     * Stream a chat completion request to OpenRouter using SSE.
     *
     * @param array $messages Chat messages
     * @param string|null $model Model ID
     * @param float|null $temperature Sampling temperature
     * @param int|null $maxTokens Maximum tokens in response
     * @param string|null $apiKeyOverride Override API key
     * @return \Generator Yields SSE-formatted chunks
     */
    public function chatStream(
        array $messages,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?string $apiKeyOverride = null
    ): \Generator {
        $model = $model ?? $this->defaultModel;
        $temperature = $temperature ?? 0.7;
        $maxTokens = $maxTokens ?? $this->maxTokens;
        $apiKey = $apiKeyOverride ?? $this->apiKey;

        $url = $this->baseUrl . '/chat/completions';

        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'stream' => true,
        ]);

        // Use cURL for streaming
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: ' . $this->siteUrl,
                'X-Title: ' . $this->siteName,
                'Accept: text/event-stream',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        // Buffer for incomplete SSE data
        $buffer = '';
        $usage = null;
        $modelUsed = $model;

        // Set up streaming callback
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$buffer) {
            $buffer .= $data;
            return strlen($data);
        });

        // Run the request
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            yield ['type' => 'error', 'data' => "Connection error: {$curlError}"];
            return;
        }

        if ($httpCode !== 200) {
            $errorData = json_decode($buffer, true);
            $errorMsg = $errorData['error']['message'] ?? "HTTP error: {$httpCode}";
            yield ['type' => 'error', 'data' => $errorMsg];
            return;
        }

        // Parse SSE data from buffer
        $lines = explode("\n", $buffer);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || str_starts_with($line, ':')) {
                continue;
            }

            // Parse SSE data line
            if (str_starts_with($line, 'data: ')) {
                $jsonData = substr($line, 6);

                // Check for stream end
                if ($jsonData === '[DONE]') {
                    break;
                }

                $data = json_decode($jsonData, true);
                if (!$data) {
                    continue;
                }

                // Extract model info
                if (isset($data['model'])) {
                    $modelUsed = $data['model'];
                }

                // Extract usage info (usually in final chunk)
                if (isset($data['usage'])) {
                    $usage = $data['usage'];
                }

                // Extract delta content
                $delta = $data['choices'][0]['delta'] ?? [];

                // Check for reasoning/thinking content
                if (isset($delta['reasoning']) || isset($delta['reasoning_content'])) {
                    $thinking = $delta['reasoning'] ?? $delta['reasoning_content'] ?? '';
                    if ($thinking) {
                        yield ['type' => 'thinking', 'data' => $thinking];
                    }
                }

                // Check for regular content
                if (isset($delta['content']) && $delta['content'] !== '') {
                    yield ['type' => 'content', 'data' => $delta['content']];
                }
            }
        }

        // Yield done event with metadata
        yield [
            'type' => 'done',
            'data' => '',
            'model' => $modelUsed,
            'usage' => $usage ?? [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];
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
