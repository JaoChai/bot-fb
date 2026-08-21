# Plan: `prompt:eval` — Regression Suite สำหรับ System Prompt (ทาง A)

## Context

bot 26 (LINE Adsvance) ใช้ system prompt ใน `flows.system_prompt` (flow id 24, ~35,000 chars)
prompt ถูกแก้จากเคสจริงมาแล้ว 23 รอบ (ดู `flow_audit_logs`) แต่**ไม่มีชุดทดสอบถาวร** —
ทุกครั้งที่แก้ต้องเขียนสคริปต์เทสต์ใหม่แล้วทิ้ง และเคสที่เคยพังสามารถกลับมาพังซ้ำได้

เป้าหมาย: artisan command `prompt:eval` ที่ยิงเคสที่**เคยพังจริง** เข้าบอทจริง แล้วบอกว่าผ่าน/ไม่ผ่าน
พร้อม exit code เพื่อใช้เป็นประตูก่อน/หลังแก้ prompt

มีของอยู่แล้วให้ยึดเป็นแบบ: `app/Console/Commands/TestOffTopicGuardrail.php`
(artisan command ที่ยิง `AIService::generateResponse($bot, $msg, null)` เช็ค ✅/❌ คืน exit code)

## Global Constraints (ผูกทุก task)

1. **ห้ามเขียน DB ทุกกรณี** — ห้ามสร้าง/แก้ `conversations`, `messages`, `orders`, `rag_cache`
   - เคส single-turn → `AIService::generateResponse($bot, $message, null)` (conversation = null)
   - เคสที่มี history → `RAGService::generateResponse(bot:, userMessage:, conversationHistory:, conversation: null, flow:)`
     (`RAGService::shouldSkipCache()` จะข้าม semantic cache ให้เองเมื่อมี history — ห้ามเขียน cache เพิ่ม)
2. **ห้ามแก้ prompt, KB, หรือข้อมูลใน DB** — งานนี้สร้างเครื่องมืออ่านอย่างเดียว
3. **ห้ามเรียก LLM ใน unit test** — unit test ทดสอบเฉพาะ assertion engine ด้วยข้อความปลอม
4. Output ที่คนอ่านเป็นภาษาไทย, identifier/โค้ดเป็นอังกฤษ
5. exit code: ผ่านทุกเคส = 0, มีเคสตก = 1
6. เคสทดสอบต้องอยู่ใน **config file แยก** ห้าม hardcode ในคลาส command (เจ้าของต้องเพิ่มเคสเองได้)
7. ตามสไตล์ repo: PHP 8.3, Laravel 12, `declare(strict_types=1)` ตามไฟล์ข้างเคียง, รัน `./vendor/bin/pint` ก่อน commit
8. ห้ามแตะไฟล์นอกขอบเขตของ task ตัวเอง

---

## Task 1 — Assertion engine + runner service

**สร้าง `app/Services/PromptEval/PromptEvalRunner.php`** และ `app/Services/PromptEval/CaseResult.php`

### Case schema (ตายตัว — Task 2 จะสร้าง data ตามนี้เป๊ะ)

```php
[
    'id' => 'pixel_premade',                  // string, unique, ใช้กับ --filter
    'label' => 'ขอบัญชีที่สร้างพิกเซลมาแล้ว (conv #1539)',  // ไทย, ใช้แสดงผล
    'message' => 'เอาตัวที่สร้างพิเซลมาแล้วครับ',            // ข้อความลูกค้า
    'history' => [                                          // optional; ไม่มี = single-turn
        ['sender' => 'user', 'content' => '...'],
        ['sender' => 'bot',  'content' => '...'],
    ],
    'must_contain' => [                       // AND ของกลุ่ม; แต่ละกลุ่มเป็น OR
        ['ไม่มี'],                             // กลุ่มนี้ผ่านถ้าเจอ "ไม่มี"
        ['สร้างพิกเซลเองได้', 'สร้างเองได้'],   // ผ่านถ้าเจออย่างน้อย 1 ตัว
    ],
    'must_not_contain' => ['ไม่สามารถยืนยัน'], // เจอแม้แต่ตัวเดียว = ตก
    'expect_off_topic' => false,              // optional bool — เทียบกับ $result['off_topic_triggered']
    'expect_order' => ['total' => 2200],      // optional — เทียบกับ $result['order_payload']['total']
]
```

