<?php

/**
 * Canned Facebook webhook body — mirrors the real Messenger payload shape
 * consumed by ProcessFacebookWebhook::processPayload():
 *   $payload['object'] === 'page',
 *   $payload['entry'][N]['messaging'][M] = a single messaging event.
 * A plain text message with sender/recipient ids.
 */
return [
    'object' => 'page',
    'entry' => [
        [
            'id' => 'wamid.fb.entry.0001',
            'time' => 1700000000000,
            'messaging' => [
                [
                    'sender' => ['id' => 'PSID_USER_001'],
                    'recipient' => ['id' => 'PAGE_ID_001'],
                    'timestamp' => 1700000000000,
                    'message' => [
                        'mid' => 'wamid.mid.fb.0001',
                        'text' => 'สวัสดีครับ อยากดูสินค้า',
                        'to' => 'PAGE_ID_001',
                        'from' => 'PSID_USER_001',
                        'app_id' => 32,
                    ],
                ],
            ],
        ],
    ],
];
