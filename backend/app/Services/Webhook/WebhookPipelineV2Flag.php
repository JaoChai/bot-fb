<?php

namespace App\Services\Webhook;

use App\Models\Bot;

/**
 * Feature flag for the shared WebhookPipeline v2 path (Task 9).
 *
 * Mirrors App\Services\LineWebhook\LineWebhookPipelineFlag: a master
 * config switch plus an optional per-bot whitelist. Default OFF —
 * the legacy per-channel job paths run unless explicitly opted in.
 */
class WebhookPipelineV2Flag
{
    public static function enabledFor(Bot $bot): bool
    {
        if (! config('webhook_pipeline_v2.enabled', false)) {
            return false;
        }

        $whitelist = config('webhook_pipeline_v2.bot_ids', []);
        if (empty($whitelist)) {
            return true;
        }

        return in_array((string) $bot->id, $whitelist, true);
    }
}
