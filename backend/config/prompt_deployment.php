<?php

return [
    'bot_id' => 26,
    'flow_id' => 24,
    // Paths persisted in the audit table are relative to the repository root.
    'artifact_root' => env('PROMPT_DEPLOYMENT_ARTIFACT_ROOT', base_path('resources/prompts/bot26')),
    'serving_model' => 'openai/gpt-5.6-luna',
    'reasoning_effort' => 'medium',
];
