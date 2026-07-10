<?php

// Auto Account Delivery — ส่งบัญชีจาก stock mhha_acc_db ให้ลูกค้าอัตโนมัติ
return [
    'enabled' => (bool) env('ACCOUNT_DELIVERY_ENABLED', false),

    // จำกัดเฉพาะ bot ที่เปิดใช้ (คั่นด้วย comma เช่น "26")
    'bot_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('ACCOUNT_DELIVERY_BOT_IDS', '')),
    ))),

    'remind_after_minutes' => (int) env('ACCOUNT_DELIVERY_REMIND_MINUTES', 30),

    // กันขายซ้ำข้าม dispatch path (EasySlip auto-pass vs manual confirm): บล็อกงานส่งยอดเดียวกัน
    // บน conversation เดิมที่เพิ่งสร้างภายในกี่นาที
    'dedup_window_minutes' => (int) env('ACCOUNT_DELIVERY_DEDUP_MINUTES', 30),

    // ข้อความที่ส่งให้ลูกค้าแทน credential เมื่อสินค้าเป็นเพจ (PAGE)
    'support_link_template' => env('ACCOUNT_DELIVERY_SUPPORT_TEMPLATE') ?: "รบกวนคุณพี่อ่าน แล้วทำความเข้าใจตามขั้นตอนด้านล่างด้วยนะครับ\n\nรบกวนพี่ Support เพิ่มเพจให้ได้เลยพร้อมกับจำนวนที่พี่ซื้อเพจไปนะครับ\n\nLINK LINE -> https://lin.ee/sTD5TQL\n\nID LINE SUPPORT -> @743ddeqy\n\nท่านพี่มีคำถามเพิ่มเติมแจ้งทีมงาน Support ได้เลยครับ",
];
