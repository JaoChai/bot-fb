<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Services\LineWebhook\ResponseEnvelope;
use Tests\TestCase;

class ResponseEnvelopeTest extends TestCase
{
    public function test_text_envelope(): void
    {
        $env = ResponseEnvelope::text('hello');

        $this->assertSame('text', $env->type);
        $this->assertSame('hello', $env->payload);
    }

    public function test_sticker_envelope_carries_package_and_sticker_ids(): void
    {
        $env = ResponseEnvelope::sticker('11537', '52002734');

        $this->assertSame('sticker', $env->type);
        $this->assertSame(['package_id' => '11537', 'sticker_id' => '52002734'], $env->payload);
    }

    public function test_flex_envelope_holds_array_payload(): void
    {
        $payload = ['type' => 'bubble', 'body' => []];
        $env = ResponseEnvelope::flex($payload);

        $this->assertSame('flex', $env->type);
        $this->assertSame($payload, $env->payload);
    }
}
