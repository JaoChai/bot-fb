<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Models\Bot;
use App\Services\LineWebhook\LineWebhookPipelineFlag;
use Tests\TestCase;

class LineWebhookPipelineFlagTest extends TestCase
{
    public function test_returns_false_when_master_flag_off(): void
    {
        config(['line_webhook.pipeline_enabled' => false]);
        config(['line_webhook.pipeline_bot_ids' => ['26']]);

        $bot = new Bot;
        $bot->id = 26;

        $this->assertFalse(LineWebhookPipelineFlag::enabledFor($bot));
    }

    public function test_returns_true_when_master_on_and_whitelist_empty(): void
    {
        config(['line_webhook.pipeline_enabled' => true]);
        config(['line_webhook.pipeline_bot_ids' => []]);

        $bot = new Bot;
        $bot->id = 99;

        $this->assertTrue(LineWebhookPipelineFlag::enabledFor($bot));
    }

    public function test_returns_true_when_bot_in_whitelist(): void
    {
        config(['line_webhook.pipeline_enabled' => true]);
        config(['line_webhook.pipeline_bot_ids' => ['26', '28']]);

        $bot = new Bot;
        $bot->id = 28;

        $this->assertTrue(LineWebhookPipelineFlag::enabledFor($bot));
    }

    public function test_returns_false_when_bot_not_in_whitelist(): void
    {
        config(['line_webhook.pipeline_enabled' => true]);
        config(['line_webhook.pipeline_bot_ids' => ['26']]);

        $bot = new Bot;
        $bot->id = 99;

        $this->assertFalse(LineWebhookPipelineFlag::enabledFor($bot));
    }
}
