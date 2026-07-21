# Quiet Hours (ช่วงเวลาเงียบแจ้งเตือน) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ช่วง 23:00–08:00 (ตั้งค่าได้จากหน้าเว็บ) ระบบหยุดส่งแจ้งเตือนซ้ำทาง Telegram (`delivery:remind` + `delivery:reconcile`) — "ออเดอร์ใหม่!" ยังเด้งปกติ

**Architecture:** Send-time gate — scheduled commands ยังรันทุกรอบตามเดิม แต่เช็ค `UserSetting::quietNow()` ก่อนส่ง Telegram ถ้าอยู่ช่วงเงียบให้ skip โดยไม่แตะ `last_reminded_at` (พ้นช่วงเงียบรอบแรกจะเตือนงานค้างเองอัตโนมัติ) ค่าเวลาเก็บใน `user_settings` แก้ผ่าน SettingsPage

**Tech Stack:** Laravel 13 (PHPUnit), React 19 + TanStack Query + shadcn/ui (Panel/Switch), PostgreSQL/Neon (sqlite ใน tests)

**Spec:** `docs/superpowers/specs/2026-07-21-quiet-hours-design.md`

## Global Constraints

- App timezone = `Asia/Bangkok` (config/app.php) — `now()` เป็นเวลาไทยอยู่แล้ว ห้าม hardcode offset
- Default: เปิดใช้ quiet hours, 23:00–08:00
- ช่วงข้ามเที่ยงคืน (start > end) ต้องรองรับ: quiet เมื่อ `now >= start || now < end`
- เทียบเวลาแบบสตริง `H:i` เสมอ (substr 0,5) — กัน Postgres คืน `HH:MM:SS` แต่ sqlite คืนตามที่เก็บ
- ข้อความ UI เป็นภาษาไทย ตามสไตล์หน้า SettingsPage เดิม
- รัน backend tests ด้วย `cd backend && php artisan test --filter=<Name>`

---

### Task 1: Migration + `UserSetting::quietNow()` + unit tests

**Files:**
- Create: `backend/database/migrations/2026_07_21_100000_add_quiet_hours_to_user_settings.php`
- Modify: `backend/app/Models/UserSetting.php` (เพิ่ม fillable/casts + static method)
- Test: `backend/tests/Unit/UserSettingQuietHoursTest.php`

**Interfaces:**
- Produces: `UserSetting::quietNow(?UserSetting $settings): bool` — คืน `true` เมื่อ "ตอนนี้" อยู่ในช่วงเงียบ; `$settings = null` ใช้ default (เปิด, 23:00–08:00); คอลัมน์ใหม่ `quiet_hours_enabled` (bool), `quiet_hours_start` (time), `quiet_hours_end` (time)

- [ ] **Step 1: เขียน failing unit test**

สร้าง `backend/tests/Unit/UserSettingQuietHoursTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\UserSetting;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserSettingQuietHoursTest extends TestCase
{
    private function settings(array $attrs): UserSetting
    {
        return (new UserSetting)->forceFill($attrs);
    }

    public function test_null_settings_uses_default_quiet_23_to_8(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 02:00'));
        $this->assertTrue(UserSetting::quietNow(null));

        Carbon::setTestNow(Carbon::parse('2026-07-21 12:00'));
        $this->assertFalse(UserSetting::quietNow(null));
    }

    public function test_boundaries_overnight_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 23:00'));
        $this->assertTrue(UserSetting::quietNow(null)); // เริ่มเงียบพอดี

        Carbon::setTestNow(Carbon::parse('2026-07-21 07:59'));
        $this->assertTrue(UserSetting::quietNow(null)); // ยังอยู่ในช่วง

        Carbon::setTestNow(Carbon::parse('2026-07-21 08:00'));
        $this->assertFalse(UserSetting::quietNow(null)); // พ้นช่วงพอดี
    }

    public function test_disabled_is_never_quiet(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 02:00'));
        $s = $this->settings([
            'quiet_hours_enabled' => false,
            'quiet_hours_start' => '23:00', 'quiet_hours_end' => '08:00',
        ]);
        $this->assertFalse(UserSetting::quietNow($s));
    }

    public function test_non_overnight_range(): void
    {
        $s = $this->settings([
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '12:00', 'quiet_hours_end' => '13:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-21 12:30'));
        $this->assertTrue(UserSetting::quietNow($s));

        Carbon::setTestNow(Carbon::parse('2026-07-21 13:00'));
        $this->assertFalse(UserSetting::quietNow($s));
    }

    public function test_postgres_time_format_with_seconds(): void
    {
        // Postgres คืน time เป็น HH:MM:SS — ต้องตัดเหลือ H:i ก่อนเทียบ
        $s = $this->settings([
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => '23:00:00', 'quiet_hours_end' => '08:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-21 02:00'));
        $this->assertTrue(UserSetting::quietNow($s));
    }
}
```

