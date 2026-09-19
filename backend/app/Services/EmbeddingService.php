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

    protected string $baseUrl;

    public function __construct(private OpenRouterCredentials $credentials)
    {
        $this->model = config_string('services.embeddings.model', 'openai/text-embedding-3-small');
        $this->dimensions = config_int('services.embeddings.dimensions', 1536);
        $this->baseUrl = config_string('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
    }

    public function generate(string $text): array
    {
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

            if (! $embedding || ! is_array($embedding)) {
                throw new RuntimeException('Invalid embedding response from OpenRouter');
            }

            return $embedding;
        } catch (ConnectionException $e) {
            Log::error('OpenRouter connection failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to connect to OpenRouter API: '.$e->getMessage());
        }
    }

    public function generateBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        try {
            $chunks = array_chunk($texts, 25, true);
            $embeddings = [];

            foreach ($chunks as $chunkIndex => $chunk) {
                $response = Http::withHeaders($this->getHeaders())
                    ->timeout(60)
                    ->post("{$this->baseUrl}/embeddings", [
                        'model' => $this->model,
                        'input' => array_values($chunk),
                    ]);

                if ($response->failed()) {
                    $error = $response->json('error.message', 'Unknown error');
                    Log::error('OpenRouter batch embedding failed', [
                        'status' => $response->status(),
                        'error' => $error,
                        'model' => $this->model,
                        'batch_size' => count($chunk),
                    ]);
                    throw new RuntimeException("OpenRouter API error: {$error}");
                }

                $data = $response->json('data', []);
                $offset = array_key_first($chunk);

                foreach ($data as $item) {
                    $embeddings[$offset + $item['index']] = $item['embedding'];
                }

                // Delay between chunks to avoid rate limiting (skip after last chunk)
                if ($chunkIndex < count($chunks) - 1) {
                    usleep(100_000);
                }
            }

            ksort($embeddings);

            return array_values($embeddings);
        } catch (ConnectionException $e) {
            Log::error('OpenRouter connection failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to connect to OpenRouter API: '.$e->getMessage());
        }
    }

    protected function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->credentials->key(),
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
