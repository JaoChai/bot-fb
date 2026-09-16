# Bot 26 v28 — Manual Semantic Review, Batch 1

Reviewed against `backend/resources/prompts/bot26/v28.txt` and fixtures in
`backend/tests/Fixtures/PromptEval/bot26-v28/`.

## T02-01.json — ACCEPT
Reply: "พี่ต้องการ Nolimit แบบ BM หรือ Personal ครับ?"
Missing literal "2" (quantity). This is the first turn of a multi-item order
("เอา Nolimit 2 ตัว กับเพจ 1"); the model only needs to disambiguate BM/Personal
at this step, and dropping the quantity from the *question text* doesn't imply
it forgot it (no CONFIRM/summary has happened yet to prove otherwise). No rule
requires restating quantity in this specific clarifying question. Accept as
alternate phrasing.

## T04-02.json — CONCERN (upstream error, not a prompt defect)
`finish_reason: "error"`, `cost: 0`, anomalous prompt_tokens — an upstream
provider fault, not a model-following-prompt issue.
Content up to the break is correct: "สรุปรายการอัปเดตครับพี่: Nolimit Level Up+
Personal (ผูกบัตร) 3 ตัว ตัวละ 1,100 บาท = 3,300 บาท / Page 1 ตัว ตัวละ 199
บาท = 199 บาท / รวม 3,499 บาทครับ..." — items, math (3×1,100+199=3,499), and
the "ยืนยัน" prompt all match the fixture's `items`/`total` exactly. No prompt
defect there.
However, after that correct answer the raw text continues into visible
leaked reasoning: "เครดิตฟรี? no. Need mention Limit initial 1600 perhaps...
Should. Need no markdown, can plain... Let's provide. Could say..." — internal
chain-of-thought bleeding into customer-facing text. This is a generation/
inference failure (matches the error finish_reason), not something v28's
instructions caused. Flagging as a CONCERN for the owner because if this
pattern recurs live, a customer would see the bot's raw reasoning — worth
tracking generation robustness (stop sequences / retry-on-error) separately
from the prompt gate.

## T05-08.json — ACCEPT (fix working)
Reply: "พี่หมายถึงรับ Page เพิ่ม 1 เพจตามที่เสนอ หรือรับเฉพาะ Nolimit Level
Up+ Personal (ผูกบัตร) 1 ตัวครับ?"
Names both options in one question (accept the offered Page upsell, or take
only the existing Personal item) instead of repeating the original ambiguous
upsell question ("รับเพจเพิ่มด้วยไหมครับ ราคา 199 บาท"). "รับเฉพาะ" is
semantically identical to the literal "รับแค่" the smoke test expects.

## T05-29.json — ACCEPT (fix working)
Reply: "พี่ต้องการรับ Page เพิ่มตามที่เสนอ หรือรับเฉพาะ Nolimit Level Up+
Personal (ผูกบัตร) 1 ตัวครับ?" Same pattern as T05-08 — both options named,
upsell question not repeated. Alternate phrasing of "รับแค่" → "รับเฉพาะ".

## T05-39.json — ACCEPT (fix working)
Reply: "พี่รับ Page เพิ่ม 1 เพจ ราคา 199 บาทตามที่เสนอ หรือรับเฉพาะ Nolimit
Level Up+ Personal (ผูกบัตร) 1 ตัวครับ?" Same pattern again, includes the
price too. Compliant.