หมายเหตุ: extend `Tests\TestCase` (ไม่ใช้ RefreshDatabase — ไม่แตะ DB) เพราะ `now()` ต้องการ Laravel app + timezone Bangkok; Laravel เคลียร์ `Carbon::setTestNow` ให้เองใน tearDown

- [ ] **Step 2: รัน test ให้ fail**

Run: `cd backend && php artisan test --filter=UserSettingQuietHoursTest`
Expected: FAIL — `Call to undefined method App\Models\UserSetting::quietNow()`

- [ ] **Step 3: สร้าง migration**

สร้าง `backend/database/migrations/2026_07_21_100000_add_quiet_hours_to_user_settings.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->boolean('quiet_hours_enabled')->default(true);
            $table->time('quiet_hours_start')->default('23:00');
            $table->time('quiet_hours_end')->default('08:00');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn(['quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end']);
        });
    }
};
```

- [ ] **Step 4: เพิ่ม fillable/casts + `quietNow()` ใน UserSetting**

แก้ `backend/app/Models/UserSetting.php`:

ใน `$fillable` เพิ่มต่อท้าย (หลัง `'easyslip_api_token',`):

```php
        'quiet_hours_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
```

ใน `$casts` เพิ่ม (หลัง `'cost_alert_threshold' => 'integer',`):

```php
        'quiet_hours_enabled' => 'boolean',
```

เพิ่ม method ใหม่ (วางหลัง `user()` relation):

```php
    /**
     * ตอนนี้อยู่ในช่วงเวลาเงียบแจ้งเตือนซ้ำหรือไม่ — ไม่มี settings row ใช้ default เงียบ 23:00–08:00
     * รองรับช่วงข้ามเที่ยงคืน (start > end) และเทียบแบบ H:i (Postgres คืน HH:MM:SS)
     */
    public static function quietNow(?self $settings): bool
    {
        if (! ($settings?->quiet_hours_enabled ?? true)) {
            return false;
        }

        $start = substr($settings?->quiet_hours_start ?? '23:00', 0, 5);
        $end = substr($settings?->quiet_hours_end ?? '08:00', 0, 5);
        if ($start === $end) {
            return false;
        }

        $now = now()->format('H:i');

        return $start < $end
            ? ($now >= $start && $now < $end)
            : ($now >= $start || $now < $end);
    }
```

หมายเหตุ: ต้องใช้ nullsafe `?->` ทุกจุดที่แตะ `$settings` เพราะรับ null ได้ (user ที่ยังไม่มี settings row) — และ `?? true` ไม่กลืนค่า `false` ที่ตั้งใจปิดสวิตช์ เพราะ `??` ทำงานเฉพาะ null

- [ ] **Step 5: รัน migration + test ให้ผ่าน**

Run: `cd backend && php artisan migrate && php artisan test --filter=UserSettingQuietHoursTest`
Expected: PASS ทั้ง 5 tests

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_07_21_100000_add_quiet_hours_to_user_settings.php backend/app/Models/UserSetting.php backend/tests/Unit/UserSettingQuietHoursTest.php
git commit -m "feat(settings): เพิ่ม quiet hours ใน user_settings + UserSetting::quietNow()"
```

---

### Task 2: Gate `delivery:remind` + `delivery:reconcile` ช่วงเงียบ

**Files:**
- Modify: `backend/app/Console/Commands/RemindPendingDeliveries.php`
- Modify: `backend/app/Console/Commands/ReconcileDeliveries.php` (method `notifyTelegram`, ~บรรทัด 82-100)
- Test: `backend/tests/Feature/RemindPendingDeliveriesTest.php`, `backend/tests/Feature/ReconcileDeliveriesTest.php`

**Interfaces:**
- Consumes: `UserSetting::quietNow(?UserSetting $settings): bool` จาก Task 1
- Produces: ไม่มี (พฤติกรรม command เท่านั้น)

**สำคัญ:** เทสต์เดิมของ 2 ไฟล์นี้รันด้วยเวลาจริง — ถ้า CI รันตอนกลางคืน (เวลาไทย) จะโดน quiet hours default แล้วพังทันที ต้อง pin เวลาเที่ยงวันใน `setUp()` ของทั้ง 2 ไฟล์

- [ ] **Step 1: เขียน failing tests ใน RemindPendingDeliveriesTest**

แก้ `backend/tests/Feature/RemindPendingDeliveriesTest.php`:

เพิ่ม import: `use Illuminate\Support\Carbon;`

เพิ่ม `setUp()` (pin เวลาเที่ยงวัน กันเทสต์เดิมพังตอน CI รันกลางคืน):

```php
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::today()->setTime(12, 0));
    }
