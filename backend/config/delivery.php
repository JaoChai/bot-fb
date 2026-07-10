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

    // ข้อความที่ส่งให้ลูกค้าแทน credential เมื่อสินค้าเป็นเพจ (PAGE)
    'support_link_template' => env('ACCOUNT_DELIVERY_SUPPORT_TEMPLATE') ?: "รบกวนคุณพี่อ่าน แล้วทำความเข้าใจตามขั้นตอนด้านล่างด้วยนะครับ\n\nรบกวนพี่ Support เพิ่มเพจให้ได้เลยพร้อมกับจำนวนที่พี่ซื้อเพจไปนะครับ\n\nLINK LINE -> https://lin.ee/sTD5TQL\n\nID LINE SUPPORT -> @743ddeqy\n\nท่านพี่มีคำถามเพิ่มเติมแจ้งทีมงาน Support ได้เลยครับ",
];
