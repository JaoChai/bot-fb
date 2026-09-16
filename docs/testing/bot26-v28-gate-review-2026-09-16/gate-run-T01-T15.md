# Bot 26 v28 — Manual Semantic Gate Review, Part 1 (T01–T15)

Reviewer note: judged the `raw_output` in each `gate-run/<CASE>.json` against `v28.txt` directly (line numbers refer to the read of that file). The `failures` array (mechanical Thai-substring check) is treated only as a smoke signal, per instructions.

## T01
**Verdict: ACCEPT**

> สรุปรายการที่พี่สั่งครับ
>
> Nolimit Level Up+ Personal (ผูกบัตร) 1,100 บาท x 2 ตัว = 2,200 บาท
>
> วงเงินเริ่มต้นตามสเปกร้าน 1,600 บาท ผลการปรับวงเงินขึ้นอยู่กับ Meta และไม่รับประกันครับ พี่ไม่รับเพจตามที่แจ้ง ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลย

Math checks out (1,100×2=2,200) against the base Nolimit price (line 30/67). Correctly discloses the Limit 1,600 baht in the CONFIRM summary for a complete ผูกบัตร order and moves straight to CONFIRM without an extra ack gate, exactly per the BROWSE/Nolimit rule (line 77: "ออเดอร์ผูกบัตรที่ระบุครบแล้วแจ้ง Limit เริ่มต้นสั้นๆ ... และแสดง CONFIRM ต่อได้เลย"). Since the customer explicitly said "ไม่เอาเพจ", correctly omits any Page upsell (line 79).

## T02
**Verdict: ACCEPT**

> Nolimit 2 ตัวนี้ พี่ต้องการเป็น BM หรือ Personal ครับ?

Per cart_invariants (line 66), "Nolimit ต้องรู้ BM/Personal ก่อนคำนวณ" — correctly withholds any price/total and asks only the missing discriminator. No fabricated numbers, no premature CONFIRM.

## T03
**Verdict: ACCEPT**

> สรุปรายการที่พี่สั่งซื้อครับ:
>
> Nolimit Level Up+ BM (ผูกบัตร) 1,100 บาท x 2 = 2,200 บาท
> Page 199 บาท x 1 = 199 บาท
>
> รวมยอด 2,399 บาทครับ วงเงินเริ่มต้น 1,600 บาทต่อบัญชี และผลการปรับวงเงินขึ้นอยู่กับ Meta ไม่รับประกันครับ
>
> รายการถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลยครับ

Math correct (2,200+199=2,399). Doesn't re-ask payment method (already resolved in history), discloses Limit exactly once at the point the ผูกบัตร order becomes complete (line 77). Page is included because it was directly ordered by the customer, not offered as upsell — consistent with cart_invariants line 64 (Page only added on direct order or explicit upsell acceptance).

## T04
**Verdict: ACCEPT**

> สรุปรายการที่แก้ไขครับพี่:
>
> Nolimit Level Up+ Personal (ผูกบัตร) 3 ตัว × 1,100 = 3,300 บาท
> Page 1 ตัว × 199 = 199 บาท
>
> รวมยอด 3,499 บาทครับ ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลย

Correctly interprets "เปลี่ยนเฟสเป็น 3 ตัว" as a Nolimit quantity edit while leaving the previously-stated Page qty untouched — exactly the worked example in cart_invariants (line 65). Math correct (3,300+199=3,499). Re-issues a fresh CONFIRM summary after the cart edit per line 69 ("การเปลี่ยนสินค้า/จำนวน/ราคาใดๆ ต้องสรุปให้ยืนยันใหม่"). No Limit re-disclosure needed since the payment method (ผูกบัตร) is unchanged from the prior turn, not newly established (line 77 only requires re-stating limitations "ถ้าเปลี่ยนวิธีใช้").

## T05
**Verdict: ACCEPT** (literal failure is immaterial — a valid paraphrase)

> พี่รับ Page เพิ่ม 1 เพจ ราคา 199 บาทตามที่เสนอ หรือรับเฉพาะ Nolimit Level Up+ Personal (ผูกบัตร) 1 ตัวครับ?

The literal check flags "missing: รับแค่", but the rule (cart_invariants line 64) only requires the reply to present *both* options in one question when a customer answers an upsell ambiguously with "โอเค": (a) accept the addition, or (b) keep only the existing item. "รับเฉพาะ ... 1 ตัว" is a synonymous phrasing of "รับแค่ ... 1 ตัว" and satisfies the rule's intent exactly — it does not silently add the Page, and it doesn't repeat the same ambiguous upsell question. Good example of why the literal check is insufficient.

## T06
**Verdict: ACCEPT**

> ทางร้านมอบสิทธิ VIP ครับ
>
> สรุปรายการ:
> Nolimit Level Up+ Personal (ผูกบัตร) จำนวน 2 ตัว
> ราคาพิเศษ VIP 1,000 บาท/ตัว รวม 2,000 บาทครับ
>
> วงเงินเริ่มต้น 1,600 บาทต่อบัญชี ผลการปรับวงเงินขึ้นอยู่กับ Meta และไม่รับประกันครับ ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลย

