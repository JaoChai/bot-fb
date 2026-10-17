<?php

// Handover alert — เตือนแอดมินทาง Telegram เมื่อลูกค้ารอคำตอบนานเกินไป (handover:alert-unanswered)
return [

    /*
    | นาทีที่ลูกค้าในแชท handover รอคำตอบก่อนยิงการแจ้งเตือน
    | ข้อความล่าสุดต้องเป็นฝั่งลูกค้าและอายุอยู่ระหว่างค่านี้กับ 24 ชม.
    */
    'alert_after_minutes' => env('HANDOVER_ALERT_AFTER_MINUTES', 15),

];
