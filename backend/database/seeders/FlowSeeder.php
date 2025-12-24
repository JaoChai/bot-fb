<?php

namespace Database\Seeders;

use App\Models\Bot;
use App\Models\Flow;
use Illuminate\Database\Seeder;

class FlowSeeder extends Seeder
{
    public function run(): void
    {
        $demoBot = Bot::where('name', 'Demo Bot')->first();

        if (!$demoBot) {
            return;
        }

        // Create default flow
        $defaultFlow = Flow::create([
            'bot_id' => $demoBot->id,
            'name' => 'Default Flow',
            'description' => 'Default conversation flow for general inquiries',
            'system_prompt' => <<<PROMPT
คุณเป็นผู้ช่วย AI ที่เป็นมิตรและช่วยเหลือดี ชื่อ "Demo Bot"

## บทบาท
- ตอบคำถามทั่วไปอย่างสุภาพและเป็นมิตร
- ใช้ภาษาไทยเป็นหลัก แต่สามารถตอบภาษาอังกฤษได้หากผู้ใช้ถามเป็นภาษาอังกฤษ
- ให้ข้อมูลที่ถูกต้องและเป็นประโยชน์

## แนวทางการตอบ
- ตอบสั้นกระชับ ไม่เกิน 2-3 ประโยค
- ใช้อีโมจิได้ตามความเหมาะสม
- หากไม่แน่ใจ ให้บอกตรงๆ ว่าไม่ทราบ
- ห้ามสร้างข้อมูลที่ไม่เป็นความจริง
PROMPT,
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 1024,
            'agentic_mode' => false,
            'language' => 'th',
            'is_default' => true,
        ]);

        // Update bot with default flow
        $demoBot->update(['default_flow_id' => $defaultFlow->id]);

        // Create customer support flow
        Flow::create([
            'bot_id' => $demoBot->id,
            'name' => 'Customer Support',
            'description' => 'Flow for handling customer support inquiries',
            'system_prompt' => <<<PROMPT
คุณเป็นพนักงานบริการลูกค้าที่มีความเชี่ยวชาญ

## บทบาท
- ช่วยแก้ไขปัญหาของลูกค้า
- ให้ข้อมูลเกี่ยวกับสินค้าและบริการ
- รับเรื่องร้องเรียนและส่งต่อให้ทีมงาน

## แนวทางการตอบ
- ถามข้อมูลเพิ่มเติมหากจำเป็น
- แสดงความเข้าใจและเห็นใจลูกค้า
- เสนอวิธีแก้ไขปัญหาที่เป็นรูปธรรม
- หากแก้ไขไม่ได้ ให้แจ้งว่าจะส่งต่อให้เจ้าหน้าที่
PROMPT,
            'model' => 'gpt-4o-mini',
            'temperature' => 0.5,
            'max_tokens' => 1024,
            'agentic_mode' => false,
            'language' => 'th',
            'is_default' => false,
        ]);

        // Create sales flow with agentic mode
        Flow::create([
            'bot_id' => $demoBot->id,
            'name' => 'Sales Assistant',
            'description' => 'AI sales assistant with product lookup capability',
            'system_prompt' => <<<PROMPT
คุณเป็นผู้ช่วยขายที่ฉลาดและเป็นมิตร

## บทบาท
- แนะนำสินค้าที่เหมาะกับความต้องการของลูกค้า
- ตอบคำถามเกี่ยวกับราคา สเปค และโปรโมชั่น
- ช่วยเปรียบเทียบสินค้า

## เครื่องมือที่ใช้ได้
- ค้นหาสินค้าจาก Knowledge Base
- ดูราคาและสต็อกสินค้า

## แนวทางการขาย
- เข้าใจความต้องการก่อนแนะนำ
- เน้นประโยชน์ที่ลูกค้าจะได้รับ
- ไม่กดดันหรือยัดเยียดสินค้า
PROMPT,
            'model' => 'gpt-4o',
            'temperature' => 0.7,
            'max_tokens' => 2048,
            'agentic_mode' => true,
            'max_tool_calls' => 5,
            'enabled_tools' => ['search_products', 'check_inventory', 'calculate_discount'],
            'language' => 'th',
            'is_default' => false,
        ]);
    }
}
