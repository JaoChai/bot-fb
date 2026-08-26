<?php

/**
 * Canned LINE image webhook event — verbatim copy of the imageEvent() payload
 * from tests/Feature/PipelineImageRoutingTest.php (lines 87-97), with the only
 * dynamic value (timestamp) pinned to a fixed constant.
 *
 * Shared by:
 *  - tests/pins/vision-pin.php (characterization pin, pre-extraction)
 *  - tests/Unit/Services/Webhook/LINE/VisionHandlerTest.php (characterization test)
 */
return [
    'type' => 'message',
    'replyToken' => 'reply_token_img_1',
    'source' => ['type' => 'user', 'userId' => 'U_img_user'],
    'message' => ['id' => 'img_msg_001', 'type' => 'image'],
    'webhookEventId' => 'webhook_img_001',
    'deliveryContext' => ['isRedelivery' => false],
    'timestamp' => 1700000000000,
];
