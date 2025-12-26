<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EmbeddingService
{
    protected string $model;
    protected int $dimensions;
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct()
    {
        $this->model = config('services.embeddings.model', 'openai/text-embedding-3-small');
        $this->dimensions = config('services.embeddings.dimensions', 1536);
        $this->apiKey = config('services.openrouter.api_key', '');
        $this->baseUrl = config('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
    }

    public function generate(string $text): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenRouter API key is not configured (OPENROUTER_API_KEY)');
        }

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(30)
                ->post("{$this->baseUrl}/embeddings", [
                    'model' => $this->model,
                    'input' => $text,
                ]);

            if ($response->failed()) {
                $error = $response->json('error.message', 'Unknown error');
                Log::error('OpenRouter embedding failed', [
                    'status' => $response->status(),
                    'error' => $error,
                    'model' => $this->model,
                ]);
                throw new RuntimeException("OpenRouter API error: {$error}");
            }

            $embedding = $response->json('data.0.embedding');

            if (!$embedding || !is_array($embedding)) {
                throw new RuntimeException('Invalid embedding response from OpenRouter');
            }

            return $embedding;
        } catch (ConnectionException $e) {
            Log::error('OpenRouter connection failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to connect to OpenRouter API: ' . $e->getMessage());
        }
    }

    public function generateBatch(array $texts): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenRouter API key is not configured (OPENROUTER_API_KEY)');
        }

        if (empty($texts)) {
            return [];
        }

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->timeout(60)
                ->post("{$this->baseUrl}/embeddings", [
                    'model' => $this->model,
                    'input' => $texts,
                ]);

            if ($response->failed()) {
                $error = $response->json('error.message', 'Unknown error');
                Log::error('OpenRouter batch embedding failed', [
                    'status' => $response->status(),
                    'error' => $error,
                    'model' => $this->model,
                    'batch_size' => count($texts),
                ]);
                throw new RuntimeException("OpenRouter API error: {$error}");
            }

            $data = $response->json('data', []);
            $embeddings = [];

            foreach ($data as $item) {
                $embeddings[$item['index']] = $item['embedding'];
            }

            ksort($embeddings);

            return array_values($embeddings);
        } catch (ConnectionException $e) {
            Log::error('OpenRouter connection failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to connect to OpenRouter API: ' . $e->getMessage());
        }
    }

    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'HTTP-Referer' => config('services.openrouter.site_url', config('app.url')),
            'X-Title' => config('services.openrouter.site_name', config('app.name')),
        ];
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
