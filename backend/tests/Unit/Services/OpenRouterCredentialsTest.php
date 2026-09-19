<?php

namespace Tests\Unit\Services;

use App\Exceptions\OpenRouterException;
use App\Services\EmbeddingService;
use App\Services\OpenRouterCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenRouterCredentialsTest extends TestCase
{
    public function test_key_returns_the_configured_value(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);

        $credentials = new OpenRouterCredentials;

        $this->assertTrue($credentials->isConfigured());
        $this->assertSame('synthetic-not-a-key', $credentials->key());
    }

    /** @return array<string, array{0: ?string}> */
    public static function missingKeys(): array
    {
        return ['null' => [null], 'empty string' => ['']];
    }

    #[DataProvider('missingKeys')]
    public function test_missing_key_is_reported_and_throws(?string $missing): void
    {
        config(['services.openrouter.api_key' => $missing]);
        $credentials = new OpenRouterCredentials;

        $this->assertFalse($credentials->isConfigured());

        $this->expectException(OpenRouterException::class);
        $this->expectExceptionMessage('OPENROUTER_API_KEY');

        $credentials->key();
    }

    public function test_config_is_read_on_every_call_not_frozen_at_construction(): void
    {
        config(['services.openrouter.api_key' => null]);
        $credentials = new OpenRouterCredentials;
        $this->assertFalse($credentials->isConfigured());

        config(['services.openrouter.api_key' => 'synthetic-late-key']);
        $this->assertSame('synthetic-late-key', $credentials->key());
    }

    public function test_embedding_service_throws_when_the_key_is_missing(): void
    {
        config(['services.openrouter.api_key' => null]);

        $this->expectException(OpenRouterException::class);
        $this->expectExceptionMessage('OPENROUTER_API_KEY');

        app(EmbeddingService::class)->generate('hello');
    }
}