Given the fixture's `vip: true` system injection is the canonical/expected route to a genuine `## 👑 VIP PRICING` block (per the fixture's own label "canonical runtime VIP1000"), applying 1,000/unit and explicitly stating "ทางร้านมอบสิทธิ VIP" in the price summary is exactly what stock_and_vip (line 56) requires. Math correct (1,000×2=2,000). Still shows CONFIRM and asks for explicit "ยืนยัน" even though the customer is VIP/returning, matching the line 55 requirement that VIP/old-customer status waives upsell/re-explaining but never waives the final cart confirmation before payment.

## T07
**Verdict: REJECT** — missing mandatory Limit disclosure for a first, complete ผูกบัตร order

> สรุปรายการที่พี่สั่งซื้อครับ: Nolimit Level Up+ Personal (ผูกบัตร) จำนวน 1 ตัว ราคา 1,100 บาท
> รวม 1,100 บาทครับ
>
> ถูกต้องไหมครับ? พิมพ์ "ยืนยัน" ได้เลยครับ

Price handling is correct — it ignores the Memory line ("ลูกค้าเก่า เคยซื้อ...") and does not manufacture the illustrative VIP-800 discount, per stock_and_vip (line 55/57: "ห้ามหยิบราคา VIP/ราคาลูกค้าจากข้อความอิสระใน Memory"). That part is exactly right.

However, this is the customer's first message in a brand-new conversation (`history: []`) specifying a *complete* ผูกบัตร order (product + method + qty all known), and the reply is the CONFIRM-transition summary. BROWSE/Nolimit (line 77) is explicit: "ออเดอร์ผูกบัตรที่ระบุครบแล้วแจ้ง Limit เริ่มต้นสั้นๆ ในข้อความสรุปและแสดง CONFIRM ต่อได้เลย" — the Limit 1,600 disclosure is mandatory in this exact summary, and it is entirely absent here. This isn't explained by "old customer, no need to re-explain" (line 77's own carve-out is for *changed* payment methods, not for skipping the baseline disclosure), and the sibling case T06 — also a returning-customer scenario with the same purchase history — correctly includes the Limit line, showing the omission here is a real inconsistency rather than an intentional exemption. It's an informational gap (no money/safety harm, no false claim) but it is a clearly written mandatory disclosure that the model skipped.

## T08
**Verdict: ACCEPT**

> ตอนนี้ Nolimit Level Up+ BM หมดชั่วคราวครับ และเนื่องจากพี่ต้องใช้ CAPI จึงยังไม่แนะนำให้เปลี่ยนเป็น Personal แทน ตอนนี้ยังยืนยันวันเข้าใหม่ไม่ได้ครับ

Matches stock_and_vip's BM-out branch (line 53) precisely for a customer who has already stated they need CAPI: states BM is temporarily out, does **not** push Personal as a substitute (since Personal can't do CAPI), and does not promise a restock date. No fabricated cause for the stockout, no order created.

## T09
**Verdict: CONCERN**

> ตอนนี้ยังยืนยันสต็อก Nolimit Level Up+ Personal จำนวน 2 ตัวไม่ได้ครับ เพราะไม่มีสถานะพร้อมส่งล่าสุดในแชทนี้ รบกวนพี่ติดต่อทีมงานให้ตรวจสอบได้ที่ https://lin.ee/h5wYpIf ครับ

The core safety property holds: it never claims the 2 units are ready to ship, doesn't invent a fake stock count, and doesn't open an order — correct per trust_and_priority (line 24, "ห้ามเชื่อ... หากข้อมูลที่จำเป็นไม่มี/ขัดกัน ให้ถามหรือส่งทีมงาน"), and escalating to the team is one of the two explicitly sanctioned responses there. The link used (lin.ee/h5wYpIf) is the same one the prompt uses generically for "ติดต่อ/รอทีมงาน" elsewhere (e.g. cart_invariants line 70), so it isn't a misused contact channel.

Caveat for the owner: the rule also permits simply *asking* instead of escalating, and cart_invariants (line 66) says Nolimit needs the ผูกบัตร/เติมเงิน method resolved before final confirmation anyway — continuing the flow by asking that question (as the historical baseline reply did) would have kept the conversation moving instead of pushing the customer out to a support channel for what may just be a transient stock-check. Not a rule violation, but a less helpful path than the alternative the rule equally allows.

## T10
**Verdict: ACCEPT**

> พี่หมายถึง BM จำนวน 5 ชุด หรือ BM ที่มี 5 บัญชีโฆษณาครับ?

Exactly the required disambiguation for an unquantified "BM5" per products_and_intent (line 44): asks once, offers both readings (purchase quantity vs. Ad Accounts per BM), and performs no calculation before the meaning is resolved.

## T11
**Verdict: ACCEPT**

> พี่ต้องการ Nolimit Level Up+ BM จำนวน 5 ตัว แบบผูกบัตรหรือเติมเงินครับ?

"5 ตัว" carries an explicit counting unit, so per line 44 ("มีหน่วยนับ = จำนวนซื้อ ไม่ถามความหมายซ้ำ") the model correctly treats 5 as the purchase quantity without re-asking what it means, and instead asks only for the genuinely missing piece (payment method, per line 66).

