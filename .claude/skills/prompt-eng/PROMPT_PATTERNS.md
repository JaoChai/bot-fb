# Prompt Patterns

## Core Patterns

### 1. Role Assignment

กำหนดบทบาทชัดเจนให้ AI รู้ว่าตัวเองเป็นใคร

```markdown
## บทบาท

คุณคือ "น้องแอน" พนักงานขายของร้าน XYZ
- ใช้ภาษาสุภาพ ลงท้ายด้วย "ค่ะ/คะ"
- ตอบกระชับ ได้ใจความ
- เป็นมิตร แต่ professional
```

### 2. Task Specification

ระบุงานที่ต้องทำอย่างชัดเจน

```markdown
## หน้าที่

1. ตอบคำถามเกี่ยวกับสินค้า (ราคา, สต็อก, รายละเอียด)
2. รับออเดอร์จากลูกค้า
3. แจ้งโปรโมชั่นที่เกี่ยวข้อง
4. ส่งต่อให้พนักงานถ้าตอบไม่ได้
```

### 3. Output Format

กำหนดรูปแบบ output ที่ต้องการ

```markdown
## รูปแบบการตอบ

- ความยาว: 1-3 ประโยค
- ภาษา: ไทย สุภาพ
- ลงท้าย: คะ/ค่ะ
- ห้าม: emoji มากเกินไป, ภาษาทางการเกิน
```

### 4. Examples (Few-shot)

ให้ตัวอย่างการตอบ

```markdown
## ตัวอย่าง

ลูกค้า: "สินค้านี้ราคาเท่าไหร่?"
ตอบ: "สินค้า [ชื่อ] ราคา X บาทค่ะ สนใจสั่งซื้อไหมคะ?"

ลูกค้า: "มีสีอื่นไหม?"
ตอบ: "สินค้านี้มี 3 สี คือ แดง, น้ำเงิน, ดำ ค่ะ สนใจสีไหนคะ?"

ลูกค้า: "ส่งได้เมื่อไหร่?"
ตอบ: "ถ้าสั่งวันนี้ จะจัดส่งภายใน 1-2 วันทำการค่ะ"
```

### 5. Constraints

กำหนดข้อห้ามและข้อจำกัด

```markdown
## ข้อห้าม

- ห้ามพูดถึงคู่แข่ง
- ห้ามให้ส่วนลดเกิน 10%
- ห้ามรับประกันสิ่งที่ทำไม่ได้
- ห้ามให้ข้อมูลส่วนตัวของลูกค้าคนอื่น
```

### 6. Fallback Handling

กำหนดวิธีจัดการเมื่อไม่รู้คำตอบ

```markdown
## เมื่อไม่รู้คำตอบ

ถ้าไม่แน่ใจหรือคำถามซับซ้อน ให้ตอบว่า:
"ขอรบกวนสอบถามข้อมูลเพิ่มเติมกับทีมงานนะคะ รอสักครู่ค่ะ"

แล้วส่งต่อให้ human agent
```

## Advanced Patterns

### Chain of Thought

ให้ AI แสดงขั้นตอนการคิด

```markdown
## วิธีวิเคราะห์คำถาม

เมื่อได้รับคำถาม ให้คิดตามขั้นตอน:
1. คำถามนี้เกี่ยวกับอะไร? (สินค้า/การสั่งซื้อ/โปรโมชั่น/อื่นๆ)
2. ต้องการข้อมูลอะไรบ้างเพื่อตอบ?
3. มีข้อมูลครบหรือไม่?
4. ตอบอย่างไรให้ครบถ้วนและกระชับ?
```

### Self-Consistency

ให้ AI ตรวจสอบคำตอบตัวเอง

```markdown
## การตรวจสอบคำตอบ

ก่อนตอบ ให้ตรวจสอบว่า:
- ตอบตรงคำถามหรือไม่?
- ข้อมูลถูกต้องหรือไม่?
- ภาษาเหมาะสมหรือไม่?
- มีข้อมูลที่ควรเพิ่มหรือไม่?
```

### Context Awareness

ให้ AI พิจารณา context

```markdown
## การใช้ context

พิจารณาบริบทการสนทนา:
- ลูกค้าเคยถามอะไรมาก่อน?
- มีการสั่งซื้อค้างอยู่หรือไม่?
- เป็นลูกค้าใหม่หรือเก่า?
- อารมณ์ของลูกค้าเป็นอย่างไร?
```

### Emotional Intelligence

ให้ AI ตอบสนองต่ออารมณ์

```markdown
## การรับมือกับอารมณ์ลูกค้า

ถ้าลูกค้าโกรธ/ไม่พอใจ:
- เริ่มด้วย "ขออภัยในความไม่สะดวกค่ะ"
- รับฟังปัญหาก่อน
- เสนอทางแก้ไข
- ยืนยันว่าจะดูแล

ถ้าลูกค้าลังเล:
- ให้ข้อมูลเพิ่มเติม
- ตอบข้อกังวล
- ไม่กดดัน
```

## Template Structure

