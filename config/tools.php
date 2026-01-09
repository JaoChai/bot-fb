<?php

/**
 * AI Agent Tool Definitions
 *
 * Format compatible with OpenRouter/OpenAI function calling spec.
 * Each tool has: name, description, parameters (JSON Schema)
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Available Tools
    |--------------------------------------------------------------------------
    |
    | Tools that AI Agent can use when agentic_mode is enabled.
    | Add new tools here following the same structure.
    |
    */

    'search_kb' => [
        'type' => 'function',
        'function' => [
            'name' => 'search_knowledge_base',
            'description' => 'ค้นหาข้อมูลในฐานความรู้ของระบบ ใช้เมื่อต้องการข้อมูลเฉพาะทาง เช่น ข้อมูลสินค้า นโยบาย หรือคำถามที่พบบ่อย',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'คำค้นหาหรือคำถามที่ต้องการหาคำตอบ',
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ],

    'calculate' => [
        'type' => 'function',
        'function' => [
            'name' => 'calculate',
            'description' => 'คำนวณนิพจน์ทางคณิตศาสตร์ ใช้สำหรับบวก ลบ คูณ หาร เปอร์เซ็นต์ หรือคำนวณราคา',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'expression' => [
                        'type' => 'string',
                        'description' => 'นิพจน์ทางคณิตศาสตร์ เช่น "100 * 0.8", "500 + 200", "1000 - (1000 * 0.2)"',
                    ],
                ],
                'required' => ['expression'],
            ],
        ],
    ],

    'think' => [
        'type' => 'function',
        'function' => [
            'name' => 'think',
            'description' => 'ใช้เพื่อหยุดคิดและวิเคราะห์ก่อนตอบ ใช้เมื่อ: ต้องการวางแผนขั้นตอนการทำงาน, วิเคราะห์ผลลัพธ์จาก tool อื่น, คิดทบทวนก่อนตอบคำถามที่ซับซ้อน, หรือตรวจสอบความถูกต้องของข้อมูล',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'thought' => [
                        'type' => 'string',
                        'description' => 'ความคิดหรือการวิเคราะห์ที่ต้องการบันทึก',
                    ],
                ],
                'required' => ['thought'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Metadata (for UI)
    |--------------------------------------------------------------------------
    */

    '_meta' => [
        'search_kb' => [
            'icon' => 'search',
            'label' => 'ค้นหาฐานความรู้',
            'label_en' => 'Search Knowledge Base',
            'color' => 'blue',
        ],
        'calculate' => [
            'icon' => 'calculator',
            'label' => 'คำนวณ',
            'label_en' => 'Calculate',
            'color' => 'green',
        ],
        'think' => [
            'icon' => 'brain',
            'label' => 'คิดก่อนตอบ',
            'label_en' => 'Think',
            'color' => 'purple',
        ],
    ],
];
