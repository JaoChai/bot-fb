<?php

namespace Tests\Unit\Services\Webhook;

use App\Models\Bot;
use App\Services\Webhook\WebhookPipelineV2Flag;
use Tests\TestCase;

class WebhookPipelineV2FlagTest extends TestCase
{
    public function test_returns_false_when_master_flag_off(): void
    {
        config(['webhook_pipeline_v2.enabled' => false]);
        config(['webhook_pipeline_v2.bot_ids' => ['26']]);

        $bot = new Bot;
        $bot->id = 26;

        $this->assertFalse(WebhookPipelineV2Flag::enabledFor($bot));
    }

    public function test_returns_true_when_master_on_and_whitelist_empty(): void
    {
        config(['webhook_pipeline_v2.enabled' => true]);
        config(['webhook_pipeline_v2.bot_ids' => []]);

        $bot = new Bot;
        $bot->id = 99;

        $this->assertTrue(WebhookPipelineV2Flag::enabledFor($bot));
    }

    public function test_returns_true_when_bot_in_whitelist(): void
    {
        config(['webhook_pipeline_v2.enabled' => true]);
        config(['webhook_pipeline_v2.bot_ids' => ['26', '28']]);

        $bot = new Bot;
        $bot->id = 28;

        $this->assertTrue(WebhookPipelineV2Flag::enabledFor($bot));
    }

    public function test_returns_false_when_bot_not_in_whitelist(): void
    {
        config(['webhook_pipeline_v2.enabled' => true]);
        config(['webhook_pipeline_v2.bot_ids' => ['26']]);

        $bot = new Bot;
        $bot->id = 99;

        $this->assertFalse(WebhookPipelineV2Flag::enabledFor($bot));
    }

    public function test_defaults_to_off(): void
    {
        // Config file must ship with enabled=false so the v2 path is
        // default-off everywhere unless explicitly opted in.
        $bot = new Bot;
        $bot->id = 1;

        $this->assertFalse(WebhookPipelineV2Flag::enabledFor($bot));
    }
}
