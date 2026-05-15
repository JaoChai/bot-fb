<?php

namespace Tests\Unit\Support;

use App\Support\QueueRouter;
use Tests\TestCase;

class QueueRouterTest extends TestCase
{
    public function test_returns_webhooks_when_split_disabled(): void
    {
        config(['queue.llm_split_enabled' => false]);

        $this->assertSame('webhooks', QueueRouter::llmQueue());
    }

    public function test_returns_llm_when_split_enabled(): void
    {
        config(['queue.llm_split_enabled' => true]);

        $this->assertSame('llm', QueueRouter::llmQueue());
    }

    public function test_default_config_routes_to_webhooks(): void
    {
        $this->assertFalse(config('queue.llm_split_enabled'));
        $this->assertSame('webhooks', QueueRouter::llmQueue());
    }
}
