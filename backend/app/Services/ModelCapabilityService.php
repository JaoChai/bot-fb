<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ModelCapabilityService
{
    private const CACHE_TTL = 86400; // 24 hours
    private const CACHE_PREFIX = 'model_cap';
    private const API_TIMEOUT = 10; // seconds

    /**
     * Patterns that indicate vision support.
     * Used as fallback when API and config don't provide info.
     */
    private const VISION_PATTERNS = [
        // OpenAI
        'gpt-4o', 'gpt-4-vision', 'gpt-4-turbo', 'gpt-5', 'chatgpt-4o', 'o1', 'o3',
        // Anthropic
        'claude-3', 'claude-sonnet-4', 'claude-opus-4',
        // Google
        'gemini',
        // Vision-specific models
        'llava', 'cogvlm', 'qwen-vl', 'pixtral',
        // Generic patterns
        'vision', 'multimodal', 'vl-',
    ];

    protected CircuitBreakerService $circuitBreaker;

    public function __construct(CircuitBreakerService $circuitBreaker)
    {
        $this->circuitBreaker = $circuitBreaker;
    }

    /**
     * Check if a model supports vision/image input.
     *
     * Resolution priority:
     * 1. Cache
     * 2. OpenRouter API
     * 3. Config (llm-models.php)
     * 4. Pattern-based detection
     */
    public function supportsVision(string $modelId): bool
    {
        $capabilities = $this->getCapabilities($modelId);

        return $capabilities['supports_vision'] ?? false;
    }

    /**
     * Get the context length for a model.
     */
    public function getContextLength(string $modelId): int
    {
        $capabilities = $this->getCapabilities($modelId);

        return $capabilities['context_length'] ?? 4096;
    }

    /**
     * Get the max output tokens for a model.
     */
    public function getMaxOutputTokens(string $modelId): int
    {
        $capabilities = $this->getCapabilities($modelId);

        return $capabilities['max_output_tokens'] ?? 4096;
    }

    /**
     * Get pricing info for a model.
     *
     * @return array{prompt: float, completion: float}
     */
    public function getPricing(string $modelId): array
    {
        $capabilities = $this->getCapabilities($modelId);

        return [
            'prompt' => $capabilities['pricing_prompt'] ?? 0.0,
            'completion' => $capabilities['pricing_completion'] ?? 0.0,
        ];
    }

    /**
     * Get all capabilities for a model.
     *
     * @return array{
     *     model_id: string,
     *     name: string,
     *     supports_vision: bool,
     *     context_length: int,
     *     max_output_tokens: int,
     *     pricing_prompt: float,
     *     pricing_completion: float,
     *     source: string
     * }
     */
    public function getCapabilities(string $modelId): array
    {
        $normalizedId = $this->normalizeModelId($modelId);
        $cacheKey = $this->getCacheKey($normalizedId);

        // 1. Check cache first
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // 2. Try OpenRouter API
        $apiCapabilities = $this->fetchFromOpenRouter($modelId);
        if ($apiCapabilities !== null) {
            $this->setToCache($cacheKey, $apiCapabilities);
            return $apiCapabilities;
        }

        // 3. Fallback to config
        $configCapabilities = $this->getFromConfig($modelId);
        if ($configCapabilities !== null) {
            // Cache config values too (shorter TTL)
            $this->setToCache($cacheKey, $configCapabilities, 3600);
            return $configCapabilities;
        }

        // 4. Pattern-based detection as last resort
        $patternCapabilities = $this->detectFromPattern($modelId);
        // Don't cache pattern-based results for long
        $this->setToCache($cacheKey, $patternCapabilities, 1800);

        return $patternCapabilities;
    }

    /**
     * Invalidate cache for a specific model.
     */
    public function invalidateCache(string $modelId): void
    {
        $normalizedId = $this->normalizeModelId($modelId);
        $cacheKey = $this->getCacheKey($normalizedId);

        Cache::forget($cacheKey);

        Log::info('Model capability cache invalidated', ['model_id' => $modelId]);
    }

    /**
     * Warm cache for all models in config.
     *
     * @return array{success: int, failed: int}
     */
    public function warmCache(): array
    {
        $models = array_keys(config('llm-models.models', []));
        $success = 0;
        $failed = 0;

        foreach ($models as $modelId) {
            try {
                $this->getCapabilities($modelId);
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Failed to warm cache for model', [
                    'model_id' => $modelId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }

    // -------------------------------------------------------------------------
    // Protected Methods
    // -------------------------------------------------------------------------

    /**
     * Normalize model ID for cache key.
     */
    protected function normalizeModelId(string $modelId): string
    {
        // Replace slashes and special chars with underscores
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($modelId));
    }

    /**
     * Get cache key for a model.
     */
    protected function getCacheKey(string $normalizedId): string
    {
        return self::CACHE_PREFIX . ':' . $normalizedId;
    }

    /**
     * Get capabilities from cache.
     */
    protected function getFromCache(string $cacheKey): ?array
    {
        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        } catch (\Throwable $e) {
            Log::warning('Model capability cache read failed', [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Set capabilities to cache.
     */
    protected function setToCache(string $cacheKey, array $capabilities, ?int $ttl = null): void
    {
        try {
            Cache::put($cacheKey, $capabilities, $ttl ?? self::CACHE_TTL);
        } catch (\Throwable $e) {
            Log::warning('Model capability cache write failed', [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fetch model capabilities from OpenRouter API.
     */
    protected function fetchFromOpenRouter(string $modelId): ?array
    {
        return $this->circuitBreaker->execute(
            'openrouter_models',
            function () use ($modelId) {
                return $this->doFetchFromOpenRouter($modelId);
            },
            function () {
                // Fallback returns null to trigger config/pattern fallback
                return null;
            }
        );
    }

    /**
     * Actually fetch from OpenRouter API.
     */
    protected function doFetchFromOpenRouter(string $modelId): ?array
    {
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'HTTP-Referer' => config('app.url', 'https://botjao.com'),
            ])
            ->timeout(self::API_TIMEOUT)
            ->get('https://openrouter.ai/api/v1/models');

            if (! $response->successful()) {
                Log::warning('OpenRouter models API returned error', [
                    'status' => $response->status(),
                    'model_id' => $modelId,
                ]);
                return null;
            }

            $data = $response->json();
            $models = $data['data'] ?? [];

            // Find the requested model
            foreach ($models as $model) {
                if (($model['id'] ?? '') === $modelId) {
                    return $this->parseOpenRouterModel($model);
                }
            }

            // Model not found in API response
            Log::debug('Model not found in OpenRouter API', ['model_id' => $modelId]);
            return null;

        } catch (\Throwable $e) {
            Log::warning('Failed to fetch from OpenRouter API', [
                'model_id' => $modelId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Let circuit breaker handle it
        }
    }

    /**
     * Parse OpenRouter API model response.
     */
    protected function parseOpenRouterModel(array $model): array
    {
        $architecture = $model['architecture'] ?? [];
        $pricing = $model['pricing'] ?? [];

        // Check for vision capability
        $supportsVision = false;
        $modality = $architecture['modality'] ?? '';
        if (str_contains($modality, 'image') || str_contains($modality, 'vision')) {
            $supportsVision = true;
        }

        // Also check description and name
        $description = strtolower($model['description'] ?? '');
        $name = strtolower($model['name'] ?? '');
        if (str_contains($description, 'vision') || str_contains($description, 'image')) {
            $supportsVision = true;
        }
        if (str_contains($name, 'vision')) {
            $supportsVision = true;
        }

        // Also use pattern detection as backup
        if (! $supportsVision) {
            $supportsVision = $this->matchesVisionPattern($model['id'] ?? '');
        }

        return [
            'model_id' => $model['id'] ?? '',
            'name' => $model['name'] ?? '',
            'supports_vision' => $supportsVision,
            'context_length' => (int) ($model['context_length'] ?? $architecture['context_length'] ?? 4096),
            'max_output_tokens' => (int) ($architecture['max_output_tokens'] ?? $model['top_provider']['max_completion_tokens'] ?? 4096),
            'pricing_prompt' => (float) (($pricing['prompt'] ?? 0) * 1000000), // Convert to per 1M tokens
            'pricing_completion' => (float) (($pricing['completion'] ?? 0) * 1000000),
            'source' => 'api',
        ];
    }

    /**
     * Get capabilities from config (llm-models.php).
     */
    protected function getFromConfig(string $modelId): ?array
    {
        // Use array access instead of dot notation because model IDs contain slashes
        $models = config('llm-models.models', []);
        $config = $models[$modelId] ?? null;

        if ($config === null) {
            return null;
        }

        // If supports_vision is not in config, try pattern detection
        $supportsVision = $config['supports_vision'] ?? null;
        if ($supportsVision === null) {
            $supportsVision = $this->matchesVisionPattern($modelId);
        }

        return [
            'model_id' => $modelId,
            'name' => $config['name'] ?? $modelId,
            'supports_vision' => (bool) $supportsVision,
            'context_length' => (int) ($config['context_length'] ?? 4096),
            'max_output_tokens' => (int) ($config['max_output_tokens'] ?? 4096),
            'pricing_prompt' => (float) ($config['pricing_prompt'] ?? 0),
            'pricing_completion' => (float) ($config['pricing_completion'] ?? 0),
            'source' => 'config',
        ];
    }

    /**
     * Detect capabilities from model ID pattern.
     */
    protected function detectFromPattern(string $modelId): array
    {
        $supportsVision = $this->matchesVisionPattern($modelId);

        return [
            'model_id' => $modelId,
            'name' => $modelId,
            'supports_vision' => $supportsVision,
            'context_length' => 4096,
            'max_output_tokens' => 4096,
            'pricing_prompt' => 0.0,
            'pricing_completion' => 0.0,
            'source' => 'pattern',
        ];
    }

    /**
     * Check if model ID matches vision patterns.
     */
    protected function matchesVisionPattern(string $modelId): bool
    {
        $lowerModelId = strtolower($modelId);

        foreach (self::VISION_PATTERNS as $pattern) {
            if (str_contains($lowerModelId, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