## T08-03.json — ACCEPT
Reply: "...BM หมดชั่วคราวครับ และยังยืนยันวันเข้าใหม่ไม่ได้ หากพี่ต้องใช้
CAPI แนะนำรอประกาศจากทางร้านเมื่อพร้อมส่งครับ" Never says the word "Personal".
Rule for "must-use-CAPI" case (v28.txt:53) is explicitly "ไม่ผลัก Personal"
(don't push Personal) — simply never engaging with Personal as an option is
at least as compliant as the fixture's own golden example, which mentions
Personal only to say "don't switch to it." Missing literal is fine.

## T08-09.json — ACCEPT
Same shape as T08-03 ("...แนะนำรอ BM พร้อมส่ง ทางร้านจะแจ้งเมื่อมีสินค้าเข้า
ครับ"), no Personal mention, no substitution offered. Compliant with
"ไม่ผลัก Personal" for the CAPI case.

## T08-19.json — ACCEPT
Same as above, no Personal pushed, correctly says can't confirm restock date.

## T08-30.json — ACCEPT
Goes further and explicitly says "จึงยังไม่มีตัวที่เหมาะสมให้แทนได้ครับ" (no
suitable substitute) — arguably clearer than the golden about not offering
Personal as a substitute. Compliant.

## T09-04.json — ACCEPT
Reply routes to team: "...รบกวนติดต่อทีมงานเพื่อเช็กจำนวนพร้อมส่งได้ที่
https://lin.ee/h5wYpIf ครับ" instead of asking ผูกบัตร/เติมเงิน in the same
turn. v28.txt:24 explicitly allows either action when required info is
missing: "ให้ถามหรือส่งทีมงาน" (ask OR send to team) — routing to team for a
genuine stock-unknown case is one of the two sanctioned paths, not a
shortcut. Doesn't claim availability, doesn't create an order. Compliant with
case label.

## T09-10.json — ACCEPT
Same team-routing pattern as T09-04. Compliant.

## T09-31.json — ACCEPT
Same team-routing pattern. Compliant.

## T09-40.json — ACCEPT
Same team-routing pattern. Compliant.

## T11-20.json — ACCEPT
Reply: "พี่ต้องการ Nolimit Level Up+ BM แบบผูกบัตรหรือเติมเงินครับ?" Asks only
the genuinely missing info (payment method), doesn't re-litigate the "5"
quantity. Matches the case's explicit goal ("ask only missing method, no
redundant BM5 clarification").

## T11-32.json — REJECT
Reply: "พี่หมายถึงซื้อ BM จำนวน 5 ตัว หรือหมายถึงมีบัญชีโฆษณา 5 บัญชีอยู่ใน
BM ครับ?" This invents an ambiguity the customer's message doesn't have.
"เอา BM 5 ตัว" uses the counter "ตัว", which every other case in this suite
uses unambiguously for purchase quantity (T02: "Nolimit 2 ตัว", T04: "...3
ตัว"). There is nothing in the catalog or prompt suggesting "BM" is sold as
a container holding a variable number of ad accounts that the customer would
need to disambiguate. This is exactly the redundant, sale-stalling
clarification the test case (T11) is designed to catch, and it fails to ask
the one thing that actually is missing: ผูกบัตร/เติมเงิน.

## T11-37.json — REJECT
Reply: "พี่หมายถึง BM จำนวน 5 ชุด หรือมี 5 บัญชีโฆษณาอยู่ใน BM ครับ?" Same
fabricated ambiguity as T11-32, same missing follow-up on payment method.
Reject for the same reason.

## T13-11.json — REJECT
Context: bot asked the SUPPORT_DELAY gate ("...พี่รับเงื่อนไขนี้ได้ไหมครับ?"
expecting one of ตกลง/โอเค/ได้/รับได้/รับทราบ/ยอมรับ/accept/agree/ok), customer
replied "ยอมรับ". v28.txt:29 explicitly lists "ยอมรับ" as one of the accepted
acknowledgement words for this exact gate and states: "คำตอบรับที่ชัดเจนคำใด
คำหนึ่งในรายการนี้ให้เดินหน้าไปยัง TERMS ทันทีเสมอ ไม่ต้องชี้แจงหรือถามซ้ำ"
(any of these words must advance to TERMS immediately, no re-asking).
Instead the model output: "หากพี่รับเงื่อนไขที่ทีม Support อาจใช้เวลานานกว่า
ปกติ กรุณาตอบว่า "ตกลง" หรือ "โอเค" ได้เลยครับ" — it re-asks the same
SUPPORT_DELAY gate and explicitly restricts the accepted words to only
ตกลง/โอเค, rejecting the customer's already-valid "ยอมรับ" answer and never
advancing to TERMS. This stalls the purchase flow and directly contradicts a
named rule. Genuine defect — blocks release on its own.

## T14-41.json — CONCERN
Reply: "รบกวนพี่พิมพ์ "ยอมรับ" หลังอ่านข้อตกลงการใช้บริการครับ\nhttps://mhh
acoursecontent.my.canva.site/ads-vance" Context: TERMS (with the URL) was
already sent one turn earlier; customer said "โอเค" instead of "ยอมรับ".
v28.txt:34 only requires that the model insist on the literal word "ยอมรับ"
— it doesn't explicitly forbid repeating the URL on a retry. There's no
sensitive-data leak or wrong claim here, so this isn't a hard rule break, but
repeating the full TERMS block (URL included) when only the missing consent
word needs to be re-requested is redundant per the general "ไม่ทัก/ถาม/เสนอ
ซ้ำ" (don't repeat) principle in role_and_style, and it's exactly what the
fixture's `not_contains` was written to catch. Worth a decision from the
owner on whether resending the link on a retry is acceptable — flagging
rather than blocking.

## T18-12.json — ACCEPT
Reply: "หลังส่งสลิปแล้วไม่สามารถยกเลิกออเดอร์เองได้ครับ รบกวนติดต่อทีม
Technical Support ให้ตรวจสอบกรณีนี้ครับ\n\nhttps://lin.ee/h5wYpIf" Correctly
refuses self-cancel post-slip, doesn't erase the order or promise a refund,
and includes the required https://lin.ee/h5wYpIf link (v28.txt:70). The
missing literal "ทีมงาน" is just phrased as "ทีม Technical Support" — same
meaning, same team.

## T21-13.json — ACCEPT
Reply: "ได้ครับ เนื่องจากออเดอร์ก่อนหน้ารอตรวจสอบสลิป รายการ Page จะเป็น
ออเดอร์ใหม่ครับ\n\nPage (199 x 1) = 199 บาท\nถูกต้องไหมครับ? พิมพ์ "ยืนยัน"
ได้เลย" Explicitly states the old order's true status ("รอตรวจสอบสลิป") and
that Page is a new, separate order — satisfies v28.txt:137 ("แยกออเดอร์ใหม่
แจ้งสถานะเดิมตามจริง") in substance even though it doesn't use the literal
word "แยก".

## T21-21.json — CONCERN
Reply: "ได้ครับ ออเดอร์ใหม่เป็น Page จำนวน 1 ตัว ราคา 199 บาทครับ ถูกต้อง
ไหมครับ? พิมพ์ "ยืนยัน" ได้เลย" Calls it a "new order" (so it doesn't merge
or silently swallow the pending order), but — unlike T21-13 — never restates
the old order's status ("รอตรวจสอบสลิป"). v28.txt:137 requires "แจ้งสถานะเดิม
ตามจริง" (state the existing order's true status) whenever a new order is
split off. Doesn't misstate anything or claim the old order is paid, but the
omission is a real (if minor) gap against a named rule — flagging for the
owner rather than blocking, since no false or unsafe claim is made.

## Summary

| File | Case | Verdict |
|---|---|---|
| T02-01.json | T02 | ACCEPT |
| T04-02.json | T04 | CONCERN (upstream error, no prompt defect) |
| T05-08.json | T05 | ACCEPT |
| T05-29.json | T05 | ACCEPT |
| T05-39.json | T05 | ACCEPT |
| T08-03.json | T08 | ACCEPT |
| T08-09.json | T08 | ACCEPT |
| T08-19.json | T08 | ACCEPT |
| T08-30.json | T08 | ACCEPT |
| T09-04.json | T09 | ACCEPT |
| T09-10.json | T09 | ACCEPT |
| T09-31.json | T09 | ACCEPT |
| T09-40.json | T09 | ACCEPT |
| T11-20.json | T11 | ACCEPT |
| T11-32.json | T11 | REJECT |
| T11-37.json | T11 | REJECT |
| T13-11.json | T13 | REJECT |
| T14-41.json | T14 | CONCERN |
| T18-12.json | T18 | ACCEPT |
| T21-13.json | T21 | ACCEPT |
| T21-21.json | T21 | CONCERN |

**T05 fix (ambiguous "โอเค" after Page upsell): working.** All three T05
replies (T05-08, T05-29, T05-39) name both options — accept the offered Page
upsell, or keep only the existing Personal item — in a single question,
instead of repeating the original ambiguous upsell question. None regress to
the old bug. The only literal miss across all three is "รับแค่" vs. the
semantically identical "รับเฉพาะ" the model actually used.