```

เพิ่ม 2 tests ท้ายคลาส:

```php
    public function test_skips_reminder_during_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $delivery = $this->makeDelivery(['created_at' => now()->subHour()]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($delivery->fresh()->last_reminded_at); // รอบเช้าต้องเตือนต่อได้
    }

    public function test_reminds_at_night_when_quiet_hours_disabled(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $delivery = $this->makeDelivery(['created_at' => now()->subHour()]);
        $delivery->bot->user->getOrCreateSettings()->update(['quiet_hours_enabled' => false]);

        $this->artisan('delivery:remind')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage'));
    }
```

- [ ] **Step 2: รัน test ให้ fail**

Run: `cd backend && php artisan test --filter=RemindPendingDeliveriesTest`
Expected: `test_skips_reminder_during_quiet_hours` FAIL (ยังส่งอยู่), เทสต์เดิม 2 ตัว + disabled ตัวใหม่ PASS

- [ ] **Step 3: Gate ใน RemindPendingDeliveries**

แก้ `backend/app/Console/Commands/RemindPendingDeliveries.php` — เพิ่ม imports:

```php
use App\Models\UserSetting;
use Illuminate\Support\Facades\Log;
```

แก้ `handle()` เปลี่ยน eager load และ loop:

```php
    public function handle(AccountDeliveryService $service): int
    {
        $threshold = now()->subMinutes(config_int('delivery.remind_after_minutes', 30));

        $pending = AccountDelivery::with('items', 'bot.user.settings', 'conversation')
            ->where('status', AccountDelivery::STATUS_RESERVED)
            ->where('created_at', '<=', $threshold)
            ->where(fn ($q) => $q->whereNull('last_reminded_at')
                ->orWhere('last_reminded_at', '<=', $threshold))
            ->get();

        $skipped = 0;
        foreach ($pending as $delivery) {
            if (UserSetting::quietNow($delivery->bot?->user?->settings)) {
                $skipped++;

                continue;
            }

            $ageMinutes = (int) $delivery->created_at->diffInMinutes(now());
            $service->sendCard($delivery, "⏰ <b>เตือน:</b> งานส่งของค้างมา <code>{$ageMinutes}</code> นาทีแล้ว ยังไม่ได้กดส่ง\n\n");
            $delivery->update(['last_reminded_at' => now()]);
        }

        if ($skipped > 0) {
            Log::info("Delivery remind: quiet hours, skipped {$skipped}");
        }

        $this->info('reminded: '.($pending->count() - $skipped).", quiet-skipped: {$skipped}");

        return self::SUCCESS;
    }
```

- [ ] **Step 4: รัน test ให้ผ่าน**

Run: `cd backend && php artisan test --filter=RemindPendingDeliveriesTest`
Expected: PASS ทั้ง 4 tests

- [ ] **Step 5: เขียน failing test ใน ReconcileDeliveriesTest**

แก้ `backend/tests/Feature/ReconcileDeliveriesTest.php`:

เพิ่ม import: `use Illuminate\Support\Carbon;`

ใน `setUp()` เพิ่มบรรทัดแรกหลัง `parent::setUp();`:

```php
        Carbon::setTestNow(Carbon::today()->setTime(12, 0));
```

เพิ่ม test ท้ายคลาส (arrange เดียวกับ `test_alerts_on_stuck_reserving_delivery` ที่มีอยู่แล้วในไฟล์ แต่ pin เวลาเป็นตี 2):

```php
    public function test_skips_telegram_alert_during_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(2, 0));
        $this->makeDelivery('reserving', ['updated_at' => now()->subMinutes(30)]);

        $this->artisan('delivery:reconcile')->assertSuccessful();

        Http::assertNothingSent();
    }
