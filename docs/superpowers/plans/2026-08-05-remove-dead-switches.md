# Remove Dead Switches & Unlistened Broadcasts — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ลบสิ่งที่ตรวจแล้วว่า "มีอยู่แต่ไม่ต่อกับอะไร" ออกจาก codebase — broadcast channel ที่ไม่มีใคร subscribe, flag `--debug` ที่ hardcode ไว้, และสวิตช์ 4 ชุดที่ UI/API รับค่าได้แต่ไม่มีโค้ดปลายทางอ่านค่าเลย

**Architecture:** ทุกอย่างในแผนนี้เป็นการ **ลบ** ล้วน ไม่มีการเพิ่ม behavior ใหม่ ไม่มีการเปลี่ยน logic ที่ทำงานอยู่ ทุก field ที่ลบถูก grep ยืนยันแล้วว่า **ไม่มี consumer** — มีแค่ fillable/casts/validation/type declaration/UI toggle ที่เก็บค่าลง DB แล้วไม่มีใครอ่าน

**Tech Stack:** Laravel 12 (PHPUnit, Pint), React 19 + TypeScript (Vite, ESLint, Vitest), PostgreSQL (Neon).

**Evidence:** ผลตรวจ 2026-08-05 — artifact "ใบตรวจสภาพระบบ bot-fb" + การ grep/query prod ซ้ำในเซสชันนี้

## Global Constraints

- **ห้ามแตะ migration ไฟล์เดิม และห้ามสร้าง migration ใหม่** — เจ้าของเลือก "ลบโค้ดก่อน ค่อยลบคอลัมน์ทีหลัง" คอลัมน์ใน production ยังต้องอยู่ครบทุกตัว
- **ห้ามแตะฐานข้อมูล** ไม่ว่ากรณีใด (ไม่มี `php artisan migrate`, ไม่มี SQL)
- ห้ามแก้ไฟล์นอก repo `/Users/jaochai/Code/bot-fb`
- Backend: รัน `./vendor/bin/pint --dirty` ก่อนจบทุก task
- ห้าม `git commit` — Claude เป็นคน commit เอง
- ถ้าเจอไฟล์/บรรทัดที่ไม่ตรงกับที่แผนระบุ **หยุดแล้วเขียนคำถามลง `.superpowers/sdd/2026-08-05-remove-dead-switches/task-N-question.md`** พร้อมตารางทางเลือก อย่าเดาเอง

**Verify commands (ใช้ชุดนี้เท่านั้น อย่าเดา toolchain เอง):**

```bash
cd /Users/jaochai/Code/bot-fb/backend && ./vendor/bin/pint --test && php artisan test
cd /Users/jaochai/Code/bot-fb/frontend && npm run lint && npm run build && npm run test
```

**Baseline ที่วัดจริงก่อนเริ่ม (ต้องได้เท่าเดิมหรือดีกว่าเมื่อจบ):**
- backend: `./vendor/bin/pint --test` = passed · `php artisan test` = 1020 passed, 13 skipped (2677 assertions)
- frontend: `npm run lint` = 0 errors / 24 warnings · `npm run test` = 27 files, 146 tests ผ่านทั้งหมด

---

### Task 1: ลบ broadcast ช่อง `conversation.*` ที่ไม่มีใคร subscribe

**Files:**
- Modify: `backend/app/Events/MessageSent.php` (เมธอด `broadcastOn`)
- Modify: `backend/app/Events/ConversationUpdated.php` (เมธอด `broadcastOn`)
- Modify: `backend/routes/channels.php` (ลบ channel authorization callback)
- Modify: `frontend/src/types/realtime.ts` (ลบ entry ใน `CHANNELS`)

**Interfaces:**
- Consumes: ไม่มี
- Produces: ทั้ง `MessageSent` และ `ConversationUpdated` ยัง broadcast เข้า `bot.{botId}` เหมือนเดิมทุกประการ — payload (`broadcastWith`) และชื่อ event (`broadcastAs`) **ห้ามเปลี่ยน** เพราะ `useBotChannel` ฝั่ง frontend ฟังอยู่จริง

