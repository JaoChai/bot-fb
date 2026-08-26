<?php

/**
 * Canned Telegram update — a my_chat_member update (admin/member change).
 * Has NO message/edited_message/channel_post, so parseUpdate() returns
 * type 'unknown' and the job early-returns (ignorable).
 */
return [
    'update_id' => 1003,
    'my_chat_member' => [
        'update_id' => 1003,
        'date' => 1700000200,
        'chat' => ['id' => 99999, 'type' => 'group'],
        'from' => ['id' => 777, 'is_bot' => false, 'first_name' => 'Somchai'],
        'old_chat_member' => ['status' => 'member'],
        'new_chat_member' => ['status' => 'administrator', 'can_send_messages' => true],
    ],
];
