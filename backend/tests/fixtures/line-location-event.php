<?php

/**
 * Canned LINE location webhook event for the NonTextHandler characterization
 * pins (fixed values).
 */
return [
    'type' => 'message',
    'replyToken' => 'reply_token_loc_1',
    'source' => ['type' => 'user', 'userId' => 'U_loc_user'],
    'message' => [
        'id' => 'loc_msg_001',
        'type' => 'location',
        'latitude' => '13.7563',
        'longitude' => '100.5018',
        'address' => 'Bangkok',
    ],
    'webhookEventId' => 'webhook_loc_001',
    'deliveryContext' => ['isRedelivery' => false],
    'timestamp' => 1700000000000,
];
