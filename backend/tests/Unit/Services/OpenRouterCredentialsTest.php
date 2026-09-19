<?php

namespace Tests\Unit\Services;

use App\Exceptions\OpenRouterException;
use App\Services\OpenRouterCredentials;
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

    public function test_missing_key_is_reported_and_throws(): void
    {
        foreach ([null, ''] as $missing) {
            config(['services.openrouter.api_key' => $missing]);
            $credentials = new OpenRouterCredentials;

            $this->assertFalse($credentials->isConfigured());

            try {
                $credentials->key();
                $this->fail('Expected OpenRouterException for '.var_export($missing, true));
            } catch (OpenRouterException $e) {
                $this->assertStringContainsString('OPENROUTER_API_KEY', $e->getMessage());
                $this->assertSame(500, $e->getHttpStatus());
            }
        }
    }

    public function test_config_is_read_on_every_call_not_frozen_at_construction(): void
    {
        config(['services.openrouter.api_key' => null]);
        $credentials = new OpenRouterCredentials;
        $this->assertFalse($credentials->isConfigured());

        config(['services.openrouter.api_key' => 'synthetic-late-key']);
        $this->assertSame('synthetic-late-key', $credentials->key());
    }
}
