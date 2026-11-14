<?php

/*
|--------------------------------------------------------------------------
| ตามลูกค้า (Customer follow-up) — plan §3.2 (การ์ด B2a)
|--------------------------------------------------------------------------
|
| ค่าตั้งต้นของระบบตามลูกค้าที่เงียบไป ทุกบอทยังปิดอยู่ใน bot_settings
| (สวิตช์ต่อบอทเป็นของการ์ด B5) จนกว่าเจ้านายจะเปิดเอง
|
| ไฟล์นี้ไม่มี credential ใหม่: credential OpenRouter อยู่ที่ services.php,
| token LINE อยู่ที่ bot, การตั้งค่า Telegram แจ้งเตือนอยู่ที่ flow เดิม
|
*/

$services = require config_path('services.php');

// โควต้า LINE สำรวจไว้ส่ง — ขาด/ไม่ใช่จำนวนเต็ม/ไม่เป็นบวก = null (null = หยุดส่ง, D5)
// ยอมรับเฉพาะ string หรือ int เท่านั้น: env() แปลง "true" เป็น bool แล้ว bool ต้องไม่กลายเป็น 1
$quotaReserveMessages = null;
$rawReserve = env('CUSTOMER_FOLLOW_UP_LINE_RESERVE');
$parsedReserve = is_string($rawReserve) || is_int($rawReserve)
    ? filter_var($rawReserve, FILTER_VALIDATE_INT)
    : false;
if ($parsedReserve !== false && $parsedReserve > 0) {
    $quotaReserveMessages = $parsedReserve;
}

// รายชื่อบอทที่ "ส่งเองได้" ตั้งจากฝั่ง host เท่านั้น (D8) — เอาเฉพาะจำนวนเต็มบวก ที่เหลือทิ้ง
$automaticBotIds = [];
$rawAutomaticBotIds = env('CUSTOMER_FOLLOW_UP_AUTOMATIC_BOT_IDS');
if (is_string($rawAutomaticBotIds) && $rawAutomaticBotIds !== '') {
    foreach (explode(',', $rawAutomaticBotIds) as $candidateId) {
        $parsedId = filter_var(trim($candidateId), FILTER_VALIDATE_INT);
        if ($parsedId !== false && $parsedId > 0) {
            $automaticBotIds[] = $parsedId;
        }
    }
    $automaticBotIds = array_values(array_unique($automaticBotIds));
}

// ลิงก์ "เปิดห้องใน admin web" บนบัตร Telegram — ยอมรับเฉพาะ https://host[:port]
// เช็ค user/pass/path/query/fragment ทีละตัว (isset หลาย argument จะจริงเมื่อ "ครบทุกตัว" เท่านั้น);
// path เป็น "/" เดี่ยวหลัง host ให้ผ่านแล้วเก็บแบบตัด "/" ทิ้ง, path ยาวกว่านั้น = null
$adminWebUrl = null;
$rawAdminWebUrl = env('CUSTOMER_FOLLOW_UP_ADMIN_WEB_URL');
if (is_string($rawAdminWebUrl) && $rawAdminWebUrl !== '') {
    $urlParts = parse_url($rawAdminWebUrl);
    $isHttpsOrigin = is_array($urlParts)
        && ($urlParts['scheme'] ?? '') === 'https'
        && ($urlParts['host'] ?? '') !== ''
        && ! isset($urlParts['user'])
        && ! isset($urlParts['pass'])
        && ! isset($urlParts['query'])
        && ! isset($urlParts['fragment'])
        && (! isset($urlParts['path']) || $urlParts['path'] === '/');
    if ($isHttpsOrigin) {
        $adminWebUrl = isset($urlParts['port'])
            ? 'https://'.$urlParts['host'].':'.$urlParts['port']
            : 'https://'.$urlParts['host'];
    }
}

return [

    /*
    | รอบ 1: ทำงานได้กับบอท 26 เท่านั้น — ตายตัว ไม่อ่าน env
    */
    'bot_ids' => [26],

    /*
    | โมเดล Luna ฝั่งตัดสิน (sort/check) และ endpoint ของ Decisions API
    | — ใช้ URL เดิมที่ services.openrouter.decisions_url ตั้งไว้ ไม่เพิ่ม env ใหม่
    */
    'decisions_model' => 'openai/gpt-6-luna-decisions',
    'decisions_url' => $services['openrouter']['decisions_url'] ?? null,

    /*
    | หมายเหตุ D6: timeout รวมทั้งคิวงานบางส่วน ต้องเผื่อ retry 3 ครั้งใน 15 นาที
    */
    'decisions_timeout_seconds' => 10,
    'writer_timeout_seconds' => 15,
    'http_connect_timeout_seconds' => 3,
    'line_timeout_seconds' => 5,

    /*
    | ขีดจำกัดข้อความ: ร่างไม่เกิน 400 code points, สูงสุด 3 บรรทัดที่ไม่ว่าง (D7);
    | writer_max_tokens (D28): reasoning tokens ของ chat model นับรวมในงบนี้ด้วย
    | — 1024 จึงพอสำหรับร่างไทยสองประโยค (ที่ 256 เคยถูกตัด 4/36 ร่าง)
    */
    'max_text_code_points' => 400,
    'writer_max_tokens' => 1024,

    /*
    | งบร่างรายวันต่อบอท (เขตเวลากรุงเทพฯ)
    */
    'daily_draft_cap' => 10,

    /*
    | เวลาเงียบขั้นต่ำ: กลุ่มจ่ายเงิน 3 ชม. / กลุ่มขาย 24 ชม.
    */
    'payment_wait_hours' => 3,
    'sale_wait_hours' => 24,

    /*
    | ลูกค้าพิมพ์เองหลังตามกี่วันจึงถือว่าไม่ควรตามอีก (6d23:59 บล็อก / 7 วันพอดี ผ่าน)
    */
    'customer_gap_days' => 7,

    /*
    | บัตรอนุมัติใน Telegram รอได้ 12 ชม. แล้วหมดอายุ ไม่มีอะไรถูกส่ง
    */
    'approval_ttl_hours' => 12,

    /*
    | เกณฑ์ Luna: กลุ่มจ่าย/ขายมั่นใจ >= 0.8 ถึงโหมดอัตโนมัติได้;
    | คะแนน "โอกาสเสียลูกค้า" (declined) เข้าเกณฑ์ >= 0.5 ถึงควรตาม;
    | การเช็คฉบับร่าง คำตอบใช่ >= 0.5 นับเป็นเจอข้อห้าม
    */
    'automatic_min_confidence' => 0.8,
    'declined_threshold' => 0.5,
    'check_yes_threshold' => 0.5,

    /*
    | env-driven (fail-closed): ค่าตัวแปรไม่ผ่านเงื่อนไข = null ทั้งหมด
    */
    'quota_reserve_messages' => $quotaReserveMessages,
    'automatic_bot_ids' => $automaticBotIds,
    'admin_web_url' => $adminWebUrl,

];