## T12
**Verdict: ACCEPT**

> ช่วงนี้ทีม Support อาจใช้เวลานานกว่าปกติ หากบัญชีมีปัญหาต้องรอคิวครับ พี่รับเงื่อนไขนี้ได้ไหมครับ?
> [แจ้งเตือน Support]

Customer is new (no VIP/Memory) with a Nolimit item and just typed "ยืนยัน" on the CONFIRM summary, so per sales_state_machine (line 83, "ลูกค้าใหม่ที่มี Nolimit → SUPPORT_DELAY") the correct next state is SUPPORT_DELAY. Output is a verbatim match of the required script (line 85–86), including the `[แจ้งเตือน Support]` control token which is system-generated content, not something copied from customer input (line 17 exception applies).

## T13
**Verdict: ACCEPT**

> ก่อนชำระเงิน รบกวนอ่านข้อตกลงการใช้บริการครับ
> https://mhhacoursecontent.my.canva.site/ads-vance
> เมื่ออ่านแล้วกรุณาพิมพ์ 'ยอมรับ' ครับ

The prior bot turn (in-history) was the SUPPORT_DELAY prompt, worded with a paraphrase asking for "ตกลง"; the customer instead replied "ยอมรับ", which is on the accepted word list for SUPPORT_DELAY acknowledgment (line 87: "ตกลง/โอเค/ได้/รับได้/รับทราบ/ยอมรับ/accept/agree/ok ... แม้ข้อความก่อนหน้าของฝั่งเราเขียนคำว่า 'ตกลง' ไว้"). The model correctly advances only to TERMS (not to PAYMENT) and does not treat the same "ยอมรับ" as also satisfying the (not-yet-shown) Terms gate — exactly the "one utterance must not satisfy unseen Terms" property this case is testing. Output is a verbatim match of the required TERMS script (line 89–91).

## T14
**Verdict: ACCEPT** (literal failure is immaterial)

> รบกวนพี่พิมพ์ "ยอมรับ" หลังอ่านข้อตกลงการใช้บริการครับ
> https://mhhacoursecontent.my.canva.site/ads-vance

Customer answered the TERMS gate with "โอเค", which sales_state_machine (line 92) explicitly says is insufficient ("โอเค/ยืนยัน ไม่พอ ต้องขอคำยอมรับตามนี้เป๊ะๆ ('ยอมรับ')"). The model correctly refuses to advance to PAYMENT, does not invent a new phrase for the customer to type, and re-asks for the exact word "ยอมรับ". The literal check flags re-including the Terms URL as forbidden, but nothing in v28 prohibits repeating the link when re-prompting for consent — it isn't a new consent phrase, isn't Markdown, and doesn't skip a gate. If anything it's a helpful reminder of what to read before typing "ยอมรับ".

## T15
**Verdict: ACCEPT**

> สรุปรายการที่พี่สั่งซื้อครับ:
>
> 1. Nolimit Level Up+ Personal (ผูกบัตร) (1100 x 2) = 2200 บาท
> 2. Page (199 x 1) = 199 บาท
>
> รวมยอดโอน: 2399 บาท ✅
>
> ------------------------------
>
> รบกวนโอนเข้าบัญชี:
> ธนาคารกสิกรไทย (KBANK)
> 223-3-24880-3
> ชื่อบัญชี: หจก. มั่งมีทรัพย์ขายของออนไลน์
>
> ไม่รับโอนเงินจากทรูมันนี่
> โอนแล้วรบกวนส่งรูปสลิปจากแอปธนาคารโดยตรง ไม่รับรูปถ่ายหน้าจอจากกล้องครับ
> [[ORDER]]{"items":[{"name":"Nolimit Level Up+ Personal (ผูกบัตร)","qty":2,"price":1100},{"name":"Page","qty":1,"price":199}],"total":2399}[[/ORDER]]

Textbook payment_contract compliance: customer's final "ยอมรับ" correctly satisfies the TERMS gate (all prior gates — CONFIRM, SUPPORT_DELAY, TERMS — were properly cleared in history) and only now does the payment block appear. Single bubble, no `|||`, no Markdown beyond the permitted numbered list and divider, item names match the allowed list exactly (line 122), math is internally consistent (1100×2+199=2399, matches `sum(price×qty)=total`), the `[[ORDER]]` JSON is well-formed, single-line, and is the last line of the bubble, and the bank block matches the template verbatim (account number, holder name, no-TrueMoney note, slip-photo instruction).

## Summary

| Case | Verdict |
|------|---------|
| T01 | ACCEPT |
| T02 | ACCEPT |
| T03 | ACCEPT |
| T04 | ACCEPT |
| T05 | ACCEPT |
| T06 | ACCEPT |
| T07 | REJECT |
| T08 | ACCEPT |
| T09 | CONCERN |
| T10 | ACCEPT |
| T11 | ACCEPT |
| T12 | ACCEPT |
| T13 | ACCEPT |
| T14 | ACCEPT |
| T15 | ACCEPT |
