<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Supported LLM Models
    |--------------------------------------------------------------------------
    |
    | This configuration defines all supported LLM models available through
    | OpenRouter. Each model includes metadata for display, pricing, and
    | context length information.
    |
    | Pricing is in USD per 1M tokens.
    |
    | Capability Flags:
    | - supports_vision: Can process images
    | - supports_reasoning: Has reasoning/thinking capability (o1, deepseek-r1, gpt-5-mini)
    | - is_mandatory_reasoning: Reasoning cannot be disabled (gpt-5-mini, o1)
    | - supports_structured_output: Supports response_format for JSON mode
    |
    */

    'models' => [
        /*
        |--------------------------------------------------------------------------
        | OpenAI Models
        |--------------------------------------------------------------------------
        */
        'openai/gpt-4o' => [
            'name' => 'GPT-4o',
            'provider' => 'openai',
            'context_length' => 128000,
            'max_output_tokens' => 16384,
            'supports_vision' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 2.5,
            'pricing_completion' => 10.0,
            'description' => 'Most capable OpenAI model with vision support',
        ],
        'openai/gpt-4o-mini' => [
            'name' => 'GPT-4o Mini',
            'provider' => 'openai',
            'context_length' => 128000,
            'max_output_tokens' => 16384,
            'supports_vision' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.15,
            'pricing_completion' => 0.6,
            'description' => 'Fast and cost-effective for most tasks',
        ],
        'openai/gpt-4.1-mini' => [
            'name' => 'GPT-4.1 Mini',
            'provider' => 'openai',
            'context_length' => 1047576,
            'max_output_tokens' => 32768,
            'supports_vision' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.4,
            'pricing_completion' => 1.6,
            'description' => 'Mid-range model competitive with GPT-4o at lower latency',
        ],
        'openai/gpt-4-turbo' => [
            'name' => 'GPT-4 Turbo',
            'provider' => 'openai',
            'context_length' => 128000,
            'max_output_tokens' => 4096,
            'supports_vision' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 10.0,
            'pricing_completion' => 30.0,
            'description' => 'Previous flagship model with vision support',
        ],
        'openai/gpt-5-mini' => [
            'name' => 'GPT-5 Mini',
            'provider' => 'openai',
            'context_length' => 400000,
            'max_output_tokens' => 32768,
            'supports_vision' => true,
            'supports_reasoning' => true,
            'is_mandatory_reasoning' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.25,
            'pricing_completion' => 2.0,
            'description' => 'Compact GPT-5 with mandatory reasoning for complex tasks',
        ],
        'openai/o1' => [
            'name' => 'OpenAI o1',
            'provider' => 'openai',
            'context_length' => 200000,
            'max_output_tokens' => 100000,
            'supports_vision' => true,
            'supports_reasoning' => true,
            'is_mandatory_reasoning' => true,
            'default_reasoning_effort' => 'medium',
            'supports_structured_output' => false,
            'pricing_prompt' => 15.0,
            'pricing_completion' => 60.0,
            'description' => 'Advanced reasoning model for complex tasks',
        ],
        'openai/o1-mini' => [
            'name' => 'OpenAI o1-mini',
            'provider' => 'openai',
            'context_length' => 128000,
            'max_output_tokens' => 65536,
            'supports_vision' => true,
            'supports_reasoning' => true,
            'is_mandatory_reasoning' => true,
            'default_reasoning_effort' => 'medium',
            'supports_structured_output' => false,
            'pricing_prompt' => 3.0,
            'pricing_completion' => 12.0,
            'description' => 'Efficient reasoning model',
        ],

        /*
        |--------------------------------------------------------------------------
        | Anthropic Models
        |--------------------------------------------------------------------------
        */
        'anthropic/claude-sonnet-4' => [
            'name' => 'Claude Sonnet 4',
            'provider' => 'anthropic',
            'context_length' => 200000,
            'max_output_tokens' => 16000,
            'supports_vision' => true,
            'supports_structured_output' => false,
            'pricing_prompt' => 3.0,
            'pricing_completion' => 15.0,
            'description' => 'Balanced performance and cost',
        ],
        'anthropic/claude-3.5-sonnet' => [
            'name' => 'Claude 3.5 Sonnet',
            'provider' => 'anthropic',
            'context_length' => 200000,
            'max_output_tokens' => 8192,
            'supports_vision' => true,
            'supports_structured_output' => false,
            'pricing_prompt' => 3.0,
            'pricing_completion' => 15.0,
            'description' => 'Previous generation Sonnet model',
        ],
        'anthropic/claude-3-haiku' => [
            'name' => 'Claude 3 Haiku',
            'provider' => 'anthropic',
            'context_length' => 200000,
            'max_output_tokens' => 4096,
            'supports_vision' => true,
            'supports_structured_output' => false,
            'pricing_prompt' => 0.25,
            'pricing_completion' => 1.25,
            'description' => 'Fastest and most affordable Claude model',
        ],
        'anthropic/claude-3-opus' => [
            'name' => 'Claude 3 Opus',
            'provider' => 'anthropic',
            'context_length' => 200000,
            'max_output_tokens' => 4096,
            'supports_vision' => true,
            'supports_structured_output' => false,
            'pricing_prompt' => 15.0,
            'pricing_completion' => 75.0,
            'description' => 'Most capable Claude model for complex tasks',
        ],

        /*
        |--------------------------------------------------------------------------
        | Google Models
        |--------------------------------------------------------------------------
        */
        'google/gemini-flash-1.5' => [
            'name' => 'Gemini Flash 1.5',
            'provider' => 'google',
            'context_length' => 1000000,
            'max_output_tokens' => 8192,
            'supports_vision' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.075,
            'pricing_completion' => 0.3,
            'description' => 'Ultra-fast with massive context window',
        ],
        'google/gemini-pro-1.5' => [
            'name' => 'Gemini Pro 1.5',
            'provider' => 'google',
            'context_length' => 2000000,
            'max_output_tokens' => 8192,
            'supports_vision' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 1.25,
            'pricing_completion' => 5.0,
            'description' => 'High performance with largest context window',
        ],
        'google/gemini-2.0-flash-exp' => [
            'name' => 'Gemini 2.0 Flash',
            'provider' => 'google',
            'context_length' => 1000000,
            'max_output_tokens' => 8192,
            'supports_vision' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.0,
            'pricing_completion' => 0.0,
            'description' => 'Latest experimental Gemini model (free during preview)',
        ],
        'google/gemini-2.0-flash-001' => [
            'name' => 'Gemini 2.0 Flash Stable',
            'provider' => 'google',
            'context_length' => 1000000,
            'max_output_tokens' => 8192,
            'supports_vision' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.1,
            'pricing_completion' => 0.4,
            'description' => 'Stable Gemini 2.0 Flash with vision support',
        ],
        'google/gemini-2.5-flash' => [
            'name' => 'Gemini 2.5 Flash',
            'provider' => 'google',
            'context_length' => 1048576,
            'max_output_tokens' => 65535,
            'supports_vision' => true,
            'supports_reasoning' => true,
            'is_mandatory_reasoning' => false,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.3,
            'pricing_completion' => 2.5,
            'description' => 'Advanced reasoning with optional thinking capability',
        ],
        'google/gemini-3-flash-preview' => [
            'name' => 'Gemini 3 Flash Preview',
            'provider' => 'google',
            'context_length' => 1048576,
            'max_output_tokens' => 65535,
            'supports_vision' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.5,
            'pricing_completion' => 3.0,
            'description' => 'Latest Gemini 3 Flash with advanced reasoning and vision',
        ],

        /*
        |--------------------------------------------------------------------------
        | Meta Models (Llama)
        |--------------------------------------------------------------------------
        */
        'meta-llama/llama-3.1-70b-instruct' => [
            'name' => 'Llama 3.1 70B',
            'provider' => 'meta',
            'context_length' => 131072,
            'max_output_tokens' => 4096,
            'supports_vision' => false,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.52,
            'pricing_completion' => 0.75,
            'description' => 'Open source model with strong performance',
        ],
        'meta-llama/llama-3.1-8b-instruct' => [
            'name' => 'Llama 3.1 8B',
            'provider' => 'meta',
            'context_length' => 131072,
            'max_output_tokens' => 4096,
            'supports_vision' => false,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.06,
            'pricing_completion' => 0.06,
            'description' => 'Lightweight open source model',
        ],
        'meta-llama/llama-4-scout' => [
            'name' => 'Llama 4 Scout',
            'provider' => 'meta',
            'context_length' => 327680,
            'max_output_tokens' => 16384,
            'supports_vision' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.08,
            'pricing_completion' => 0.3,
            'description' => 'Multimodal 17B MoE model for assistant tasks',
        ],

        /*
        |--------------------------------------------------------------------------
        | Mistral Models
        |--------------------------------------------------------------------------
        */
        'mistralai/mistral-large' => [
            'name' => 'Mistral Large',
            'provider' => 'mistral',
            'context_length' => 128000,
            'max_output_tokens' => 4096,
            'supports_vision' => false,
            'supports_structured_output' => true,
            'pricing_prompt' => 2.0,
            'pricing_completion' => 6.0,
            'description' => 'Most capable Mistral model',
        ],
        'mistralai/mistral-small' => [
            'name' => 'Mistral Small',
            'provider' => 'mistral',
            'context_length' => 32000,
            'max_output_tokens' => 4096,
            'supports_vision' => false,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.2,
            'pricing_completion' => 0.6,
            'description' => 'Cost-effective Mistral model',
        ],

        /*
        |--------------------------------------------------------------------------
        | DeepSeek Models
        |--------------------------------------------------------------------------
        */
        'deepseek/deepseek-chat' => [
            'name' => 'DeepSeek Chat',
            'provider' => 'deepseek',
            'context_length' => 163840,
            'max_output_tokens' => 163840,
            'supports_vision' => false,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.32,
            'pricing_completion' => 0.89,
            'description' => 'Cost-effective with strong reasoning',
        ],
        'deepseek/deepseek-r1' => [
            'name' => 'DeepSeek R1',
            'provider' => 'deepseek',
            'context_length' => 64000,
            'max_output_tokens' => 8192,
            'supports_vision' => false,
            'supports_reasoning' => true,
            'is_mandatory_reasoning' => false,
            'default_reasoning_effort' => 'medium',
            'supports_structured_output' => false,
            'pricing_prompt' => 0.55,
            'pricing_completion' => 2.19,
            'description' => 'Advanced reasoning model',
        ],

        /*
        |--------------------------------------------------------------------------
        | Qwen Models
        |--------------------------------------------------------------------------
        */
        'qwen/qwen-2.5-72b-instruct' => [
            'name' => 'Qwen 2.5 72B',
            'provider' => 'qwen',
            'context_length' => 131072,
            'max_output_tokens' => 8192,
            'supports_vision' => false,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.35,
            'pricing_completion' => 0.4,
            'description' => 'Strong multilingual support including Thai',
        ],
        'qwen/qwen3.5-plus-02-15' => [
            'name' => 'Qwen 3.5 Plus',
            'provider' => 'qwen',
            'context_length' => 1000000,
            'max_output_tokens' => 65536,
            'supports_vision' => true,
            'supports_structured_output' => true,
            'pricing_prompt' => 0.4,
            'pricing_completion' => 2.4,
            'description' => 'High-efficiency MoE model with strong multilingual support',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Models
    |--------------------------------------------------------------------------
    |
    | These are the default models used when creating new bots or when
    | no specific model is configured.
    |
    */

    'default_chat_model' => env('LLM_DEFAULT_CHAT_MODEL', 'openai/gpt-4o-mini'),
    'default_decision_model' => env('LLM_DEFAULT_DECISION_MODEL', 'openai/gpt-4o-mini'),

    /*
    |--------------------------------------------------------------------------
    | Provider Information
    |--------------------------------------------------------------------------
    |
    | Additional metadata about each provider for display purposes.
    |
    */

    'providers' => [
        'openai' => [
            'name' => 'OpenAI',
            'website' => 'https://openai.com',
        ],
        'anthropic' => [
            'name' => 'Anthropic',
            'website' => 'https://anthropic.com',
        ],
        'google' => [
            'name' => 'Google',
            'website' => 'https://ai.google.dev',
        ],
        'meta' => [
            'name' => 'Meta',
            'website' => 'https://llama.meta.com',
        ],
        'mistral' => [
            'name' => 'Mistral AI',
            'website' => 'https://mistral.ai',
        ],
        'deepseek' => [
            'name' => 'DeepSeek',
            'website' => 'https://deepseek.com',
        ],
        'qwen' => [
            'name' => 'Alibaba Qwen',
            'website' => 'https://qwenlm.github.io',
        ],
    ],
];
