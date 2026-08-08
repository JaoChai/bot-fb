<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class CorsPreflightTest extends TestCase
{
    /**
     * The chat send-message hooks attach an Idempotency-Key header, so the CORS
     * preflight must allow it or the browser blocks the request entirely.
     */
    public function test_preflight_allows_idempotency_key_header(): void
    {
        $response = $this->options('/api/bots/1/conversations/1/agent-message', [], [
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'idempotency-key',
        ]);

        $response->assertNoContent(204);

        $this->assertStringContainsString(
            'idempotency-key',
            strtolower($response->headers->get('Access-Control-Allow-Headers') ?? '')
        );
    }
}
