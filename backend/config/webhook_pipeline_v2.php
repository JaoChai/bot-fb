<?php

/*
|--------------------------------------------------------------------------
| Shared Webhook Pipeline v2 (Task 9)
|--------------------------------------------------------------------------
|
| Opt-in flag for routing webhook processing through the shared
| App\Services\Webhook\WebhookPipeline (resolve → response → send steps)
| instead of the legacy per-channel job paths.
|
| Default is OFF everywhere: when `enabled` is false, all three channel
| jobs (LINE, Facebook, Telegram) run their existing legacy paths with
| zero behavior change.
|
| Rollout: set WEBHOOK_PIPELINE_V2_ENABLED=true to enable for all bots,
| or additionally restrict to a comma-separated bot id whitelist via
| WEBHOOK_PIPELINE_V2_BOT_IDS. Rollback = set the flag back to false.
*/

return [
    'enabled' => env('WEBHOOK_PIPELINE_V2_ENABLED', false),
    'bot_ids' => array_filter(array_map(
        'trim',
        explode(',', (string) env('WEBHOOK_PIPELINE_V2_BOT_IDS', ''))
    )),
];