```

(`makeDelivery('reserving', ['updated_at' => now()->subMinutes(30)])` คือ helper เดิมในไฟล์ — สร้างงานค้างสถานะ reserving เกิน threshold ซึ่งปกติ trigger การแจ้ง Telegram)

- [ ] **Step 6: รัน test ให้ fail**

Run: `cd backend && php artisan test --filter=ReconcileDeliveriesTest`
Expected: test ใหม่ FAIL (ยังส่งอยู่), เทสต์เดิม PASS

- [ ] **Step 7: Gate ใน ReconcileDeliveries**

แก้ `backend/app/Console/Commands/ReconcileDeliveries.php` — เพิ่ม import `use App\Models\UserSetting;` แล้วใน `notifyTelegram()` หลังบรรทัด `if (! $plugin) { ... return; }` เพิ่ม:

```php
        if (UserSetting::quietNow($plugin->flow?->bot?->user?->settings)) {
            Log::info('Reconcile: quiet hours, skipped telegram alert', ['problems' => count($problems)]);

            return;
        }
```

(`FlowPlugin->flow()` และ `Flow->bot()` เป็น BelongsTo ที่มีอยู่แล้ว — ตรวจแล้ว)

- [ ] **Step 8: รัน test ให้ผ่าน + รันชุด delivery ทั้งหมดกัน regression**

Run: `cd backend && php artisan test --filter=ReconcileDeliveriesTest && php artisan test --filter=Delivery`
Expected: PASS ทั้งหมด

- [ ] **Step 9: Commit**

```bash
git add backend/app/Console/Commands/RemindPendingDeliveries.php backend/app/Console/Commands/ReconcileDeliveries.php backend/tests/Feature/RemindPendingDeliveriesTest.php backend/tests/Feature/ReconcileDeliveriesTest.php
git commit -m "feat(delivery): เตือนซ้ำ/reconcile เงียบช่วง quiet hours (default 23:00-08:00)"
```

---

### Task 3: API — `PUT /settings/quiet-hours` + ฟิลด์ใน `GET /settings`

**Files:**
- Modify: `backend/app/Http/Controllers/Api/UserSettingController.php` (`show()` บรรทัด ~16-34 + method ใหม่)
- Modify: `backend/routes/api.php` (settings group บรรทัด ~103-114)
- Test: `backend/tests/Feature/QuietHoursApiTest.php`

**Interfaces:**
- Consumes: คอลัมน์ quiet_hours_* จาก Task 1, `User::getOrCreateSettings()` (มีอยู่แล้ว)
- Produces: `PUT /api/settings/quiet-hours` body `{enabled: bool, start: 'H:i', end: 'H:i'}`; `GET /api/settings` เพิ่มฟิลด์ `quiet_hours_enabled: bool`, `quiet_hours_start: 'H:i'`, `quiet_hours_end: 'H:i'` (Task 4 ใช้ชื่อเหล่านี้)

- [ ] **Step 1: เขียน failing test**

สร้าง `backend/tests/Feature/QuietHoursApiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuietHoursApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_defaults_when_no_settings_row(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.quiet_hours_enabled', true)
            ->assertJsonPath('data.quiet_hours_start', '23:00')
            ->assertJsonPath('data.quiet_hours_end', '08:00');
    }

    public function test_update_and_show_quiet_hours(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->putJson('/api/settings/quiet-hours', [
            'enabled' => true, 'start' => '22:00', 'end' => '09:00',
        ])->assertOk();

        $this->assertDatabaseHas('user_settings', [
            'user_id' => $user->id, 'quiet_hours_enabled' => true,
        ]);

        $this->actingAs($user)->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.quiet_hours_start', '22:00')
            ->assertJsonPath('data.quiet_hours_end', '09:00');
    }

    public function test_invalid_time_format_rejected(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->putJson('/api/settings/quiet-hours', [
            'enabled' => true, 'start' => '25:99', 'end' => '08:00',
        ])->assertStatus(422);
    }

    public function test_same_start_end_rejected(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->putJson('/api/settings/quiet-hours', [
            'enabled' => true, 'start' => '08:00', 'end' => '08:00',
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: รัน test ให้ fail**

Run: `cd backend && php artisan test --filter=QuietHoursApiTest`
Expected: FAIL — show ไม่มีฟิลด์ / route quiet-hours เป็น 404 หรือ 405

- [ ] **Step 3: เพิ่ม route**

แก้ `backend/routes/api.php` ใน `Route::prefix('settings')->group(...)` เพิ่มบรรทัด (ท้าย group):

```php
        Route::put('/quiet-hours', [UserSettingController::class, 'updateQuietHours'])->name('settings.quiet-hours.update');
```

- [ ] **Step 4: เพิ่ม controller method + ฟิลด์ใน show()**

แก้ `backend/app/Http/Controllers/Api/UserSettingController.php`:

ใน `show()` เพิ่มใน array `data` (หลังบรรทัด `'easyslip_token_masked' => ...`):

```php
                'quiet_hours_enabled' => $settings?->quiet_hours_enabled ?? true,
                'quiet_hours_start' => substr($settings?->quiet_hours_start ?? '23:00', 0, 5),
                'quiet_hours_end' => substr($settings?->quiet_hours_end ?? '08:00', 0, 5),
```

เพิ่ม method ใหม่ (วางถัดจาก `updateEasySlip`):

```php
    /**
     * Update quiet hours — ช่วงเวลาเงียบแจ้งเตือนซ้ำ (delivery remind/reconcile).
     */
    public function updateQuietHours(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'start' => 'required|date_format:H:i',
            'end' => 'required|date_format:H:i|different:start',
        ]);

        $settings = $request->user()->getOrCreateSettings();
        $settings->quiet_hours_enabled = $validated['enabled'];
        $settings->quiet_hours_start = $validated['start'];
        $settings->quiet_hours_end = $validated['end'];
        $settings->save();

        return response()->json([
            'message' => 'บันทึกช่วงเวลาเงียบแล้ว',
            'data' => [
                'quiet_hours_enabled' => $settings->quiet_hours_enabled,
                'quiet_hours_start' => substr($settings->quiet_hours_start, 0, 5),
                'quiet_hours_end' => substr($settings->quiet_hours_end, 0, 5),
            ],
        ]);
    }
```

- [ ] **Step 5: รัน test ให้ผ่าน**

Run: `cd backend && php artisan test --filter=QuietHoursApiTest`
Expected: PASS ทั้ง 4 tests

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/UserSettingController.php backend/routes/api.php backend/tests/Feature/QuietHoursApiTest.php
git commit -m "feat(api): endpoint ตั้งค่า quiet hours + คืนค่าใน GET /settings"
```

---

### Task 4: Frontend — การ์ด "ช่วงเวลาเงียบแจ้งเตือน" ใน SettingsPage

**Files:**
- Modify: `frontend/src/types/api.ts` (interface `UserSettings` บรรทัด ~476)
- Modify: `frontend/src/hooks/useUserSettings.ts` (เพิ่ม mutation hook)
- Modify: `frontend/src/pages/SettingsPage.tsx` (เพิ่ม Panel ใหม่)

**Interfaces:**
- Consumes: API จาก Task 3 — `GET /settings` ฟิลด์ `quiet_hours_enabled/start/end`, `PUT /settings/quiet-hours` body `{enabled, start, end}`
- Produces: hook `useUpdateQuietHours()` คืน mutation รับ `{enabled: boolean; start: string; end: string}`

- [ ] **Step 1: เพิ่ม types**

แก้ `frontend/src/types/api.ts` — ใน `interface UserSettings` เพิ่มต่อท้าย (หลัง `easyslip_token_masked`):

```ts
  quiet_hours_enabled: boolean;
  quiet_hours_start: string; // 'HH:MM'
  quiet_hours_end: string; // 'HH:MM'
```

- [ ] **Step 2: เพิ่ม mutation hook**

แก้ `frontend/src/hooks/useUserSettings.ts` เพิ่มท้ายไฟล์:

```ts
// Update quiet hours (ช่วงเวลาเงียบแจ้งเตือนซ้ำ)
export function useUpdateQuietHours() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: { enabled: boolean; start: string; end: string }) => {
      const response = await apiPut<ApiResponse<UserSettings>>('/settings/quiet-hours', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.settings.user() });
    },
  });
}
```

- [ ] **Step 3: เพิ่มการ์ดใน SettingsPage**

แก้ `frontend/src/pages/SettingsPage.tsx`:

imports: เพิ่ม `Moon` ใน import จาก `lucide-react` (มี import block อยู่แล้ว), เพิ่ม `Switch`:

```ts
import { Switch } from '@/components/ui/switch';
```

และเพิ่ม `useUpdateQuietHours` ใน import จาก `@/hooks/useUserSettings` (block เดียวกับ `useUpdateEasySlipToken`)

ใน component เพิ่ม state + handler (วางใกล้กลุ่ม EasySlip state บรรทัด ~45-60):

```tsx
  const [quietEnabled, setQuietEnabled] = useState(true);
  const [quietStart, setQuietStart] = useState('23:00');
  const [quietEnd, setQuietEnd] = useState('08:00');
  const updateQuietHoursMutation = useUpdateQuietHours();

  useEffect(() => {
    if (settings) {
      setQuietEnabled(settings.quiet_hours_enabled);
      setQuietStart(settings.quiet_hours_start);
      setQuietEnd(settings.quiet_hours_end);
    }
  }, [settings]);

  const handleSaveQuietHours = async () => {
    if (quietStart === quietEnd) {
      toast.error('เวลาเริ่มและสิ้นสุดต้องไม่เท่ากัน');
      return;
    }
    try {
      await updateQuietHoursMutation.mutateAsync({
        enabled: quietEnabled, start: quietStart, end: quietEnd,
      });
      toast.success('บันทึกช่วงเวลาเงียบแล้ว');
    } catch {
      toast.error('บันทึกช่วงเวลาเงียบไม่สำเร็จ');
    }
  };
