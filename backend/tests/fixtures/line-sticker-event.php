<?php

/**
 * Canned LINE sticker webhook event — mirrors the sticker payload used by
 * tests/Unit/Jobs/ProcessLINEWebhookPipelineTest (test_non_text_event_uses_legacy_path),
 * with fixed values for the characterization pins.
 */
return [
    'type' => 'message',
    'replyToken' => 'reply_token_stk_1',
    'source' => ['type' => 'user', 'userId' => 'U_stk_user'],
    'message' => ['id' => 'stk_msg_001', 'type' => 'sticker', 'stickerId' => '999', 'packageId' => '777'],
    'webhookEventId' => 'webhook_stk_001',
    'deliveryContext' => ['isRedelivery' => false],
    'timestamp' => 1700000000000,
];
