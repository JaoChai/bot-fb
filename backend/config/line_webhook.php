<?php

return [
    'pipeline_enabled' => env('PROCESS_LINE_PIPELINE_ENABLED', false),
    'pipeline_bot_ids' => array_filter(array_map(
        'trim',
        explode(',', (string) env('PROCESS_LINE_PIPELINE_BOT_IDS', ''))
    )),
];
