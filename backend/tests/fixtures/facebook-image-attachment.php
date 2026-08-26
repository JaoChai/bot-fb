<?php

/**
 * Canned Facebook webhook body — an image-attachment message, mirroring the
 * shape ProcessFacebookWebhook::handleMessage() reads:
 *   $message['attachments'][0]['type']  (e.g. 'image')
 *   $message['attachments'][0]['payload']['url']
 * No message['text'] (attachment-only), so the job generates a placeholder.
 */
return [
    'object' => 'page',
    'entry' => [
        [
            'id' => 'wamid.fb.entry.0002',
            'time' => 1700000100000,
            'messaging' => [
                [
                    'sender' => ['id' => 'PSID_USER_002'],
                    'recipient' => ['id' => 'PAGE_ID_001'],
                    'timestamp' => 1700000100000,
                    'message' => [
                        'mid' => 'wamid.mid.fb.0002',
                        'to' => 'PAGE_ID_001',
                        'from' => 'PSID_USER_002',
                        'app_id' => 32,
                        'attachments' => [
                            [
                                'type' => 'image',
                                'payload' => [
                                    'url' => 'https://example.com/fb-photo.jpg',
                                    'is_valid' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
