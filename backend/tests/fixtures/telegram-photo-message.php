<?php

/**
 * Canned Telegram update — a photo message (caption + photo), mirroring the
 * real Bot API shape read by TelegramService::detectMessageType() (photo) and
 * extractFileId() (largest photo). The job maps 'photo' => 'image'.
 */
return [
    'update_id' => 1002,
    'message' => [
        'message_id' => 502,
        'from' => [
            'id' => 888,
            'is_bot' => false,
            'first_name' => 'Fah',
            'username' => 'fah_tg',
        ],
        'chat' => [
            'id' => 888,
            'first_name' => 'Fah',
            'username' => 'fah_tg',
            'type' => 'private',
        ],
        'date' => 1700000100,
        'caption' => 'รูปสินค้าครับ',
        'photo' => [
            [
                'file_id' => 'photo_small_id',
                'file_unique_id' => 'photo_small_unq',
                'width' => 128,
                'height' => 128,
                'file_size' => 2048,
            ],
            [
                'file_id' => 'photo_large_id',
                'file_unique_id' => 'photo_large_unq',
                'width' => 1024,
                'height' => 768,
                'file_size' => 204800,
            ],
        ],
    ],
];
