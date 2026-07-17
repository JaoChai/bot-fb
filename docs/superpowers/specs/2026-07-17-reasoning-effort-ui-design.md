# Per-Bot Reasoning Effort (ตั้งจาก UI) — Design

วันที่: 2026-07-17
สถานะ: revised after adversarial review (Rev 2 ด้านล่าง supersede ตัวเลข/ข้อความที่ขัดกัน)

## Revision 2 — ผล adversarial review (Fable 5) + decisions

Review รอบสองเจอ blocker 3 ตัวที่รอบแรกพลาด → ปรับ design ดังนี้ (แผน implementation อัปเดตตามแล้ว):

**Blockers (แก้แล้วในแผน):**
- **B1** — job ที่ generate LLM ทั้งหมด (`ProcessLINEWebhook/Facebook/Telegram` + `ProcessAggregatedMessages`) ต้องตั้ง `$timeout=200` ไม่ใช่แค่ตัว aggregation (FB/TG generate inline, LINE ก็ตรงๆ เมื่อปิด multi-bubble)
- **B2** — deploy gate ตัวจริง = queue **`retry_after`** (Redis default **90s**) ต้อง ≥ 210 ไม่ใช่ worker `--timeout` (job `$timeout` override worker อยู่แล้ว). ค่า 90 เดิมละเมิดกับ timeout=150 เดิมอยู่แล้ว
- **B3** — B1 client-side fallback (จาก #239) เดิมส่ง `reasoning`+`timeout` ต่อให้ fallback → high รั่วไป fallback + worst-case 120+120. แก้: recursion ส่ง `reasoning: null` + `timeout: 45` (fast escape)

**Decisions (จากเจ้าของ):**
- timeout map = **low 45 · medium 45 (no-regress; prod เดิม 45) · high 90** (LINE loading indicator ตันที่ 60s — `LINEService:340`)
- **adaptive effort:** ค่าบอทเป็น **เพดาน** — ข้อความ `is_complex` ใช้เต็ม, ไม่ complex cap ที่ medium (ประหยัด ~80% ข้อความง่าย) via `RAGService::resolveReasoningEffort()`
- column `nullable` (B4); token headroom gate ด้วย `supportsReasoning` (B7); frontend submit ต้องแก้ `EditConnectionPage.tsx` 2 จุด (B6)

**Design verdict:** shape (per-bot column → RAGService → chat gate) ถูกต้อง เป็น best fit สำหรับโจทย์ "UI knob ต่อบอท"; streaming เลื่อนถูกแล้ว (LINE/FB ไม่ใช่ live stream). Caveat ที่เหลือ: high ยังเป็น UX cost (แม้ 90s) — ควรใช้เท่าที่จำเป็น

---


## ปัญหา / เป้าหมาย

ตอนนี้ reasoning effort ของโมเดล (low/medium/high) ถูกกำหนดจาก config ต่อโมเดล หรือ default `'medium'` ที่ hardcode ใน `OpenRouterService::chat()` — เจ้าของบอทปรับเองไม่ได้ พอเปลี่ยนโมเดลก็ปรับ effort ให้เหมาะไม่ได้

ต้องการ:
1. เจ้าของตั้ง reasoning effort ต่อบอทได้จากหน้า Connection (UI)
2. ระบบเรียกใช้ค่าจาก UI แทน default ของโมเดล
3. รองรับ effort=high โดยระบบไม่พัง (latency สูงขึ้นมากไม่ทำให้ timeout/worker ตาย/คำตอบถูกตัด)

## ขอบเขต (จาก brainstorming)

- **ค่าเดียวต่อบอท** ใช้กับโมเดลตอบแชทหลัก (primary_chat_model); fallback/utility ใช้ default ของโมเดลไป
- **timeout ปรับตาม effort อัตโนมัติ**: low=45s / medium=60s / high=120s
- **Segmented control แบบมีรายละเอียด** ใน AI Models section, โชว์เฉพาะเมื่อ primary model รองรับ reasoning
- **default = medium** (คงพฤติกรรมปัจจุบัน)
- **revert A1** (config `openai/gpt-5.6-luna` → effort=low ใน PR #239) เพราะค่าต่อบอทเข้ามาแทน

## แนวทางที่เลือก (vs ทางเลือกอื่น)

| ประเด็น | เลือก | ทางเลือกที่ตัดออก + เหตุผล |
|--------|------|---------------------------|
| เก็บค่า | คอลัมน์ `bots.reasoning_effort` | settings JSON — ค้นยาก/ไม่ typed; global setting — ปรับต่อบอทไม่ได้ |
| จัดการ latency high | timeout ปรับตาม effort | timeout สูงค่าเดียว — low/medium ต้องรอนาน; streaming — งานใหญ่ กระทบ webhook/aggregation/bubbles ทั้งสาย ยกไป phase หลัง |
| ขอบเขต effort | ค่าเดียวต่อบอท | per-model — ซับซ้อน UI/ค่าไม่ค่อยได้ใช้ |

## สถาปัตยกรรม & Data Flow

```
UI (AIModelsSection) --reasoning_effort--> useConnectionForm --> PATCH /bots/{id}
     --> UpdateBotRequest (validate in:low,medium,high) --> bots.reasoning_effort

ProcessLINEWebhook / ProcessAggregatedMessages
  --> AIService::generateResponse(bot, ...)
      --> RAGService::generateResponse(bot, ...)
          effort  = bot.reasoning_effort ?? 'medium'
          timeout = effortTimeout(effort)   // 45/60/120
          --> OpenRouterService::generateBotResponse(..., reasoning:['effort'=>$effort], timeout:$timeout)
              --> chat(..., reasoning, timeout)
                  payload['reasoning'] = ['effort' => $effort]   // จาก bot ไม่ใช่ model default
```

### จุดแก้ backend
1. **migration**: `bots.reasoning_effort` VARCHAR nullable **default `'medium'`** (ค่า low/medium/high)
2. **`Bot.php`**: เพิ่ม `reasoning_effort` ใน `$fillable`; ไม่ต้อง cast (string)
3. **`StoreBotRequest` / `UpdateBotRequest`**: `reasoning_effort => ['nullable','in:low,medium,high']`
4. **`BotResource`**: expose `reasoning_effort`
5. **`config/services.php`**: เพิ่ม `openrouter.effort_timeouts = ['low'=>45,'medium'=>60,'high'=>120]` (ปรับได้ทีหลัง; fallback ไป `timeout` เดิมถ้า key หาย)
6. **`OpenRouterService::generateBotResponse()`**: เพิ่ม param `?array $reasoning = null` + `?int $timeout = null` แล้วส่งต่อเข้า `chat()` (ปัจจุบันยังไม่ส่ง reasoning)
7. **`RAGService::generateResponse()`** (จุดเรียก `generateBotResponse` ~บรรทัด 210): อ่าน `$bot->reasoning_effort`, map เป็น timeout, ส่ง `reasoning:['effort'=>$effort]` + `timeout` เข้าไป
   - ถ้า primary model ไม่รองรับ reasoning → ไม่ส่ง reasoning (chat() มี guard `supportsReasoning` อยู่แล้ว) แต่ timeout ยัง map ตาม effort ได้
8. **revert A1**: ลบ entry `openai/gpt-5.6-luna` ออกจาก `config/llm-models.php`

### Safeguards (กัน high พังจริง)
- **Job timeout**: `ProcessAggregatedMessages::$timeout` 150 → **200** (high 120s + fallback gemini ~15s + overhead)
  - ⚠️ **Deploy gate**: queue worker `--timeout` (ตั้งฝั่ง Railway start command / Procfile) ต้อง ≥ 200 ไม่งั้น worker ฆ่า job ก่อน — ต้องยืนยันค่าจริงตอน deploy
- **max_tokens headroom**: เมื่อ effort=high ยก floor `max_tokens` (กัน reasoning tokens กินโควตาจนคำตอบมองเห็นถูกตัด) — ใช้ค่า floor เช่น 8000 เฉพาะ high; low/medium คงเดิม

## Frontend (ui-styling)

- **ตำแหน่ง**: `AIModelsSection.tsx` ใต้ `ModelConfiguration`
- **Control**: Segmented แนวตั้ง 3 ตัวเลือก แต่ละตัวมี label + speed/quality/cost + latency โดยประมาณ
  ```
  Reasoning Effort
  ┌──────────────────────────────┐
  │ ○ Low     เร็วสุด · ~45s        │
  │ ● Medium  สมดุล · ~60s (แนะนำ) │
  │ ○ High    ฉลาดสุด · ~120s · แพง │
  └──────────────────────────────┘
  ```
- **Gate**: แสดงเฉพาะเมื่อ `primary_chat_model` มี `supports_reasoning = true` (อ่านจาก model list/capabilities ที่ ModelSelector ใช้อยู่) — ถ้าไม่รองรับ: ซ่อน control + hint สั้น ("โมเดลนี้ไม่ใช้ reasoning")
- **Wiring**: เพิ่ม `reasoning_effort` ใน `ConnectionFormData` + `useConnectionForm`, ใช้ `handleChange('reasoning_effort', value)` เหมือน field อื่น
- ใช้ shadcn/ui + Tailwind ตาม pattern เดิม (Panel/ModelConfiguration); styling ผ่าน /ui-ux-pro-max:ui-styling ตอน implement

## Error handling / ความเข้ากันได้

- บอทเดิมที่ยังไม่มีค่า → default `'medium'` = พฤติกรรมเดิม ไม่ regress
- effort ที่ map timeout ไม่เจอ key → fallback ไป `services.openrouter.timeout` (45)
- ถ้าเลือก high แล้ว primary timeout → B1 (client-side fallback, จาก #239) เด้ง fallback model ทันที (fallback ใช้ default effort ของตัวเอง ไม่รับ high มา)

## Testing

- **backend (PHPUnit)**:
  - `RAGService`/`OpenRouterService`: bot effort=high → chat() ได้ `reasoning.effort=high` + timeout=120
  - effort=medium (default) → effort=medium + timeout=60
  - primary model ไม่ reasoning → ไม่ส่ง reasoning payload
  - `UpdateBotRequest`: reject ค่านอก low/medium/high
- **frontend (Vitest)**:
  - control แสดงเมื่อ model รองรับ reasoning, ซ่อนเมื่อไม่รองรับ
  - เปลี่ยนค่า → เรียก handleChange ถูก field/ค่า

## Git plan

1. เอา A1 (config luna) ออกจาก branch `fix/llm-timeout-fallback` (#239) → #239 เป็น timeout fix ล้วน
2. แตก branch `feat/reasoning-effort-ui` ต่อจาก #239 (สืบทอด B1/B2/B4) → PR ใหม่ ชี้ main

## Out of scope (phase หลัง)

- Streaming responses
- per-model effort
- global/workspace-level effort
- ขยาย `OPENROUTER_TIMEOUT` global / `LOG_STACK=stderr` (ops แยก)