**หลักฐานว่าไม่มีคนฟัง:** ทั้งโปรเจค frontend มี Echo subscription แค่ 2 ที่ — `useBotChannel` (`bot.{id}`) และ `useKnowledgeBaseChannel` (`knowledge-base.{id}`) ทั้งคู่อยู่ใน `frontend/src/hooks/useEcho.ts` ไม่มีจุดใดเรียก `CHANNELS.conversation`

- [ ] **Step 1: ลบ channel ออกจาก event ทั้งสองตัว**

ใน `backend/app/Events/MessageSent.php` เมธอด `broadcastOn()` ลบบรรทัด:

```php
new PrivateChannel('conversation.'.$this->message->conversation_id),
```

เหลือ array ที่มีสมาชิกตัวเดียวคือ `new PrivateChannel('bot.'.$this->message->conversation->bot_id),`

ใน `backend/app/Events/ConversationUpdated.php` เมธอด `broadcastOn()` ลบบรรทัด:

```php
new PrivateChannel('conversation.'.$this->conversation->id),
```

เหลือ `new PrivateChannel('bot.'.$this->conversation->bot_id),`

- [ ] **Step 2: ลบ channel authorization**

ใน `backend/routes/channels.php` ลบทั้งบล็อก `Broadcast::channel('conversation.{conversationId}', ...)` รวม docblock ข้างบนที่บรรยาย channel นี้

จากนั้น **ตรวจว่า `use App\Models\Conversation;` ยังถูกใช้ในไฟล์นี้อีกหรือไม่** — ถ้าไม่เหลือการอ้างอิงแล้วให้ลบบรรทัด `use` นั้นด้วย ถ้ายังเหลือให้คงไว้

- [ ] **Step 3: ลบ channel name ฝั่ง frontend**

ใน `frontend/src/types/realtime.ts` ในออบเจ็กต์ `CHANNELS` ลบบรรทัด:

```ts
conversation: (id: number) => `conversation.${id}`,
```

**ห้ามแตะ `EVENTS`** — ชื่อ event อย่าง `conversation.created` / `conversation.updated` เป็นคนละเรื่องกับชื่อ channel และยังใช้งานอยู่จริงบน `bot.{id}`

- [ ] **Step 4: Verify**

```bash
cd /Users/jaochai/Code/bot-fb && grep -rn "conversation\.'\.\|CHANNELS.conversation" backend/app backend/routes frontend/src
```

ต้องไม่เหลือผลลัพธ์ที่เป็นชื่อ channel (ผลที่เป็นชื่อ event ใน `EVENTS` ถือว่าถูกต้อง) แล้วรัน verify commands ทั้งสองชุด

---

### Task 2: ถอด `--debug` ออกจาก Reverb

**Files:**
- Modify: `backend/docker/start-reverb.sh` (บรรทัด `exec` บรรทัดสุดท้าย)

**Interfaces:**
- Consumes: ไม่มี
- Produces: ไม่มี

- [ ] **Step 1: ลบ flag**

บรรทัดสุดท้ายของ `backend/docker/start-reverb.sh` ปัจจุบันคือ:

```sh
exec php artisan reverb:start --host=0.0.0.0 --port="${PORT:-8080}" --debug
```

แก้เป็น:

```sh
exec php artisan reverb:start --host=0.0.0.0 --port="${PORT:-8080}"
```

**ห้ามแตะบรรทัดอื่นในไฟล์นี้** — บรรทัด `echo ... >&2` ทั้งหมดและ `php artisan config:cache` ต้องคงเดิม

- [ ] **Step 2: Verify**

```bash
cd /Users/jaochai/Code/bot-fb && grep -n "debug" backend/docker/start-reverb.sh
```

ต้องไม่มีผลลัพธ์

---

### Task 3: ลบสวิตช์ 4 ชุดที่ไม่มีโค้ดปลายทาง (เฉพาะโค้ด ไม่แตะ DB)

