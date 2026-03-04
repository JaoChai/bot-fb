<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ModelCapabilityService
{
    private const CACHE_TTL = 86400; // 24 hours for per-model merged cache

    private const CACHE_PREFIX = 'model_cap';

    private const ALL_MODELS_CACHE_KEY = 'model_cap:all_models';

    private const ALL_MODELS_CACHE_TTL = 21600; // 6 hours

    private const API_TIMEOUT = 15; // seconds

    protected CircuitBreakerService $circuitBreaker;

    public function __construct(CircuitBreakerService $circuitBreaker)
    {
        $this->circuitBreaker = $circuitBreaker;
    }

    /**
     * Check if a model supports vision/image input.
     */
    public function supportsVision(string $modelId): bool
    {
        return $this->getCapabilities($modelId)['supports_vision'] ?? false;
    }

    /**
     * Check if a model supports reasoning (o1, deepseek-r1, gpt-5-mini, etc).
     */
    public function supportsReasoning(string $modelId): bool
    {
        return $this->getCapabilities($modelId)['supports_reasoning'] ?? false;
    }

    /**
     * Check if reasoning is mandatory (cannot be disabled).
     */
    public function isMandatoryReasoning(string $modelId): bool
    {
        return $this->getCapabilities($modelId)['is_mandatory_reasoning'] ?? false;
    }

    /**
     * Get default reasoning effort for a model.
     */
    public function getDefaultReasoningEffort(string $modelId): ?string
    {
        return $this->getCapabilities($modelId)['default_reasoning_effort'] ?? null;
    }

    /**
     * Check if a model supports structured output (JSON mode).
     */
    public function supportsStructuredOutput(string $modelId): bool
    {
        return $this->getCapabilities($modelId)['supports_structured_output'] ?? false;
    }

    /**
     * Get the context length for a model.
     */
    public function getContextLength(string $modelId): int
    {
        return $this->getCapabilities($modelId)['context_length'] ?? 4096;
    }

    /**
     * Get the max output tokens for a model.
     */
    public function getMaxOutputTokens(string $modelId): int
    {
        return $this->getCapabilities($modelId)['max_output_tokens'] ?? 4096;
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
     * Resolution priority:
     * 1. Per-model merged cache (24hr)
     * 2. OpenRouter API (via full-list cache) + config overrides merged
     * 3. Config-only (models not on OpenRouter)
     * 4. Conservative defaults
     */
    public function getCapabilities(string $modelId): array
    {
        $normalizedId = $this->normalizeModelId($modelId);
        $cacheKey = $this->getCacheKey($normalizedId);

        // 1. Check per-model merged cache
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // 2. Try API (using full-list cache) + merge config overrides
        $apiCapabilities = $this->getFromApi($modelId);
        $configOverrides = $this->getConfigOverrides($modelId);

        if ($apiCapabilities !== null) {
            $merged = $this->mergeCapabilities($apiCapabilities, $configOverrides);
            $this->setToCache($cacheKey, $merged);

            return $merged;
        }

        // 3. Config-only model (not on OpenRouter API)
        $configCapabilities = $this->getFromConfig($modelId);
        if ($configCapabilities !== null) {
            $this->setToCache($cacheKey, $configCapabilities);

            return $configCapabilities;
        }

        // 4. Conservative defaults
        $defaults = $this->getDefaults($modelId);
        $this->setToCache($cacheKey, $defaults, 1800); // Short cache for unknowns

        return $defaults;
    }

    /**
     * Get all available models (API + config-only).
     *
     * @param  string|null  $search  Filter by name, provider, or model ID
     * @return array<int, array>
     */
    public function getAvailableModels(?string $search = null): array
    {
        $allModels = [];

        // Get API models (cached 6hr)
        $apiModels = $this->fetchAllModels();
        foreach ($apiModels as $modelId => $model) {
            $configOverrides = $this->getConfigOverrides($modelId);
            $allModels[$modelId] = $this->mergeCapabilities($model, $configOverrides);
        }

        // Add config-only models (not in API)
        $configModels = config('llm-models.models', []);
        foreach ($configModels as $modelId => $config) {
            if (! isset($allModels[$modelId])) {
                $allModels[$modelId] = $this->getFromConfig($modelId);
            }
        }

        // Apply search filter
        if ($search) {
            $search = strtolower($search);
            $allModels = array_filter($allModels, function ($model) use ($search) {
                return str_contains(strtolower($model['model_id'] ?? ''), $search)
                    || str_contains(strtolower($model['name'] ?? ''), $search)
                    || str_contains(strtolower($model['provider'] ?? ''), $search)
                    || str_contains(strtolower($model['description'] ?? ''), $search);
            });
        }

        // Sort by provider then name
        $models = array_values($allModels);
        usort($models, function ($a, $b) {
            $providerCmp = strcasecmp($a['provider'] ?? '', $b['provider'] ?? '');
            if ($providerCmp !== 0) {
                return $providerCmp;
            }

            return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
        });

        return $models;
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
     * Invalidate the full model list cache.
     */
    public function invalidateAllModelsCache(): void
    {
        Cache::forget(self::ALL_MODELS_CACHE_KEY);

        Log::info('All models cache invalidated');
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

    /**
     * Warm cache by fetching all models from OpenRouter API.
     *
     * @return array{total: int, source: string}
     */
    public function warmAllModelsCache(): array
    {
        // Force refresh by invalidating first
        $this->invalidateAllModelsCache();

        $models = $this->fetchAllModels();

        return [
            'total' => count($models),
            'source' => 'api',
        ];
    }

    // -------------------------------------------------------------------------
    // Protected Methods
    // -------------------------------------------------------------------------

    /**
     * Normalize model ID for cache key.
     */
    protected function normalizeModelId(string $modelId): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($modelId));
    }

    /**
     * Get cache key for a model.
     */
    protected function getCacheKey(string $normalizedId): string
    {
        return self::CACHE_PREFIX.':'.$normalizedId;
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
     * Look up a single model from the full-list cache.
     */
    protected function getFromApi(string $modelId): ?array
    {
        $allModels = $this->fetchAllModels();

        return $allModels[$modelId] ?? null;
    }

    /**
     * Fetch all models from OpenRouter API (cached 6hr).
     *
     * @return array<string, array> Keyed by model_id
     */
    protected function fetchAllModels(): array
    {
        $cached = $this->getFromCache(self::ALL_MODELS_CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        $models = $this->circuitBreaker->execute(
            'openrouter_models',
            function () {
                return $this->doFetchAllModels();
            },
            function () {
                return [];
            }
        );

        if (! empty($models)) {
            $this->setToCache(self::ALL_MODELS_CACHE_KEY, $models, self::ALL_MODELS_CACHE_TTL);
        }

        return $models;
    }

    /**
     * Actually fetch all models from OpenRouter API.
     *
     * @return array<string, array> Keyed by model_id
     */
    protected function doFetchAllModels(): array
    {
        $apiKey = config('services.openrouter.api_key');
        if (empty($apiKey)) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'HTTP-Referer' => config('app.url', 'https://botjao.com'),
            ])
                ->timeout(self::API_TIMEOUT)
                ->get('https://openrouter.ai/api/v1/models');

            if (! $response->successful()) {
                Log::warning('OpenRouter models API returned error', [
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();
            $models = [];

            foreach ($data['data'] ?? [] as $model) {
                $parsed = $this->parseOpenRouterModel($model);
                $models[$parsed['model_id']] = $parsed;
            }

            Log::info('Fetched models from OpenRouter API', ['count' => count($models)]);

            return $models;
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch all models from OpenRouter API', [
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
        $supportedParams = $model['supported_parameters'] ?? [];

        // Vision detection
        $supportsVision = $this->detectVisionSupport($model, $architecture);

        // Reasoning detection from supported_parameters
        $supportsReasoning = in_array('reasoning', $supportedParams, true);

        // Structured output detection from supported_parameters
        $supportsStructuredOutput = in_array('structured_outputs', $supportedParams, true);

        // Extract provider from model ID
        $parts = explode('/', $model['id'] ?? '');
        $provider = $parts[0] ?? '';

        return [
            'model_id' => $model['id'] ?? '',
            'name' => $model['name'] ?? '',
            'provider' => $provider,
            'description' => $model['description'] ?? '',
            'supports_vision' => $supportsVision,
            'supports_reasoning' => $supportsReasoning,
            'is_mandatory_reasoning' => false, // Only from config override
            'default_reasoning_effort' => null, // Only from config override
            'supports_structured_output' => $supportsStructuredOutput,
            'context_length' => (int) ($model['context_length'] ?? $architecture['context_length'] ?? 4096),
            'max_output_tokens' => (int) ($architecture['max_output_tokens'] ?? $model['top_provider']['max_completion_tokens'] ?? 4096),
            'pricing_prompt' => (float) (($pricing['prompt'] ?? 0) * 1000000), // Convert to per 1M tokens
            'pricing_completion' => (float) (($pricing['completion'] ?? 0) * 1000000),
            'source' => 'api',
        ];
    }

    /**
     * Detect vision support from OpenRouter model data.
     */
    protected function detectVisionSupport(array $model, array $architecture): bool
    {
        // Priority 1: Check supports_images field
        if (isset($model['supports_images']) && $model['supports_images'] === true) {
            return true;
        }

        // Priority 2: Check input_modalities array
        $inputModalities = $architecture['input_modalities'] ?? [];
        if (in_array('image', $inputModalities, true)) {
            return true;
        }

        // Priority 3: Check modality string
        $modality = $architecture['modality'] ?? '';
        if (str_contains($modality, 'image') || str_contains($modality, 'vision')) {
            return true;
        }

        return false;
    }

    /**
     * Get config overrides for a model (fields to merge on top of API data).
     * Returns null if model is not in config.
     */
    protected function getConfigOverrides(string $modelId): ?array
    {
        $models = config('llm-models.models', []);
        $config = $models[$modelId] ?? null;

        if ($config === null) {
            return null;
        }

        // Only return fields that are explicitly set in config
        $overrides = [];

        if (isset($config['name'])) {
            $overrides['name'] = $config['name'];
        }
        if (isset($config['provider'])) {
            $overrides['provider'] = $config['provider'];
        }
        if (isset($config['description'])) {
            $overrides['description'] = $config['description'];
        }
        if (isset($config['supports_vision'])) {
            $overrides['supports_vision'] = (bool) $config['supports_vision'];
        }
        if (isset($config['supports_reasoning'])) {
            $overrides['supports_reasoning'] = (bool) $config['supports_reasoning'];
        }
        if (isset($config['is_mandatory_reasoning'])) {
            $overrides['is_mandatory_reasoning'] = (bool) $config['is_mandatory_reasoning'];
        }
        if (isset($config['default_reasoning_effort'])) {
            $overrides['default_reasoning_effort'] = $config['default_reasoning_effort'];
        }
        if (isset($config['supports_structured_output'])) {
            $overrides['supports_structured_output'] = (bool) $config['supports_structured_output'];
        }
        if (isset($config['context_length'])) {
            $overrides['context_length'] = (int) $config['context_length'];
        }
        if (isset($config['max_output_tokens'])) {
            $overrides['max_output_tokens'] = (int) $config['max_output_tokens'];
        }
        if (isset($config['pricing_prompt'])) {
            $overrides['pricing_prompt'] = (float) $config['pricing_prompt'];
        }
        if (isset($config['pricing_completion'])) {
            $overrides['pricing_completion'] = (float) $config['pricing_completion'];
        }

        return $overrides;
    }

    /**
     * Merge API data with config overrides.
     * Config values take precedence where explicitly set.
     */
    protected function mergeCapabilities(array $apiData, ?array $configOverrides): array
    {
        if ($configOverrides === null) {
            return $apiData;
        }

        $merged = array_merge($apiData, $configOverrides);
        $merged['source'] = 'api+config';

        return $merged;
    }

    /**
     * Get full capabilities from config only (for models not on OpenRouter).
     */
    protected function getFromConfig(string $modelId): ?array
    {
        $models = config('llm-models.models', []);
        $config = $models[$modelId] ?? null;

        if ($config === null) {
            return null;
        }

        return [
            'model_id' => $modelId,
            'name' => $config['name'] ?? $modelId,
            'provider' => $config['provider'] ?? explode('/', $modelId)[0] ?? '',
            'description' => $config['description'] ?? '',
            'supports_vision' => (bool) ($config['supports_vision'] ?? false),
            'supports_reasoning' => (bool) ($config['supports_reasoning'] ?? false),
            'is_mandatory_reasoning' => (bool) ($config['is_mandatory_reasoning'] ?? false),
            'default_reasoning_effort' => $config['default_reasoning_effort'] ?? null,
            'supports_structured_output' => (bool) ($config['supports_structured_output'] ?? false),
            'context_length' => (int) ($config['context_length'] ?? 4096),
            'max_output_tokens' => (int) ($config['max_output_tokens'] ?? 4096),
            'pricing_prompt' => (float) ($config['pricing_prompt'] ?? 0),
            'pricing_completion' => (float) ($config['pricing_completion'] ?? 0),
            'source' => 'config',
        ];
    }

    /**
     * Return conservative default capabilities when no data available.
     */
    protected function getDefaults(string $modelId): array
    {
        $parts = explode('/', $modelId);

        return [
            'model_id' => $modelId,
            'name' => $modelId,
            'provider' => $parts[0] ?? '',
            'description' => '',
            'supports_vision' => false,
            'supports_reasoning' => false,
            'is_mandatory_reasoning' => false,
            'default_reasoning_effort' => null,
            'supports_structured_output' => false,
            'context_length' => 4096,
            'max_output_tokens' => 4096,
            'pricing_prompt' => 0.0,
            'pricing_completion' => 0.0,
            'source' => 'default',
        ];
    }
}
