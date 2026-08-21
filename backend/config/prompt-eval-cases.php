<?php

/*
|--------------------------------------------------------------------------
| Prompt Eval Cases
|--------------------------------------------------------------------------
|
| ชุดเคส regression test สำหรับ system prompt แชทบอทขายของ รันด้วย `php artisan
| prompt:eval` (ดู App\Services\PromptEval\PromptEvalRunner สำหรับ assertion engine)
| ทุกเคสมาจากเหตุการณ์จริงที่เคยพัง — คอมเมนต์กำกับที่มาไว้เหนือแต่ละเคส
|
| Schema ต่อเคส (key ทั้งหมด optional ยกเว้น id/label/message):
|   - id                 : string ไม่ซ้ำกัน ใช้กับ --filter
|   - label              : string อธิบายเคสภาษาไทย
|   - history            : array<int, array{sender: string, content: string}> รายการ
|                          ข้อความก่อนหน้า — มี = เดินทาง RAGService, ไม่มี = เดินทาง AIService
|   - message             : string ข้อความล่าสุดจากลูกค้า
|   - must_contain        : array<int, array<int, string>> แต่ละกลุ่มคือ OR — เจอ needle
|                          ใดใน group ก็ถือว่ากลุ่มนั้นผ่าน (ต้องผ่านทุกกลุ่ม) needle ที่ขึ้นต้น/
|                          ลงท้ายด้วย "/" ตีความเป็น regex
|   - must_not_contain    : array<int, string> ห้ามพบ needle ใดเลย
|   - expect_off_topic    : bool ต้อง trigger (true) หรือห้าม trigger (false) off-topic guardrail
|   - expect_order        : array<string, mixed> key/value ที่ต้องตรงใน order_payload
|                          (ตัดจากบล็อก [[ORDER]]...[[/ORDER]])
|
| วิธีเพิ่มเคสใหม่: เพิ่ม element ใหม่ในอาร์เรย์ด้านล่าง ใส่คอมเมนต์ระบุที่มา (conv #/วันที่/PR)
| เหนือเคส แล้วรัน `php artisan prompt:eval --filter=<id>` ยืนยันก่อน commit
|
*/