**Files:**
- Modify: `backend/app/Models/BotSetting.php`, `backend/app/Models/UserSetting.php`, `backend/app/Models/Bot.php`
- Modify: `backend/app/Http/Controllers/Api/BotSettingController.php`
- Modify: `backend/app/Http/Requests/Bot/StoreBotRequest.php`, `backend/app/Http/Requests/Bot/UpdateBotRequest.php`
- Modify: `backend/app/Http/Resources/BotResource.php`
- Modify: `backend/app/OpenApi/OpenApi.php`
- Delete: `backend/app/Models/InjectionAttemptLog.php`
- Modify: `frontend/src/types/api.ts`, `frontend/src/hooks/useConnectionForm.ts`, `frontend/src/pages/EditConnectionPage.tsx`
- Modify: `frontend/src/components/connections/sections/AdvancedOptionsSection.tsx`
- Modify: `docs/services.md`

**Interfaces:**
- Consumes: ไม่มี
- Produces: `BotResource` ยังคืน field อื่นครบเหมือนเดิม · `ConnectionFormData` ยังมี `auto_handover` และ `auto_delivery_enabled` ครบ · `AdvancedOptionsSection` ยังแสดง 2 สวิตช์ที่เหลือ

**หลักฐาน (grep ทั้ง `backend/app` + `frontend/src` แล้วไม่พบ consumer):** `content_filter_enabled` พบแค่ fillable/casts/validation/default/OpenApi doc/TS type · `max_daily_cost`, `max_monthly_cost`, `cost_alert_enabled`, `cost_alert_threshold` พบแค่ fillable/casts (ไม่มีแม้แต่ validation ใน controller) · `use_semantic_router`, `semantic_router_threshold`, `semantic_router_fallback` พบแค่ fillable/casts (ตาราง `semantic_routes` มี 0 แถวบน prod) · `webhook_forwarder_enabled` พบแค่ fillable/casts/FormRequest/Resource/TS type/UI toggle · `InjectionAttemptLog` ไม่ถูกอ้างอิงจากที่ใดเลยนอกจากตัวไฟล์เอง

- [ ] **Step 1: Backend — content filter**

`backend/app/Models/BotSetting.php`: ลบ `'content_filter_enabled',` ออกจาก `$fillable` และลบ `'content_filter_enabled' => 'boolean',` ออกจาก `$casts`

`backend/app/Http/Controllers/Api/BotSettingController.php`:
- ลบบรรทัด validation `'content_filter_enabled' => 'boolean',` พร้อมคอมเมนต์ `// Content moderation` ที่อยู่ติดกันด้านบน (ถ้าคอมเมนต์นั้นครอบเฉพาะบรรทัดนี้)
- ลบบรรทัด `'content_filter_enabled' => true,` ในเมธอดที่สร้าง BotSetting ค่าเริ่มต้น
- ลบบรรทัด `@OA\Property(property="content_filter_enabled", type="boolean"),` ใน docblock

`backend/app/OpenApi/OpenApi.php`: ลบบรรทัด `@OA\Property(property="content_filter_enabled", type="boolean"),`

`backend/app/Models/InjectionAttemptLog.php`: **ลบทั้งไฟล์** (ตารางปลายทางมี 0 แถวและไม่มีโค้ดใดเขียนลงไป — เป็นคู่กับ content filter ที่ไม่เคยทำงาน)

- [ ] **Step 2: Backend — cost limits**

`backend/app/Models/UserSetting.php`:
- ใน `$fillable` ลบ 4 บรรทัด: `'max_daily_cost',` `'max_monthly_cost',` `'cost_alert_enabled',` `'cost_alert_threshold',` พร้อมคอมเมนต์ `// Cost limits` ที่กำกับกลุ่มนี้
- ใน `$casts` ลบ 4 บรรทัด: `'max_daily_cost' => 'decimal:2',` `'max_monthly_cost' => 'decimal:2',` `'cost_alert_enabled' => 'boolean',` `'cost_alert_threshold' => 'integer',` พร้อมคอมเมนต์ `// Cost limits` ที่กำกับกลุ่มนี้

**ห้ามแตะ** field อื่นใน model นี้ โดยเฉพาะกลุ่ม encrypted (`openrouter_api_key`, `line_channel_*`, `easyslip_api_token`) และกลุ่ม `quiet_hours_*` ซึ่งใช้งานจริง

- [ ] **Step 3: Backend — semantic router + webhook forwarder**