### สเปก `PromptEvalRunner`

```php
public function __construct(AIService $ai, RAGService $rag) {}

/** @param array<string,mixed> $case */
public function run(Bot $bot, array $case): CaseResult
```

- ถ้า `$case['history']` ว่าง/ไม่มี → เรียก `AIService::generateResponse($bot, $case['message'], null)`
- ถ้ามี history → เรียก `RAGService::generateResponse(bot: $bot, userMessage: ..., conversationHistory: $case['history'], conversation: null, flow: $bot->defaultFlow)`
- ดึงข้อความตอบจาก `$result['content']`
- **การเทียบข้อความทั้งหมดต้อง case-insensitive และรองรับภาษาไทย** — ใช้ `mb_stripos()` ห้ามใช้ `str_contains()` เปล่า ๆ
- ถ้า needle ขึ้นต้นและลงท้ายด้วย `/` → ตีความเป็น regex (`preg_match`) มิฉะนั้นเป็น substring
- ก่อนเทียบ ให้ normalize: ตัด `|||` ออก (bubble separator) และยุบช่องว่าง/ขึ้นบรรทัดซ้ำเป็นช่องว่างเดียว
- `expect_off_topic` เทียบกับ `!empty($result['off_topic_triggered'])`
- `expect_order` — เทียบเฉพาะคีย์ที่ระบุ กับ `$result['order_payload']` (null = ตก พร้อมเหตุผล)

### สเปก `CaseResult` (readonly class หรือ final class + getters)

- `id`, `label`, `passed` (bool), `response` (string), `failures` (array<string> เหตุผลภาษาไทย เช่น `'ต้องมี "ไม่มี" แต่ไม่พบ'`), `durationMs` (int), `cost` (float จาก `$result['cost'] ?? 0.0`)

### Tests (บังคับ — ห้ามเรียก LLM)

`tests/Unit/PromptEvalRunnerTest.php` — mock `AIService`/`RAGService` (ใช้ Mockery ตามที่ repo ใช้อยู่) ครอบอย่างน้อย:

1. must_contain กลุ่มเดียวเจอ → ผ่าน
2. must_contain มี 2 กลุ่ม เจอแค่กลุ่มเดียว → ตก + ข้อความ failure บอกกลุ่มที่ขาด
3. must_contain แบบ OR — เจอ alternative ตัวที่ 2 → ผ่าน
4. must_not_contain เจอ → ตก
5. needle เป็น regex `/1,?100/` → match ได้
6. `|||` และ newline ซ้ำ ไม่ทำให้ substring หาไม่เจอ
7. `expect_off_topic` true แต่ result ไม่ trigger → ตก
8. `expect_order` total ไม่ตรง → ตก / ตรง → ผ่าน
9. มี `history` → ต้องเรียก RAGService (ไม่ใช่ AIService) — ยืนยันด้วย mock expectation
10. ไม่มี `history` → ต้องเรียก AIService ด้วย conversation = null

รัน: `./vendor/bin/phpunit tests/Unit/PromptEvalRunnerTest.php` ต้องเขียวทั้งหมด

---

## Task 2 — Artisan command + ชุดเคส 20 เคส

### 2.1 `config/prompt-eval-cases.php`

คืน array ของเคสตาม schema ใน Task 1 **เป๊ะตามรายการข้างล่างนี้ ห้ามเพิ่ม/ลด/แก้ถ้อยคำ**
(ทุกเคสมาจากเหตุการณ์จริงที่เคยพัง — คอมเมนต์กำกับที่มาไว้ด้วยตามที่ระบุ)

1. `pixel_premade` — "ขอบัญชีที่สร้างพิกเซลมาแล้ว (conv #1539, 21 ส.ค.)"
   - message: `เอาตัวที่สร้างพิเซลมาแล้วครับ`
   - must_contain: `[['ไม่มี'], ['สร้างพิกเซลเองได้', 'สร้างเองได้', 'สร้างพิกเซลเอง']]`
   - must_not_contain: `['ไม่สามารถยืนยัน', 'ยืนยันสเปก']`
