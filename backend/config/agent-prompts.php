<?php

/**
 * Language-aware Agent Prompt Templates
 *
 * Used by AgentLoopService::buildAgentSystemPrompt() to generate
 * prompts in the flow's configured language.
 */

return [
    'th' => [
        'pre_loaded_kb' => "## Pre-loaded Knowledge Base Results\n",
        'pre_loaded_kb_suffix' => "\n---\nใช้ข้อมูลด้านบนในการตอบ ใช้ search_knowledge_base เมื่อต้องการข้อมูลเพิ่มเติมเท่านั้น",
        'agent_decision_framework' => "## Agent Decision Framework\n",
        'decision_instant' => "1. ตอบได้ทันที: ทักทาย, ขอบคุณ, มีข้อมูลใน KB แล้ว ตอบเลย ไม่ต้องใช้ tool\n",
        'decision_search_with_kb' => "2. ค้นหาเพิ่ม (search_knowledge_base): ข้อมูลที่โหลดไว้ไม่พอ หรือถามเรื่องอื่น\n",
        'decision_search_no_kb' => "2. ค้นหา (search_knowledge_base): ลูกค้าถามเรื่องสินค้า ราคา นโยบาย บริการ\n",
        'decision_calculate' => "3. คำนวณ (calculate): คำนวณราคารวม/ส่วนลด\n",
        'decision_think' => "4. วิเคราะห์ (think): คำถามซับซ้อน ต้องคิดหลายขั้นตอน\n",
        'decision_datetime' => "5. วันที่/เวลา (get_current_datetime): ถามวันที่ เวลา วันในสัปดาห์\n",
        'decision_escalate' => "6. ส่งต่อเจ้าหน้าที่ (escalate_to_human): ลูกค้าต้องการคุยกับคน\n",
        'search_strategy' => "### Search Strategy\n1. ค้นด้วย keyword ตรงประเด็น (2-4 คำ)\n2. ไม่เจอ ลอง synonym/กว้างขึ้น\n3. สูงสุด 2 ครั้ง ห้ามค้นซ้ำ keyword เดิม\n",
        'response_rules' => "### Response Rules\n- ตอบจากข้อมูลที่ค้นเจอเท่านั้น ห้ามเดาหรือสร้างข้อมูลขึ้นมาเอง\n- ตอบกระชับ ไม่ต้องบอกว่า \"จากการค้นหา...\"\n- ถ้าไม่มีข้อมูลเพียงพอ ให้แนะนำลูกค้าติดต่อเจ้าหน้าที่\n",
        'truncation_note' => "[ระบบ: ข้อความก่อนหน้า %d ข้อความถูกตัดเพื่อประหยัด token]",
        'error_message' => "ขออภัยค่ะ ระบบมีปัญหาชั่วคราว กรุณาลองใหม่อีกครั้ง",
    ],

    'en' => [
        'pre_loaded_kb' => "## Pre-loaded Knowledge Base Results\n",
        'pre_loaded_kb_suffix' => "\n---\nUse the information above to respond. Only use search_knowledge_base when you need additional information.",
        'agent_decision_framework' => "## Agent Decision Framework\n",
        'decision_instant' => "1. Respond immediately: Greetings, thanks, or when KB data is sufficient — no tool needed.\n",
        'decision_search_with_kb' => "2. Search more (search_knowledge_base): Pre-loaded data is insufficient or user asks about something else.\n",
        'decision_search_no_kb' => "2. Search (search_knowledge_base): Customer asks about products, pricing, policies, or services.\n",
        'decision_calculate' => "3. Calculate (calculate): Compute totals, discounts, or prices.\n",
        'decision_think' => "4. Analyze (think): Complex questions requiring multi-step reasoning.\n",
        'decision_datetime' => "5. Date/Time (get_current_datetime): Ask about current date, time, or day of week.\n",
        'decision_escalate' => "6. Escalate (escalate_to_human): Customer wants to speak with a human agent.\n",
        'search_strategy' => "### Search Strategy\n1. Search with precise keywords (2-4 words)\n2. If not found, try synonyms or broader terms\n3. Maximum 2 searches, never repeat the same keywords\n",
        'response_rules' => "### Response Rules\n- Only respond based on retrieved information. Never guess or fabricate data.\n- Be concise. Don't say \"Based on my search...\"\n- If information is insufficient, suggest the customer contact support.\n",
        'truncation_note' => "[System: %d previous messages were truncated to save tokens]",
        'error_message' => "I apologize, a temporary error occurred. Please try again.",
    ],
];