`backend/app/Models/Bot.php`:
- ใน `$fillable` ลบ `'use_semantic_router',` `'semantic_router_threshold',` `'semantic_router_fallback',` พร้อมคอมเมนต์ `// Semantic Router settings` และลบ `'webhook_forwarder_enabled',`
- ใน `$casts` ลบ `'use_semantic_router' => 'boolean',` `'semantic_router_threshold' => 'float',` พร้อมคอมเมนต์ `// Semantic Router settings` และลบ `'webhook_forwarder_enabled' => 'boolean',`

`backend/app/Http/Requests/Bot/StoreBotRequest.php`: ลบบรรทัด `'webhook_forwarder_enabled' => ['nullable', 'boolean'],`

`backend/app/Http/Requests/Bot/UpdateBotRequest.php`: ลบบรรทัด `'webhook_forwarder_enabled' => ['sometimes', 'boolean'],`

`backend/app/Http/Resources/BotResource.php`: ลบบรรทัด `'webhook_forwarder_enabled' => $this->webhook_forwarder_enabled ?? false,`

- [ ] **Step 4: Frontend — ลบ type และ UI**

`frontend/src/types/api.ts`: ลบทุกบรรทัดที่ประกาศ `webhook_forwarder_enabled` (มี 3 จุด: 1 required, 2 optional) และลบบรรทัด `content_filter_enabled: boolean;` พร้อมคอมเมนต์ `// Content moderation` ที่กำกับมัน

`frontend/src/hooks/useConnectionForm.ts`: ลบ `webhook_forwarder_enabled` ออกจาก interface `ConnectionFormData`, ออกจากออบเจ็กต์ค่าเริ่มต้น และออกจากจุดที่ map ค่าจาก `existingBot`

`frontend/src/pages/EditConnectionPage.tsx`: ลบบรรทัด `webhook_forwarder_enabled: formData.webhook_forwarder_enabled,` ทั้ง 2 จุด

`frontend/src/components/connections/sections/AdvancedOptionsSection.tsx`: ลบ `<SettingRow label="Webhook Forwarder" ...>` ทั้งบล็อกรวม `<Switch>` ข้างใน **เหลือ 2 SettingRow คือ "Auto Handover" และ "ส่งของอัตโนมัติ"** — import ทั้งหมดยังใช้อยู่ครบ ห้ามลบ import ใดๆ ในไฟล์นี้

- [ ] **Step 5: แก้เอกสารที่โฆษณาไฟล์ที่ไม่มีอยู่จริง**

`docs/services.md`: ลบแถวตารางที่อ้างถึง `CostTrackingService` และลบหัวข้อ `### CostTrackingService` พร้อมเนื้อหาใต้หัวข้อนั้น — ไฟล์ `backend/app/Services/CostTrackingService.php` ถูกลบไปแล้วตั้งแต่ 2026-05-15 เอกสารยังโฆษณาค้างอยู่

- [ ] **Step 6: Verify**

```bash
cd /Users/jaochai/Code/bot-fb && grep -rn "content_filter_enabled\|cost_alert_enabled\|cost_alert_threshold\|max_daily_cost\|max_monthly_cost\|use_semantic_router\|semantic_router_threshold\|semantic_router_fallback\|webhook_forwarder_enabled\|InjectionAttemptLog\|CostTrackingService" backend/app backend/routes frontend/src docs/services.md
```

ต้องไม่เหลือผลลัพธ์เลย (ผลใน `backend/database/migrations/` และ `docs/superpowers/plans/` ไม่นับ เพราะไม่ได้ค้นในนั้น)

จากนั้นรัน verify commands ทั้งสองชุด — ต้องผ่านครบและไม่แย่ลงกว่า baseline

---

## Definition of Done

- [ ] verify command ของ backend ผ่าน (pint clean + test ผ่านหมด)
- [ ] verify command ของ frontend ผ่าน (lint ไม่เกิน 24 warnings และ 0 errors · build สำเร็จ · test 146 ตัวผ่านหมด)
- [ ] `git status` ไม่มีไฟล์ใหม่นอกเหนือจากที่ระบุใน Files ของแต่ละ task
- [ ] ไม่มี migration ใหม่ และไม่มีไฟล์ใน `backend/database/migrations/` ถูกแก้