```

เพิ่ม Panel ใหม่ต่อจากการ์ด EasySlip (หลัง `</Panel>` ของ EasySlip บรรทัด ~390):

```tsx
      <Panel
        icon={Moon}
        title="ช่วงเวลาเงียบแจ้งเตือน"
        description="ช่วงเวลานี้จะเงียบเฉพาะแจ้งเตือนซ้ำ (งานค้างกดยืนยัน/ของค้างสต๊อก) — ออเดอร์ใหม่ยังแจ้งเตือนปกติ"
        actions={
          <Switch checked={quietEnabled} onCheckedChange={setQuietEnabled} />
        }
      >
        <div className="space-y-4">
          <div className="flex flex-wrap items-end gap-4">
            <div className="space-y-2">
              <Label htmlFor="quiet-start">เริ่มเงียบ</Label>
              <Input
                id="quiet-start"
                type="time"
                value={quietStart}
                onChange={(e) => setQuietStart(e.target.value)}
                disabled={!quietEnabled}
                className="w-32"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="quiet-end">สิ้นสุด</Label>
              <Input
                id="quiet-end"
                type="time"
                value={quietEnd}
                onChange={(e) => setQuietEnd(e.target.value)}
                disabled={!quietEnabled}
                className="w-32"
              />
            </div>
            <Button
              onClick={handleSaveQuietHours}
              disabled={updateQuietHoursMutation.isPending}
            >
              {updateQuietHoursMutation.isPending ? 'กำลังบันทึก...' : 'บันทึก'}
            </Button>
          </div>
          <p className="text-sm text-muted-foreground">
            ค่าเริ่มต้น 23:00–08:00 ตามเวลาไทย — พ้นช่วงเงียบแล้วงานที่ค้างจะถูกเตือนทันทีในรอบถัดไป
          </p>
        </div>
      </Panel>
