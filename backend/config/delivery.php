<?php

// Auto Account Delivery — ส่งบัญชีจาก stock mhha_acc_db ให้ลูกค้าอัตโนมัติ
return [
    'enabled' => (bool) env('ACCOUNT_DELIVERY_ENABLED', false),

    'remind_after_minutes' => (int) env('ACCOUNT_DELIVERY_REMIND_MINUTES', 30),

    // กันขายซ้ำข้าม dispatch path (EasySlip auto-pass vs manual confirm): บล็อกงานส่งยอดเดียวกัน
    // บน conversation เดิมที่เพิ่งสร้างภายในกี่นาที
    'dedup_window_minutes' => (int) env('ACCOUNT_DELIVERY_DEDUP_MINUTES', 30),

    // เพดานจำนวนต่อรายการ กัน summary เพี้ยน (qty สูงผิดปกติ) จองรัวจน stock หมดในงานเดียว
    'max_qty' => (int) env('ACCOUNT_DELIVERY_MAX_QTY', 20),

    // ชั้น 2 fallback: เมื่อ regex ดึง total ได้แต่ items ว่าง เรียก utility_model ช่วยดึงรายการสินค้า
    // (ยัง gated ด้วย utility_model ต้องตั้งค่าไว้ก่อนถึงจะยิงจริง)
    'llm_item_fallback_enabled' => (bool) env('DELIVERY_LLM_ITEM_FALLBACK', true),

    // ข้อความที่ส่งให้ลูกค้าแทน credential เมื่อสินค้าเป็นเพจ (PAGE)
    // {customer} = ชื่อลูกค้าจากโปรไฟล์ LINE (ไม่มีชื่อจะตัด placeholder ทิ้ง)
    'support_link_template' => env('ACCOUNT_DELIVERY_SUPPORT_TEMPLATE') ?: "รบกวนคุณพี่อ่าน แล้วทำความเข้าใจตามขั้นตอนด้านล่างด้วยนะครับ\n\nรบกวนพี่ {customer} แจ้งทีมงาน Support เพิ่มเพจให้ได้เลยพร้อมกับจำนวนที่พี่ซื้อเพจไปนะครับ\n\nLINK LINE -> https://lin.ee/sTD5TQL\n\nID LINE SUPPORT -> @743ddeqy\n\nและ ท่านพี่มีคำถามเพิ่มเติมแจ้งทีมงาน Support ได้เลยครับ",

    // ข้อความปิดท้ายเมื่อออเดอร์มีแต่บัญชี (ไม่มีเพจ) — ชี้ช่องทาง Support เรื่องบัญชี/ตั้งค่า
    'account_support_template' => env('ACCOUNT_DELIVERY_ACCOUNT_SUPPORT_TEMPLATE') ?: "เรียนท่านพี่ ที่มีปัญหา ทางด้านบัญชี หรือ ต้องการให้ทีมงานช่วยตั้งค่า และสอบถามรายละเอียดต่างๆ รบกวนพี่แจ้งทีมงาน Support ได้เลยครับ\n\nLINK LINE -> https://lin.ee/sTD5TQL\n\nID LINE SUPPORT -> @743ddeqy\n\nท่านพี่มีคำถามเพิ่มเติมแจ้งทีมงาน Support ได้เลยครับ",
];