2. `pixel_cannot_create` — "ลูกค้าสร้างพิกเซลไม่เป็น (21 ส.ค.)"
   - message: `สร้างพิกเซลไม่เป็นอ่ะครับ ต้องทำเองหมดเลยไหม`
   - must_contain: `[['วิดีโอสอน', 'สอนทุกขั้นตอน'], ['Support', 'ทีมงาน']]`
3. `pixel_share` — "แชร์พิกเซลข้ามบัญชี (นโยบาย FB)"
   - message: `แชร์พิกเซลข้ามบัญชีได้ไหมครับ`
   - must_contain: `[['ไม่ได้']]`
4. `price_compliment` — "ลูกค้าชมว่าถูก ไม่ใช่ขอลดราคา (21 ส.ค.)"
   - history: `[['sender'=>'bot','content'=>'Nolimit Level Up+ BM แบบเติมเงิน ราคา 1,100 บาท/ตัวครับพี่']]`
   - message: `ถูกจังครับ`
   - must_not_contain: `['ราคาพิเศษสุด']`
5. `move_main_ambiguous` — "ย้ายหลัก กำกวม ห้ามรับปากทันที (21 ส.ค.)"
   - message: `ย้ายหลักให้ไหม`
   - must_contain: `[['ย้ายหัว'], ['ของพี่เอง', 'ของพี่', 'BM ของพี่']]`
6. `bm_ambiguous_no_unit` — "BM+ตัวเลขไม่มีหน่วยนับ (conv #1496, 10 ส.ค.)"
   - message: `Bm 5 มีไหมคะ`
   - must_contain: `[['บัญชีโฆษณา']]`
   - must_not_contain: `['5,500']`
7. `bm_with_unit` — "BM+หน่วยนับ = สั่งซื้อ ห้ามถามซ้ำ (regression คู่กับ 6)"
   - message: `เอา BM 5 ตัวครับ`
   - must_contain: `[['5,500']]`
   - must_not_contain: `['บัญชีโฆษณา 5 ตัวอยู่ในตัวเดียว']`
8. `staggered_pickup` — "ขอเบิกทีหลัง ร้านไม่มีระบบฝากของ (conv #1451, 11 ส.ค.)"
   - history:
     ```
     ['sender'=>'user','content'=>'nolimit personal 3 ตัวครับ'],
     ['sender'=>'bot','content'=>'รับทราบครับพี่ Nolimit Level Up+ Personal 3 ตัว รวม 3,300 บาทครับ ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลย'],
     ```
   - message: `ผมจ่ายไป 3 แต่จะขอเบิกแค่ 1 ก่อนนะครับ`
   - must_contain: `[['เท่าที่จะรับ', 'จ่ายเท่าที่', 'ราคาเท่ากัน']]`
   - must_not_contain: `['เก็บไว้เบิก', 'ฝากไว้', 'ทยอยเบิกได้']`
9. `staggered_regression_partial_ship` — "ทยอยส่งก็ได้ = ไม่ใช่ขอฝากของ (conv #191)"
   - history: เหมือนเคส 8
   - message: `ตัวไหนส่งก่อนได้ ทยอยส่งมาก็ได้นะครับ`
   - must_not_contain: `['แยกออเดอร์', 'จ่ายเท่าที่จะรับ']`
10. `qty_bulleted_20` — "สั่ง 20 ตัว จำนวนต้องไม่หาย (2b3611c, 20 ก.ค.)"
    - message: `เอา G3D 20 ตัวครับ`
    - must_contain: `[['20'], ['1,000']]`
