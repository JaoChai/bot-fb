# สวิตช์เปิด/ปิดส่งของ Auto รายบอท — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** เจ้าของเปิด/ปิดการส่งบัญชีอัตโนมัติรายบอทได้จากหน้าเว็บ Connection Settings มีผลทันทีไม่ต้อง redeploy

**Architecture:** เพิ่มคอลัมน์ `bots.auto_delivery_enabled` (boolean, default false) ตาม pattern `auto_handover` เป๊ะทุกชั้น (migration → model → gate ใน services → FormRequest → Resource → React form) แล้วตัด `ACCOUNT_DELIVERY_BOT_IDS` ทิ้งเพราะคอลัมน์รายบอทแทนหน้าที่เลือกบอทแล้ว — เหลือ env `ACCOUNT_DELIVERY_ENABLED` เป็น master kill switch

**Tech Stack:** Laravel 13 (PHPUnit 12 class-style tests), React 19 + TypeScript, shadcn/ui Switch

**Spec:** `docs/superpowers/specs/2026-07-10-delivery-auto-toggle-design.md`

## Global Constraints

- ทำบน branch `feat/delivery-auto-toggle` — **rebase จาก main ล่าสุดก่อนเริ่ม** (มี session อื่นกำลังทำ Wave 2 hardening แตะ `AccountDeliveryService.php` เหมือนกัน — ถ้า conflict ให้ยึดโค้ด main แล้ววาง gate ใหม่ทับตาม Task 2)
- ห้ามแตะ working tree หลักถ้า session อื่นยังมีไฟล์ค้าง — ทำใน worktree `.claude/worktrees/delivery-toggle`
- ทุก gate ต้องเป็น `config('delivery.enabled') && $bot->auto_delivery_enabled` — env เป็น AND ไม่ใช่ OR
- ค่า default ของคอลัมน์ = `false` เสมอ (บอทเก่า/ใหม่ไม่ส่งของจนกว่าจะเปิดเอง)
- backend test: `cd backend && php artisan test --filter=<ชื่อ>` · frontend: `cd frontend && npx tsc --noEmit && npm run build`
- `/simplify` ก่อน commit สุดท้าย (กติกาโปรเจกต์)
- ห้าม log / เก็บ credential — งานนี้ไม่แตะ credential path อยู่แล้ว อย่าไปเปลี่ยน

---

### Task 1: Migration + Bot model

**Files:**
- Create: `backend/database/migrations/2026_07_10_200000_add_auto_delivery_enabled_to_bots_table.php`
- Modify: `backend/app/Models/Bot.php` (fillable ~บรรทัด 42, casts ~บรรทัด 67)
- Test: `backend/tests/Feature/BotAutoDeliveryToggleTest.php` (ใหม่)

**Interfaces:**
- Produces: คอลัมน์ `bots.auto_delivery_enabled` (boolean NOT NULL DEFAULT false) + `$bot->auto_delivery_enabled` (bool cast) — Task 2,3,4,6 ใช้

- [ ] **Step 1: เขียน failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotAutoDeliveryToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_delivery_enabled_defaults_to_false(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $this->assertFalse($bot->fresh()->auto_delivery_enabled);
    }

    public function test_auto_delivery_enabled_is_fillable_and_cast_to_bool(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $bot->update(['auto_delivery_enabled' => 1]);

        $this->assertTrue($bot->fresh()->auto_delivery_enabled);
    }
}
```

- [ ] **Step 2: รันให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=BotAutoDeliveryToggleTest`
Expected: FAIL (column not found / attribute null)

- [ ] **Step 3: สร้าง migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->boolean('auto_delivery_enabled')->default(false)->after('auto_handover');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('auto_delivery_enabled');
        });
    }
};
```

- [ ] **Step 4: แก้ `Bot.php`** — ใน `$fillable` เพิ่มบรรทัดต่อจาก `'auto_handover',`:

```php
        'auto_delivery_enabled',
```

ใน `$casts` เพิ่มต่อจาก `'auto_handover' => 'boolean',`:

```php
        'auto_delivery_enabled' => 'boolean',