```

(ปรับตำแหน่ง/prop ให้เข้ากับ `Panel` component จริง — ดู pattern การ์ด EasySlip ในไฟล์เดียวกันเป็นหลัก ถ้า `Panel` ไม่รองรับ prop ไหนให้จัดวางแบบเดียวกับการ์ดเดิม)

- [ ] **Step 4: ตรวจ type + build ผ่าน**

Run: `cd frontend && npm run build`
Expected: build สำเร็จ ไม่มี TS error

- [ ] **Step 5: Commit**

```bash
git add frontend/src/types/api.ts frontend/src/hooks/useUserSettings.ts frontend/src/pages/SettingsPage.tsx
git commit -m "feat(settings): การ์ดตั้งค่าช่วงเวลาเงียบแจ้งเตือนในหน้า Settings"
```

---

## Final Verification (หลังครบ 4 tasks)

- [ ] `cd backend && php artisan test` — ทั้ง suite ผ่าน
- [ ] `cd frontend && npm run build` — ผ่าน
- [ ] ไล่เช็คกับ spec: เงียบเฉพาะ remind+reconcile ✓, ออเดอร์ใหม่ไม่ถูกแตะ (ไม่มีการแก้ path สร้างงาน/การ์ดแรก) ✓, ตั้งค่าจากเว็บ ✓, default 23:00–08:00 ✓