return [

    // ขอบัญชีที่สร้างพิกเซลมาแล้ว (conv #1539, 21 ส.ค.)
    [
        'id' => 'pixel_premade',
        'label' => 'ขอบัญชีที่สร้างพิกเซลมาแล้ว (conv #1539, 21 ส.ค.)',
        'message' => 'เอาตัวที่สร้างพิเซลมาแล้วครับ',
        'must_contain' => [
            ['ไม่มี'],
            ['สร้างพิกเซลเองได้', 'สร้างเองได้', 'สร้างพิกเซลเอง'],
        ],
        'must_not_contain' => ['ไม่สามารถยืนยัน', 'ยืนยันสเปก'],
    ],

    // ลูกค้าสร้างพิกเซลไม่เป็น (21 ส.ค.)
    [
        'id' => 'pixel_cannot_create',
        'label' => 'ลูกค้าสร้างพิกเซลไม่เป็น (21 ส.ค.)',
        'message' => 'สร้างพิกเซลไม่เป็นอ่ะครับ ต้องทำเองหมดเลยไหม',
        'must_contain' => [
            ['วิดีโอสอน', 'สอนทุกขั้นตอน'],
            ['Support', 'ทีมงาน'],
        ],
    ],

    // แชร์พิกเซลข้ามบัญชี (นโยบาย FB)
    [
        'id' => 'pixel_share',
        'label' => 'แชร์พิกเซลข้ามบัญชี (นโยบาย FB)',
        'message' => 'แชร์พิกเซลข้ามบัญชีได้ไหมครับ',
        'must_contain' => [
            ['ไม่ได้'],
        ],
    ],

    // ลูกค้าชมว่าถูก ไม่ใช่ขอลดราคา (21 ส.ค.)
    [
        'id' => 'price_compliment',
        'label' => 'ลูกค้าชมว่าถูก ไม่ใช่ขอลดราคา (21 ส.ค.)',
        'history' => [
            ['sender' => 'bot', 'content' => 'Nolimit Level Up+ BM แบบเติมเงิน ราคา 1,100 บาท/ตัวครับพี่'],
        ],
        'message' => 'ถูกจังครับ',
        'must_not_contain' => ['ราคาพิเศษสุด'],
    ],

    // ย้ายหลัก กำกวม ห้ามรับปากทันที (21 ส.ค.)
    [
        'id' => 'move_main_ambiguous',
        'label' => 'ย้ายหลัก กำกวม ห้ามรับปากทันที (21 ส.ค.)',
        'message' => 'ย้ายหลักให้ไหม',
        'must_contain' => [
            ['ย้ายหัว'],
            ['ของพี่เอง', 'ของพี่', 'BM ของพี่'],
        ],
    ],

    // BM+ตัวเลขไม่มีหน่วยนับ (conv #1496, 10 ส.ค.)
    [
        'id' => 'bm_ambiguous_no_unit',
        'label' => 'BM+ตัวเลขไม่มีหน่วยนับ (conv #1496, 10 ส.ค.)',
        'message' => 'Bm 5 มีไหมคะ',
        'must_contain' => [
            ['บัญชีโฆษณา'],
        ],
        'must_not_contain' => ['5,500'],
    ],

    // BM+หน่วยนับ = สั่งซื้อ ห้ามถามซ้ำ (regression คู่กับ 6)
    [
        'id' => 'bm_with_unit',
        'label' => 'BM+หน่วยนับ = สั่งซื้อ ห้ามถามซ้ำ (regression คู่กับ 6)',
        'message' => 'เอา BM 5 ตัวครับ',
        'must_contain' => [
            ['5,500'],
        ],
        'must_not_contain' => ['บัญชีโฆษณา 5 ตัวอยู่ในตัวเดียว'],
    ],

    // ขอเบิกทีหลัง ร้านไม่มีระบบฝากของ (conv #1451, 11 ส.ค.)
    [
        'id' => 'staggered_pickup',
        'label' => 'ขอเบิกทีหลัง ร้านไม่มีระบบฝากของ (conv #1451, 11 ส.ค.)',
        'history' => [
            ['sender' => 'user', 'content' => 'nolimit personal 3 ตัวครับ'],
            ['sender' => 'bot', 'content' => 'รับทราบครับพี่ Nolimit Level Up+ Personal 3 ตัว รวม 3,300 บาทครับ ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลย'],
        ],
        'message' => 'ผมจ่ายไป 3 แต่จะขอเบิกแค่ 1 ก่อนนะครับ',
        'must_contain' => [
            ['เท่าที่จะรับ', 'จ่ายเท่าที่', 'ราคาเท่ากัน'],
        ],
        'must_not_contain' => ['เก็บไว้เบิก', 'ฝากไว้', 'ทยอยเบิกได้'],
    ],

    // ทยอยส่งก็ได้ = ไม่ใช่ขอฝากของ (conv #191) — history เหมือนเคส staggered_pickup
    [
        'id' => 'staggered_regression_partial_ship',
        'label' => 'ทยอยส่งก็ได้ = ไม่ใช่ขอฝากของ (conv #191)',
        'history' => [
            ['sender' => 'user', 'content' => 'nolimit personal 3 ตัวครับ'],
            ['sender' => 'bot', 'content' => 'รับทราบครับพี่ Nolimit Level Up+ Personal 3 ตัว รวม 3,300 บาทครับ ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลย'],
        ],
        'message' => 'ตัวไหนส่งก่อนได้ ทยอยส่งมาก็ได้นะครับ',
        'must_not_contain' => ['แยกออเดอร์', 'จ่ายเท่าที่จะรับ'],
    ],

    // สั่ง 20 ตัว จำนวนต้องไม่หาย (2b3611c, 20 ก.ค.)
    [
        'id' => 'qty_bulleted_20',
        'label' => 'สั่ง 20 ตัว จำนวนต้องไม่หาย (2b3611c, 20 ก.ค.)',
        'message' => 'เอา G3D 20 ตัวครับ',
        'must_contain' => [
            ['20'],
            ['1,000'],
        ],
    ],

    // ห้ามมีบรรทัด Page = 0 ในใบสรุป (PR #235, 13 ก.ค.)
    [
        'id' => 'phantom_page_line',
        'label' => 'ห้ามมีบรรทัด Page = 0 ในใบสรุป (PR #235, 13 ก.ค.)',
        'history' => [
            ['sender' => 'user', 'content' => 'เอา Nolimit BM 1 ตัว แบบผูกบัตรครับ'],
            ['sender' => 'bot', 'content' => 'กัปตันแอด ขอเช็คความถูกต้องอีกครั้งนะครับ: Nolimit Level Up+ BM (ผูกบัตร) x 1 รวม: 1,100 บาท ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลย'],
            ['sender' => 'user', 'content' => 'ยืนยัน'],
            ['sender' => 'bot', 'content' => 'ขอแจ้งให้ทราบก่อนนะครับพี่ ช่วงนี้ทีม Support อาจใช้เวลาซัพพอร์ตนานกว่าปกติหน่อยครับ ถ้าพี่รับเงื่อนไขตรงนี้ได้ ผมจะดำเนินการจำหน่ายให้ครับผม'],
            ['sender' => 'user', 'content' => 'ตกลง'],
            ['sender' => 'bot', 'content' => 'ก่อนดำเนินการชำระเงิน ขอให้ลูกค้าอ่านข้อตกลงการใช้บริการครับ https://mhhacoursecontent.my.canva.site/ads-vance กรุณาพิมพ์คำว่า "ยอมรับ" เท่านั้น หลังอ่านจบ'],
        ],
        'message' => 'ยอมรับ',
        'must_contain' => [
            ['1,100'],
            ['223-3-24880-3'],
        ],
        'must_not_contain' => ['Page = 0', 'บริการเสริม Page = 0'],
    ],

    // บล็อก [[ORDER]] ต้องมีและยอดตรง (10 ส.ค.) — history เหมือนเคส phantom_page_line
    // แต่เปลี่ยนจำนวนเป็น 2 ตัว และยอดเป็น 2,200 ทุกที่ที่ปรากฏ
    [
        'id' => 'order_payload_total',
        'label' => 'บล็อก [[ORDER]] ต้องมีและยอดตรง (10 ส.ค.)',
        'history' => [
            ['sender' => 'user', 'content' => 'เอา Nolimit BM 2 ตัว แบบผูกบัตรครับ'],
            ['sender' => 'bot', 'content' => 'กัปตันแอด ขอเช็คความถูกต้องอีกครั้งนะครับ: Nolimit Level Up+ BM (ผูกบัตร) x 2 รวม: 2,200 บาท ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลย'],
            ['sender' => 'user', 'content' => 'ยืนยัน'],
            ['sender' => 'bot', 'content' => 'ขอแจ้งให้ทราบก่อนนะครับพี่ ช่วงนี้ทีม Support อาจใช้เวลาซัพพอร์ตนานกว่าปกติหน่อยครับ ถ้าพี่รับเงื่อนไขตรงนี้ได้ ผมจะดำเนินการจำหน่ายให้ครับผม'],
            ['sender' => 'user', 'content' => 'ตกลง'],
            ['sender' => 'bot', 'content' => 'ก่อนดำเนินการชำระเงิน ขอให้ลูกค้าอ่านข้อตกลงการใช้บริการครับ https://mhhacoursecontent.my.canva.site/ads-vance กรุณาพิมพ์คำว่า "ยอมรับ" เท่านั้น หลังอ่านจบ'],
        ],
        'message' => 'ยอมรับ',
        'expect_order' => ['total' => 2200],
    ],

    // ห้ามยืนยันเงินเข้าเอง (Step 5) — history เหมือนเคส phantom_page_line
    [
        'id' => 'slip_no_self_confirm',
        'label' => 'ห้ามยืนยันเงินเข้าเอง (Step 5)',
        'history' => [
            ['sender' => 'user', 'content' => 'เอา Nolimit BM 1 ตัว แบบผูกบัตรครับ'],
            ['sender' => 'bot', 'content' => 'กัปตันแอด ขอเช็คความถูกต้องอีกครั้งนะครับ: Nolimit Level Up+ BM (ผูกบัตร) x 1 รวม: 1,100 บาท ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลย'],
            ['sender' => 'user', 'content' => 'ยืนยัน'],
            ['sender' => 'bot', 'content' => 'ขอแจ้งให้ทราบก่อนนะครับพี่ ช่วงนี้ทีม Support อาจใช้เวลาซัพพอร์ตนานกว่าปกติหน่อยครับ ถ้าพี่รับเงื่อนไขตรงนี้ได้ ผมจะดำเนินการจำหน่ายให้ครับผม'],
            ['sender' => 'user', 'content' => 'ตกลง'],
            ['sender' => 'bot', 'content' => 'ก่อนดำเนินการชำระเงิน ขอให้ลูกค้าอ่านข้อตกลงการใช้บริการครับ https://mhhacoursecontent.my.canva.site/ads-vance กรุณาพิมพ์คำว่า "ยอมรับ" เท่านั้น หลังอ่านจบ'],
        ],
        'message' => 'โอนแล้วครับ 1,100 บาท',
        'must_not_contain' => ['เงินเข้าแล้ว', 'ยืนยันชำระเงิน'],
    ],

    // off-topic guardrail (20 ส.ค.)
    [
        'id' => 'offtopic_translate',
        'label' => 'off-topic guardrail (20 ส.ค.)',
        'message' => 'แปลประโยคนี้เป็นอังกฤษหน่อยครับ: สวัสดีครับ วันนี้อากาศดี',
        'expect_off_topic' => true,
    ],

    // ถามราคา ห้ามโดน guardrail บล็อก (regression คู่กับ 14)
    [
        'id' => 'sanity_price_question',
        'label' => 'ถามราคา ห้ามโดน guardrail บล็อก (regression คู่กับ 14)',
        'message' => 'BM ราคาเท่าไหร่ครับ',
        'expect_off_topic' => false,
        'must_contain' => [
            ['1,100'],
        ],
    ],

    // ห้ามรับปาก NOVAT (25 มิ.ย.)
    [
        'id' => 'vat_no_promise',
        'label' => 'ห้ามรับปาก NOVAT (25 มิ.ย.)',
        'message' => 'มีเฟสที่ไม่ต้องเสีย VAT ไหมครับ',
        'must_contain' => [
            ['ไม่ได้', 'เลี่ยงไม่ได้', 'บังคับ'],
        ],
        'must_not_contain' => ['แจ้ง Support ทำ NOVAT', 'ทำ NOVAT ให้ได้'],
    ],

    // ข้อมูลผู้เสียภาษีไม่บังคับ (19 ส.ค.)
    [
        'id' => 'tax_id_optional',
        'label' => 'ข้อมูลผู้เสียภาษีไม่บังคับ (19 ส.ค.)',
        'message' => 'ตอนผูกบัตรขึ้นให้กรอกข้อมูลผู้เสียภาษี ไม่กรอกได้ไหมครับ',
        'must_contain' => [
            ['ไม่บังคับ', 'ไม่กรอกก็'],
        ],
        'must_not_contain' => ['ยิงแอดไม่ได้', 'จะมีปัญหา'],
    ],

    // ห้ามเชียร์ BM เพราะเรื่องเพจ (10 ก.ค.)
    [
        'id' => 'page_no_bm_push',
        'label' => 'ห้ามเชียร์ BM เพราะเรื่องเพจ (10 ก.ค.)',
        'message' => 'ซื้อเพจมาใช้กับ Personal ได้ไหมครับ',
        'must_contain' => [
            ['ได้'],
        ],
        'must_not_contain' => ['ต้องมี BM', 'แนะนำซื้อ BM'],
    ],

    // เฟสไก่สร้างเพจไม่ได้ (10 ก.ค.)
    [
        'id' => 'g3d_cannot_create_page',
        'label' => 'เฟสไก่สร้างเพจไม่ได้ (10 ก.ค.)',
        'message' => 'เฟสไก่สร้างเพจได้ไหมครับ',
        'must_contain' => [
            ['ไม่ได้'],
        ],
    ],

    // ห้ามแต่งราคา (VIP anti-hallucination)
    [
        'id' => 'price_anti_hallucination',
        'label' => 'ห้ามแต่งราคา (VIP anti-hallucination)',
        'message' => 'Nolimit Personal ราคาเท่าไหร่ครับ',
        'must_contain' => [
            ['1,100'],
        ],
        'must_not_contain' => ['800 บาท', '900 บาท', '1,200', '1,500'],
    ],

];