```

- [ ] **Step 5: รัน test ให้ผ่าน**

Run: `cd backend && php artisan test --filter=BotAutoDeliveryToggleTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations backend/app/Models/Bot.php backend/tests/Feature/BotAutoDeliveryToggleTest.php
git commit -m "feat(delivery): คอลัมน์ auto_delivery_enabled บนตาราง bots"
```

---

### Task 2: Gate ใน AccountDeliveryService

**Files:**
- Modify: `backend/app/Services/Delivery/AccountDeliveryService.php` (gate ต้นเมธอด `createFromPayment`)
- Test: `backend/tests/Feature/AccountDeliveryCreateTest.php` (แก้ setUp + เพิ่ม test)

**Interfaces:**
- Consumes: `$bot->auto_delivery_enabled` จาก Task 1
- Produces: พฤติกรรม "ปิดสวิตช์ → คืน null ไม่แตะ stock" ที่ Task 5 (ชุด test เดิม) พึ่ง

- [ ] **Step 1: แก้ setUp ของ `AccountDeliveryCreateTest`** — แทนบรรทัด `config(['delivery.enabled' => true, 'delivery.bot_ids' => [$this->bot->id]]);` ด้วย:

```php
        config(['delivery.enabled' => true]);
        $this->bot->update(['auto_delivery_enabled' => true]);
        $this->bot = $this->bot->fresh();
```

- [ ] **Step 2: เพิ่ม failing tests ท้ายไฟล์เดียวกัน**

```php
    public function test_returns_null_when_bot_auto_delivery_disabled(): void
    {
        $this->seedAvailable(10, 'NLMP');
        $this->bot->update(['auto_delivery_enabled' => false]);
        $this->bot = $this->bot->fresh();

        $delivery = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1,299 บาท']]);

        $this->assertNull($delivery);
        $this->assertDatabaseCount('account_deliveries', 0);
        $this->assertSame(10, $this->countAvailable('NLMP')); // ไม่แตะ stock
    }

    public function test_returns_null_when_master_env_disabled_even_if_bot_enabled(): void
    {
        $this->seedAvailable(10, 'NLMP');
        config(['delivery.enabled' => false]);

        $delivery = $this->create([['name' => 'Nolimit ส่วนตัว', 'total' => '1,299 บาท']]);

        $this->assertNull($delivery);
        $this->assertDatabaseCount('account_deliveries', 0);
    }
```

หมายเหตุ: ถ้า trait `InteractsWithStockPool` ไม่มี helper `countAvailable` ให้ใช้แถวนับตรงจาก connection stock pool ตามที่ trait ใช้ (ดูเมธอด `seedAvailable` ในไฟล์ `backend/tests/Support/InteractsWithStockPool.php` แล้วนับจากตารางเดียวกัน)

- [ ] **Step 3: รันให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=AccountDeliveryCreateTest`
Expected: test ใหม่ FAIL (ยังสร้าง delivery เพราะ gate เช็ค bot_ids ซึ่งไม่ตรงแล้ว → จริงๆ setUp ที่แก้จะทำให้ test เดิม fail ด้วย — นั่นคือสัญญาณถูกต้อง)

- [ ] **Step 4: แก้ gate ใน `createFromPayment`** — แทนบรรทัด:

```php
        if (! config('delivery.enabled') || ! in_array($bot->id, config('delivery.bot_ids'), true)) {
            return null;
        }
```

ด้วย:

```php
        if (! config('delivery.enabled') || ! $bot->auto_delivery_enabled) {
            return null;
        }
```

- [ ] **Step 5: รันทั้งไฟล์ให้ผ่าน**

Run: `cd backend && php artisan test --filter=AccountDeliveryCreateTest`
Expected: PASS ทุก test

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Delivery/AccountDeliveryService.php backend/tests/Feature/AccountDeliveryCreateTest.php
git commit -m "feat(delivery): gate สร้างงานส่งของด้วยสวิตช์รายบอทแทน bot_ids"
```

---

### Task 3: Gate ใน SlipVerificationService + ReconcileDeliveries

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationService.php` (เมธอด `recentManualConfirmExists` ~บรรทัด 250)
- Modify: `backend/app/Console/Commands/ReconcileDeliveries.php` (เมธอด `fallbackPlugin` ~บรรทัด 103 + docblock ~บรรทัด 83)
- Test: `backend/tests/Feature/SlipVerificationServiceTest.php`, `backend/tests/Feature/ReconcileDeliveriesTest.php`

