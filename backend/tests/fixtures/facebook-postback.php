<?php

/**
 * Canned Facebook webhook body — a postback event (quick-reply / button tap),
 * mirroring ProcessFacebookWebhook::handlePostback():
 *   $postback['payload']  (button identifier)
 *   $postback['title']    (button label)
 * The job uses title as content, falling back to payload.
 */
return [
    'object' => 'page',
    'entry' => [
        [
            'id' => 'wamid.fb.entry.0003',
            'time' => 1700000200000,
            'messaging' => [
                [
                    'sender' => ['id' => 'PSID_USER_003'],
                    'recipient' => ['id' => 'PAGE_ID_001'],
                    'timestamp' => 1700000200000,
                    'postback' => [
                        'payload' => 'BUTTON_SHOW_MENU',
                        'title' => 'ดูเมนู',
                    ],
                ],
            ],
        ],
    ],
];
