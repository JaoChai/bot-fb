<?php

/**
 * Canned Telegram update — a private-chat text message, mirroring the real
 * Bot API update consumed by ProcessTelegramWebhook::processUpdate() via
 * TelegramService::parseUpdate():
 *   $update['message']['chat']['id']  (chat_id)
 *   $update['message']['from']        (user)
 *   $update['message']['text']
 */
return [
    'update_id' => 1001,
    'message' => [
        'message_id' => 501,
        'from' => [
            'id' => 777,
            'is_bot' => false,
            'first_name' => 'Somchai',
            'username' => 'somchai_tg',
        ],
        'chat' => [
            'id' => 777,
            'first_name' => 'Somchai',
            'username' => 'somchai_tg',
            'type' => 'private',
        ],
        'date' => 1700000000,
        'text' => 'สวัสดีครับ อยากดูสินค้า',
    ],
];