**Interfaces:**
- Consumes: `$bot->auto_delivery_enabled` จาก Task 1
- Produces: dedup guard (PR #217) กับ reconcile fallback ทำงานตามสวิตช์บอทแทน env list

- [ ] **Step 1: แก้ test setup ทั้งสองไฟล์** — ทุกจุดที่มี `config(['delivery.bot_ids' => ...])` หรือ `'delivery.bot_ids' => [...]`:
  - `SlipVerificationServiceTest.php` (~บรรทัด 114): แทน `config(['delivery.enabled' => true, 'delivery.bot_ids' => [$this->bot->id]]);` ด้วย `config(['delivery.enabled' => true]); $this->bot->update(['auto_delivery_enabled' => true]);`
  - `ReconcileDeliveriesTest.php` (~บรรทัด 169): แทน `config(['delivery.bot_ids' => [$this->bot->id]]);` ด้วย `$this->bot->update(['auto_delivery_enabled' => true]);`
  - grep ยืนยันไม่เหลือ: `grep -rn "delivery.bot_ids" backend/tests/Feature/SlipVerificationServiceTest.php backend/tests/Feature/ReconcileDeliveriesTest.php` ต้องว่าง

- [ ] **Step 2: รันให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter="SlipVerificationServiceTest|ReconcileDeliveriesTest"`
Expected: test ที่พึ่ง dedup guard / fallback plugin FAIL

- [ ] **Step 3: แก้ `SlipVerificationService::recentManualConfirmExists`** — แทน:

```php
        if (! config('delivery.enabled') || ! in_array($bot->id, config('delivery.bot_ids', []), true)) {
            return false;
        }
```

ด้วย:

```php
        if (! config('delivery.enabled') || ! $bot->auto_delivery_enabled) {
            return false;
        }
```

- [ ] **Step 4: แก้ `ReconcileDeliveries::fallbackPlugin`** — แทนทั้งเมธอด (รวม docblock):

```php
    /** ไม่มีงานส่งของเลย → หา plugin จาก bot ที่เปิดสวิตช์ส่งของ auto */
    private function fallbackPlugin(): ?FlowPlugin
    {
        foreach (Bot::where('auto_delivery_enabled', true)->get() as $bot) {
            $flow = $bot->defaultFlow;
            $plugin = $flow?->plugins()->where('type', 'telegram')->where('enabled', true)->first();
            if ($plugin) {
                return $plugin;
            }
        }

        return null;
    }
```

และแก้ docblock ของ `notifyTelegram` จาก `...fallback ไปหา plugin จาก config('delivery.bot_ids')` เป็น `...fallback ไปหา plugin จากบอทที่เปิด auto_delivery_enabled`

- [ ] **Step 5: รันให้ผ่าน**

Run: `cd backend && php artisan test --filter="SlipVerificationServiceTest|ReconcileDeliveriesTest"`
Expected: PASS ทุก test

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Payment/SlipVerificationService.php backend/app/Console/Commands/ReconcileDeliveries.php backend/tests/Feature/SlipVerificationServiceTest.php backend/tests/Feature/ReconcileDeliveriesTest.php
git commit -m "feat(delivery): dedup guard + reconcile fallback ใช้สวิตช์รายบอท"
```

---

### Task 4: ตัด bot_ids ออกจาก config + เก็บกวาด test ที่เหลือ

**Files:**
- Modify: `backend/config/delivery.php` (ลบ block `bot_ids`)
- Modify: `backend/tests/Feature/StockPoolConnectionTest.php` (~บรรทัด 29), `backend/tests/Feature/ReserveAccountStockDispatchTest.php` (~บรรทัด 68)

**Interfaces:**
- Consumes: gates ทั้งหมดเลิกอ่าน `delivery.bot_ids` แล้ว (Task 2–3)
- Produces: config สะอาด — `grep -rn "bot_ids" backend/app backend/config backend/tests` เหลือ 0 จุด (ยกเว้นไฟล์อื่นที่ไม่ใช่ delivery ถ้ามี)

- [ ] **Step 1: ลบออกจาก `config/delivery.php`** — ลบ block นี้ทั้งก้อน:

```php
    // จำกัดเฉพาะ bot ที่เปิดใช้ (คั่นด้วย comma เช่น "26")
    'bot_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('ACCOUNT_DELIVERY_BOT_IDS', '')),
    ))),
```

- [ ] **Step 2: แก้ test ที่เหลือ**
  - `StockPoolConnectionTest.php`: ลบบรรทัด `$this->assertIsArray(config('delivery.bot_ids'));` (assertion ตรวจ config key ที่ไม่มีแล้ว)
  - `ReserveAccountStockDispatchTest.php`: แทน `config(['delivery.enabled' => true, 'delivery.bot_ids' => [$bot->id]]);` ด้วย `config(['delivery.enabled' => true]); $bot->update(['auto_delivery_enabled' => true]);`

- [ ] **Step 3: grep กวาดซ้ำทั้ง repo**

Run: `grep -rn "delivery.bot_ids\|ACCOUNT_DELIVERY_BOT_IDS" backend/ --include="*.php"`
Expected: ไม่มีผลลัพธ์

- [ ] **Step 4: รัน delivery suite ทั้งหมด**

Run: `cd backend && php artisan test --filter="Delivery|StockPool|SlipVerification|ReserveAccountStock|Reconcile"`
Expected: PASS ทั้งหมด

- [ ] **Step 5: Commit**

```bash
git add backend/config/delivery.php backend/tests/Feature/StockPoolConnectionTest.php backend/tests/Feature/ReserveAccountStockDispatchTest.php
git commit -m "refactor(delivery): ตัด ACCOUNT_DELIVERY_BOT_IDS — สวิตช์รายบอทแทน"
```

---

### Task 5: API layer — FormRequests + BotResource

**Files:**
- Modify: `backend/app/Http/Requests/Bot/UpdateBotRequest.php` (rules, ใต้ `'auto_handover'`)
- Modify: `backend/app/Http/Requests/Bot/StoreBotRequest.php` (rules, ใต้ `'auto_handover'`)
- Modify: `backend/app/Http/Resources/BotResource.php` (ใต้ `'auto_handover'` ~บรรทัด 47)
- Test: `backend/tests/Feature/BotAutoDeliveryToggleTest.php` (เพิ่ม test API)

**Interfaces:**
- Consumes: คอลัมน์จาก Task 1
- Produces: field `auto_delivery_enabled` ใน API request/response ที่ frontend (Task 6) ใช้ — ชื่อ field ตรงกันเป๊ะ

- [ ] **Step 1: เพิ่ม failing test ใน `BotAutoDeliveryToggleTest`** — ดู pattern การยิง API + auth จาก test bot update ที่มีอยู่ (เช่น grep `putJson.*bots` ใน backend/tests) แล้วเขียน:

```php
    public function test_owner_can_toggle_auto_delivery_via_api(): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/bots/{$bot->id}", [
            'auto_delivery_enabled' => true,
        ]);

        $response->assertOk()->assertJsonPath('data.auto_delivery_enabled', true);
        $this->assertTrue($bot->fresh()->auto_delivery_enabled);
    }
```

(ถ้า route จริงไม่ใช่ `PUT /api/bots/{id}` ให้ดูจาก `backend/routes/api.php` — ใช้ endpoint เดียวกับที่หน้า EditConnection บันทึก `auto_handover`)

- [ ] **Step 2: รันให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=BotAutoDeliveryToggleTest`
Expected: FAIL (field ถูก validation ตัดทิ้ง / ไม่อยู่ใน response)

- [ ] **Step 3: แก้ 3 ไฟล์**

`UpdateBotRequest.php` — ใต้ `'auto_handover' => ['sometimes', 'boolean'],`:

```php
            // Auto account delivery
            'auto_delivery_enabled' => ['sometimes', 'boolean'],
```

`StoreBotRequest.php` — ใต้ `'auto_handover' => ['nullable', 'boolean'],`:

```php
            // Auto account delivery
            'auto_delivery_enabled' => ['nullable', 'boolean'],
```

`BotResource.php` — ใต้ `'auto_handover' => $this->auto_handover ?? false,`:

```php
            // Auto account delivery setting
            'auto_delivery_enabled' => $this->auto_delivery_enabled ?? false,
```

- [ ] **Step 4: รันให้ผ่าน**

Run: `cd backend && php artisan test --filter=BotAutoDeliveryToggleTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Requests/Bot backend/app/Http/Resources/BotResource.php backend/tests/Feature/BotAutoDeliveryToggleTest.php
git commit -m "feat(delivery): expose auto_delivery_enabled ผ่าน bot API"
```

---

### Task 6: Frontend — สวิตช์ในหน้า Connection Settings

**Files:**
- Modify: `frontend/src/types/api.ts` (Bot interface ~บรรทัด 65, CreateConnectionData ~บรรทัด 104, UpdateConnectionData ~บรรทัด 117)
- Modify: `frontend/src/hooks/useConnectionForm.ts` (interface ~บรรทัด 15, default ~บรรทัด 28, hydrate ~บรรทัด 59)
- Modify: `frontend/src/components/connections/sections/AdvancedOptionsSection.tsx` (เพิ่ม SettingRow)
- Modify: `frontend/src/pages/EditConnectionPage.tsx` (payload update ~บรรทัด 133, payload create ~บรรทัด 152)

**Interfaces:**
- Consumes: API field `auto_delivery_enabled` จาก Task 5 (ชื่อเดียวกันทุกชั้น)
- Produces: สวิตช์ UI "ส่งของอัตโนมัติ" ใน Panel "ตัวเลือกขั้นสูง"

- [ ] **Step 1: `types/api.ts`** — เพิ่มใต้ `auto_handover: boolean;` (Bot interface):

```ts
  auto_delivery_enabled: boolean;
```

เพิ่มใต้ `auto_handover?: boolean;` ทั้งใน `CreateConnectionData` และ `UpdateConnectionData`:

```ts
  auto_delivery_enabled?: boolean;
```

- [ ] **Step 2: `useConnectionForm.ts`** — 3 จุดตาม pattern `auto_handover` บรรทัด 15/28/59:

```ts
  auto_delivery_enabled: boolean;      // ใน interface ConnectionFormData
  auto_delivery_enabled: false,        // ใน default form state
  auto_delivery_enabled: existingBot.auto_delivery_enabled || false,  // ใน hydrate จาก existingBot
```

- [ ] **Step 3: `AdvancedOptionsSection.tsx`** — เพิ่ม SettingRow ต่อจาก block "Auto Handover" (ก่อนปิด `</div>`):

```tsx
        <SettingRow
          label="ส่งของอัตโนมัติ"
          description="เปิด: จ่ายเงินผ่านแล้วบอทจองของ+ส่งการ์ดให้กดส่งใน Telegram · ปิด: แจ้งเตือนรับเงินปกติ แล้วส่งของเอง"
          htmlFor="auto-delivery"
        >
          <Switch
            id="auto-delivery"
            checked={formData.auto_delivery_enabled}
            onCheckedChange={(checked) => handleChange('auto_delivery_enabled', checked)}
          />
        </SettingRow>
```

- [ ] **Step 4: `EditConnectionPage.tsx`** — เพิ่ม `auto_delivery_enabled: formData.auto_delivery_enabled,` ใต้บรรทัด `auto_handover: formData.auto_handover,` ทั้ง 2 จุด (payload update ~133 และ create ~152)

- [ ] **Step 5: Typecheck + build**

Run: `cd frontend && npx tsc --noEmit && npm run build`
Expected: ผ่านทั้งคู่ ไม่มี type error

- [ ] **Step 6: Commit**

```bash
git add frontend/src/types/api.ts frontend/src/hooks/useConnectionForm.ts frontend/src/components/connections/sections/AdvancedOptionsSection.tsx frontend/src/pages/EditConnectionPage.tsx
git commit -m "feat(delivery): สวิตช์ส่งของอัตโนมัติในหน้า Connection Settings"
```

---

### Task 7: Full suite + simplify + PR

**Files:**
- ไม่มีไฟล์ใหม่ — verification + cleanup เท่านั้น

- [ ] **Step 1: รัน backend ทั้ง suite**

Run: `cd backend && php artisan test`
Expected: PASS ทั้งหมด (ถ้ามี fail ที่ไม่เกี่ยวกับงานนี้ ให้เทียบกับ main ก่อนโทษตัวเอง — รัน `git stash && php artisan test --filter=<ตัวที่พัง>` เช็ค baseline)

- [ ] **Step 2: `/simplify`** — รีวิวโค้ดที่แก้ทั้งหมดตามกติกาโปรเจกต์ (dedup/altitude/reuse) แล้ว commit fix ถ้ามี

- [ ] **Step 3: เปิด PR**

```bash
git push -u origin feat/delivery-auto-toggle
gh pr create --title "feat(delivery): สวิตช์เปิด/ปิดส่งของ auto รายบอท" --body "..."
```

เนื้อหา PR body: สรุป spec + จุดแก้ 10 จุด + test ครอบ + ops note ด้านล่าง

- [ ] **Step 4: Ops note ใน PR body (ทำหลัง merge — ไม่ใช่โค้ด)**
  - ลบ `ACCOUNT_DELIVERY_BOT_IDS` ออกจาก Railway backend service (ตัวแปรค้างเป็น staged changes อยู่)
  - apply staged env changes + redeploy → `ACCOUNT_DELIVERY_ENABLED=true` มีผล
  - เปิดสวิตช์บอท 26 ในหน้า Connection Settings ตอนพร้อมทดสอบ E2E

## Self-Review (ทำแล้ว)

- Spec coverage: gate 3 จุด (Task 2,3), config ตัด bot_ids (Task 4), API (Task 5), UI (Task 6), test ครบ 6 ข้อของ spec (Task 1,2,3,5 + full suite Task 7) ✓
- ไม่มี placeholder — โค้ดจริงทุก step ✓
- ชื่อ field `auto_delivery_enabled` ตรงกันทุกชั้น: DB/model/request/resource/types/form ✓
