<?php

namespace App\Services\LineWebhook;

use App\Models\Bot;

class LineWebhookPipelineFlag
{
    public static function enabledFor(Bot $bot): bool
    {
        if (! config('line_webhook.pipeline_enabled', false)) {
            return false;
        }

        $whitelist = config('line_webhook.pipeline_bot_ids', []);
        if (empty($whitelist)) {
            return true;
        }

        return in_array((string) $bot->id, $whitelist, true);
    }
}