```markdown
# System Prompt: [Bot Name]

## บทบาท
[ระบุว่า AI เป็นใคร ทำหน้าที่อะไร]

## ความรู้พื้นฐาน
[ข้อมูลที่ AI ต้องรู้ เช่น สินค้า, นโยบาย, ราคา]

## วิธีการตอบ
[รูปแบบ, โทน, ความยาว]

## ตัวอย่างการสนทนา
[2-3 ตัวอย่าง input/output]

## ข้อห้าม
[สิ่งที่ AI ต้องไม่ทำ]

## การจัดการกรณีพิเศษ
[เช่น ถ้าไม่รู้ให้ตอบอย่างไร, ถ้าลูกค้าโกรธ]
```

## Thai-Specific Patterns

### Politeness Markers

```markdown
## การใช้คำลงท้าย

- ผู้หญิง: ค่ะ, คะ (ถาม)
- ผู้ชาย: ครับ
- เป็นกลาง: นะคะ/นะครับ

ตัวอย่าง:
- "สนใจสินค้าตัวไหนคะ?" (ถาม)
- "รับทราบค่ะ" (รับ)
- "รอสักครู่นะคะ" (ขอ)
```

### Formal vs Casual

```markdown
## ระดับความเป็นทางการ

Formal (สินค้าราคาสูง):
- "ขอบพระคุณที่ติดต่อมาค่ะ"
- "สินค้ารุ่นนี้มีคุณสมบัติดังนี้ค่ะ"

Casual (สินค้าทั่วไป):
- "สวัสดีค่ะ ยินดีให้บริการนะคะ"
- "สินค้านี้ดีมากเลยค่ะ ลูกค้าหลายคนชอบ"
```

## Testing Prompts

### Test Cases

```markdown
## Test Cases

1. Basic Question
   Input: "ราคาเท่าไหร่"
   Expected: ตอบราคาพร้อมถามความสนใจ

2. Edge Case
   Input: "มีของเหมือนร้าน X ไหม"
   Expected: ไม่พูดถึงคู่แข่ง ตอบเกี่ยวกับสินค้าตัวเอง

3. Angry Customer
   Input: "ส่งช้ามาก โกรธมาก"
   Expected: ขอโทษก่อน แล้วเสนอทางแก้

4. Unknown Question
   Input: "ถามเรื่องการเมือง"
   Expected: ปฏิเสธสุภาพ กลับมาเรื่องสินค้า
```

## Iteration Process

1. **Draft** - เขียน prompt เบื้องต้น
2. **Test** - ทดสอบกับ test cases
3. **Identify gaps** - หา edge cases ที่ fail
4. **Refine** - ปรับ prompt
5. **A/B Test** - ทดสอบกับ users จริง
6. **Measure** - วัดผลด้วย metrics
7. **Iterate** - ทำซ้ำจนได้ผลดี

## Bot-FB Prompt Templates

### Sales Bot Template (Line Adsvance Pattern)

Flow 24 (v21) structure ที่ผ่านการ iterate มาแล้ว:

```markdown
Captain Ad - Sales Assistant v21

<identity>
กัปตันแอด | Sales Assistant | Thai
Tone: มืออาชีพ กระชับ ตรงประเด็น ไม่ยืดยาว
Greeting: "สวัสดีครับพี่ สนใจตัวไหนดีครับ?" (ครั้งแรกเท่านั้น)
การตอบ: 1-2 ประโยค (ไม่เกิน 3 บรรทัด)
ห้าม: ถามซ้ำ, อธิบายยาวถ้าไม่ถาม, upsell ซ้ำหลังปฏิเสธ, ทักซ้ำระหว่างบทสนทนา
</identity>

<products>
[product catalog with pricing tiers]
</products>

<pricing_rules>
[VIP vs normal pricing logic]
[Anti-hallucination checks]
</pricing_rules>

<sales_flow>
[step-by-step sales process]
[payment verification steps]
</sales_flow>
```

### Key Prompt Engineering Lessons (from git history)

| Version | Change | Result |
|---------|--------|--------|
| v18→v19 | เพิ่ม VIP pricing tiers | ลด hallucination ราคา |
| v19→v20 | เพิ่ม anti-hallucination check | ป้องกันสร้างราคาเอง |
| v20→v21 | เพิ่ม "เฟส" disambiguation | แยก Page vs G3D ถูกต้อง |

### Prompt Structure Best Practices (จาก Flow 24)

1. **XML tags** สำหรับ section boundaries (`<identity>`, `<products>`, `<rules>`)
2. **Explicit constraints** ดีกว่า implicit (ระบุ "ห้าม" ชัดเจน)
3. **Version tracking** ใส่ version ใน header เพื่อ track changes
4. **Anti-hallucination**: ระบุว่า "ถ้าไม่มีราคาใน KB ห้ามตอบเอง"
5. **Greeting guard**: กำหนดให้ทักแค่ครั้งเดียว ป้องกัน repetitive greetings
6. **Response length**: กำหนดจำนวนประโยคที่ชัดเจน (1-2 ประโยค)

### Temperature Guidelines

| Use Case | Temperature | Reason |
|----------|-------------|--------|
| Sales (accurate pricing) | 0.3 | ลด hallucination |
| Customer support | 0.7 | Natural conversation |
| Creative/casual | 0.8-1.0 | Diverse responses |
| FAQ/factual | 0.1-0.3 | Consistent answers |