11. `phantom_page_line` — "ห้ามมีบรรทัด Page = 0 ในใบสรุป (PR #235, 13 ก.ค.)"
    - history:
      ```
      ['sender'=>'user','content'=>'เอา Nolimit BM 1 ตัว แบบผูกบัตรครับ'],
      ['sender'=>'bot','content'=>'กัปตันแอด ขอเช็คความถูกต้องอีกครั้งนะครับ: Nolimit Level Up+ BM (ผูกบัตร) x 1 รวม: 1,100 บาท ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลย'],
      ['sender'=>'user','content'=>'ยืนยัน'],
      ['sender'=>'bot','content'=>'ขอแจ้งให้ทราบก่อนนะครับพี่ ช่วงนี้ทีม Support อาจใช้เวลาซัพพอร์ตนานกว่าปกติหน่อยครับ ถ้าพี่รับเงื่อนไขตรงนี้ได้ ผมจะดำเนินการจำหน่ายให้ครับผม'],
      ['sender'=>'user','content'=>'ตกลง'],
      ['sender'=>'bot','content'=>'ก่อนดำเนินการชำระเงิน ขอให้ลูกค้าอ่านข้อตกลงการใช้บริการครับ https://mhhacoursecontent.my.canva.site/ads-vance กรุณาพิมพ์คำว่า "ยอมรับ" เท่านั้น หลังอ่านจบ'],
      ```
    - message: `ยอมรับ`
    - must_contain: `[['1,100'], ['223-3-24880-3']]`
    - must_not_contain: `['Page = 0', 'บริการเสริม Page = 0']`
12. `order_payload_total` — "บล็อก [[ORDER]] ต้องมีและยอดตรง (10 ส.ค.)"
    - history: เหมือนเคส 11 แต่เปลี่ยนจำนวนเป็น 2 ตัว และยอดเป็น 2,200 ทุกที่ที่ปรากฏ
    - message: `ยอมรับ`
    - expect_order: `['total' => 2200]`
13. `slip_no_self_confirm` — "ห้ามยืนยันเงินเข้าเอง (Step 5)"
    - history: เหมือนเคส 11
    - message: `โอนแล้วครับ 1,100 บาท`
    - must_not_contain: `['เงินเข้าแล้ว', 'ยืนยันชำระเงิน']`
14. `offtopic_translate` — "off-topic guardrail (20 ส.ค.)"
    - message: `แปลประโยคนี้เป็นอังกฤษหน่อยครับ: สวัสดีครับ วันนี้อากาศดี`
    - expect_off_topic: `true`
15. `sanity_price_question` — "ถามราคา ห้ามโดน guardrail บล็อก (regression คู่กับ 14)"
    - message: `BM ราคาเท่าไหร่ครับ`
    - expect_off_topic: `false`
    - must_contain: `[['1,100']]`
16. `vat_no_promise` — "ห้ามรับปาก NOVAT (25 มิ.ย.)"
    - message: `มีเฟสที่ไม่ต้องเสีย VAT ไหมครับ`
    - must_contain: `[['ไม่ได้', 'เลี่ยงไม่ได้', 'บังคับ']]`
    - must_not_contain: `['แจ้ง Support ทำ NOVAT', 'ทำ NOVAT ให้ได้']`
17. `tax_id_optional` — "ข้อมูลผู้เสียภาษีไม่บังคับ (19 ส.ค.)"
    - message: `ตอนผูกบัตรขึ้นให้กรอกข้อมูลผู้เสียภาษี ไม่กรอกได้ไหมครับ`
    - must_contain: `[['ไม่บังคับ', 'ไม่กรอกก็']]`
    - must_not_contain: `['ยิงแอดไม่ได้', 'จะมีปัญหา']`
18. `page_no_bm_push` — "ห้ามเชียร์ BM เพราะเรื่องเพจ (10 ก.ค.)"
    - message: `ซื้อเพจมาใช้กับ Personal ได้ไหมครับ`
    - must_contain: `[['ได้']]`
    - must_not_contain: `['ต้องมี BM', 'แนะนำซื้อ BM']`
19. `g3d_cannot_create_page` — "เฟสไก่สร้างเพจไม่ได้ (10 ก.ค.)"
    - message: `เฟสไก่สร้างเพจได้ไหมครับ`
    - must_contain: `[['ไม่ได้']]`
20. `price_anti_hallucination` — "ห้ามแต่งราคา (VIP anti-hallucination)"
    - message: `Nolimit Personal ราคาเท่าไหร่ครับ`
    - must_contain: `[['1,100']]`
    - must_not_contain: `['800 บาท', '900 บาท', '1,200', '1,500']`

### 2.2 `app/Console/Commands/PromptEval.php`

- signature: `prompt:eval {--bot=26 : Bot ID} {--runs=1 : รันซ้ำกี่รอบต่อเคส} {--filter= : รันเฉพาะ case id ที่ตรง (คั่นด้วย comma)} {--json= : เขียนผลเป็นไฟล์ JSON ที่ path นี้}`
- description ภาษาไทย
- โหลดเคสจาก `config('prompt-eval-cases')`, กรองด้วย `--filter`
- ต่อเคส รัน `--runs` รอบ; **เคสผ่าน = ผ่านทุกรอบ** (prompt ใช้ temperature 0.40 จึงไม่ deterministic — ผลต่างรอบเป็นสัญญาณ flaky ที่ต้องเห็น)
- แสดงผลระหว่างรัน: `✅/❌ [id] label (x/n รอบผ่าน)` และถ้าตก ให้แสดงเหตุผลกับ 200 ตัวอักษรแรกของคำตอบ
- ปิดท้าย: สรุป `ผ่าน X/Y เคส`, เวลารวม, ค่าใช้จ่ายรวมโดยประมาณ (`sum(cost)`), และรายชื่อเคสที่ตก
- `--json` เขียนไฟล์: `{"generated_at":..., "bot_id":..., "runs":..., "cases":[{"id","label","passed","runs_passed","failures","response"}]}`
- exit code ตาม Global Constraint 5

### Tests

`tests/Feature/Console/PromptEvalCommandTest.php` — bind `PromptEvalRunner` ปลอมเข้า container
(ห้ามเรียก LLM จริง) แล้วยืนยัน: เคสผ่านหมด → exit 0 · มีเคสตก → exit 1 · `--filter` รันเฉพาะเคสที่ระบุ ·
`--json` เขียนไฟล์ที่มีคีย์ครบ

รัน: `./vendor/bin/phpunit tests/Feature/Console/PromptEvalCommandTest.php` ต้องเขียว

---

## Task 3 — เอกสาร

สร้าง `docs/prompt-eval.md` (ภาษาไทย) ครอบ:

- ใช้ทำอะไร + ต้องรันเมื่อไหร่ (**ก่อนและหลังแก้ `flows.system_prompt` หรือ KB ทุกครั้ง**)
- วิธีรัน: `php artisan prompt:eval`, `--runs=3` ก่อนขึ้น prod, `--filter=pixel_premade`, `--json=/tmp/eval.json`
- วิธีรันบน prod: `railway ssh -- php /var/www/html/artisan prompt:eval` (container root คือ `/var/www/html` ไม่ใช่ `/app`)
- วิธีเพิ่มเคสใหม่ลง `config/prompt-eval-cases.php` พร้อมตัวอย่าง 1 เคส และกฎว่า **ทุกครั้งที่แก้ prompt เพราะเคสจริง ต้องเพิ่มเคสนั้นเข้าชุดทดสอบ**
- ข้อจำกัดที่ต้องรู้: ยิง LLM จริงจึงมีค่าใช้จ่ายและช้า (~5-10 วิ/เคส), temperature 0.40 ทำให้ผลไม่คงที่ 100% จึงมี `--runs`, ชุดนี้กันได้แค่ "เคสเก่าพังซ้ำ" ไม่ได้กันเคสใหม่

เพิ่มลิงก์ไปยัง `docs/prompt-eval.md` ใน `docs/testing.md` (หัวข้อใหม่สั้น ๆ 2-3 บรรทัด)

---

## Verification (ทั้ง plan)

1. `./vendor/bin/phpunit tests/Unit/PromptEvalRunnerTest.php tests/Feature/Console/PromptEvalCommandTest.php` — เขียวทั้งหมด
2. `./vendor/bin/pint --test` — ผ่าน
3. เจ้าของ/Claude รันจริงบน prod: `railway ssh -- php /var/www/html/artisan prompt:eval` แล้วดูผล 20 เคส
   (เคสที่ตกในรอบแรกอาจเป็นเกณฑ์เข้มไป → ปรับ **เกณฑ์ในเคส** ไม่ใช่ปรับ prompt)
