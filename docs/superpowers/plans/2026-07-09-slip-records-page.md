# Slip Records Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** เพิ่มหน้า "สลิป / การชำระเงิน" (owner-only) แสดงรายการผลตรวจสลิป EasySlip จากตาราง `slip_verifications` พร้อม filter/ค้นหา/แบ่งหน้า/สรุปยอด

**Architecture:** Backend เพิ่ม 1 endpoint `GET /api/slips` (owner-only + scope ตาม bot ของ user) คืน list + summary; ไม่แตะ DB schema เดิม. Frontend เพิ่มหน้า `SlipsPage` ลอก pattern จาก `OrdersPage`/`VipManagementPage` (React Query server-side pagination) + เมนู sidebar owner-only.

**Tech Stack:** Laravel 12 + Sanctum + Pest/PHPUnit (backend), React 19 + react-router v8 + React Query v5 + Tailwind v4 + shadcn/ui (frontend). โค้ดจริงอยู่ใต้ `backend/` และ `frontend/`.

## Global Constraints

- **โค้ด backend อยู่ใต้ `backend/`, frontend ใต้ `frontend/`** — path ในแผนนี้เขียนเทียบจาก repo root
- **ไม่แตะ DB schema** — ใช้ตาราง `slip_verifications` เดิม (มี index `['bot_id','created_at']` รองรับ query อยู่แล้ว)
- **owner-only 2 ชั้น:** backend คืน 403 ถ้า `! $user->isOwner()` + scope query ด้วย `$user->bots()->pluck('id')`; frontend ซ่อนเมนูด้วย `user?.role === 'owner'`
- **Timezone:** DB เก็บ UTC ร้านอยู่ Bangkok (+7h). Frontend คำนวณช่วงวัน (Bangkok) แล้วส่งเป็น UTC ISO ให้ backend เทียบ `created_at` ตรงๆ — backend ไม่ยุ่ง timezone, ห้ามใช้ `NOW()`/`CURDATE()` ใน SQL
- **raw_response อาจ null** (เคส error ระบบ) — ทุกจุดที่อ่านจาก raw ต้อง null-safe
- **UI ภาษาไทยทั้งหมด**, named export ทุก page component (`export function SlipsPage()`)

### Status values + การจัดกลุ่ม (ใช้ทั้ง backend และ frontend)

| status | ความหมาย (label ไทย) | กลุ่ม | สี badge |
|--------|----------------------|-------|----------|
| `passed` | ผ่าน | ผ่าน | success (เขียว) |
| `fake` | ปลอม | ผิดปกติ | destructive (แดง) |
| `wrong_account` | บัญชีผิด | ผิดปกติ | destructive (แดง) |
| `duplicate` | สลิปซ้ำ | ผิดปกติ | warning (ส้ม) |
| `amount_mismatch` | ยอดไม่ตรง | ผิดปกติ | warning (ส้ม) |
| `no_pending_order` | ไม่มีออเดอร์ค้าง | ผิดปกติ | warning (ส้ม) |
| `unreadable` | อ่านสลิปไม่ได้ | error ระบบ | secondary (เทา) |
| `api_error` | API ผิดพลาด | error ระบบ | secondary (เทา) |
| `config_error` | ตั้งค่าไม่ครบ | error ระบบ | secondary (เทา) |
| `image_download_failed` | โหลดรูปไม่ได้ | error ระบบ | secondary (เทา) |
| `pending` | รอธนาคารยืนยัน | error ระบบ | secondary (เทา) |

- **กลุ่ม "ผิดปกติ" (abnormal):** `fake, wrong_account, duplicate, amount_mismatch, no_pending_order`
- **กลุ่ม "error ระบบ" (system_error):** `unreadable, api_error, config_error, image_download_failed, pending`

---

## File Structure

**Backend (สร้างใหม่):**
- `backend/app/Http/Controllers/Api/SlipController.php` — controller `index()` เดียว
- `backend/app/Http/Resources/SlipResource.php` — แปลง model → array (null-safe raw passthrough)
- `backend/database/factories/SlipVerificationFactory.php` — factory สำหรับ test
- `backend/tests/Feature/SlipControllerTest.php` — Feature tests

**Backend (แก้):**
- `backend/routes/api.php` — เพิ่ม route ในกลุ่ม `auth:sanctum`
- `backend/app/Models/SlipVerification.php` — เพิ่ม `use HasFactory` (ถ้ายังไม่มี)

**Frontend (สร้างใหม่):**
- `frontend/src/hooks/useSlips.ts` — React Query hook
- `frontend/src/pages/SlipsPage.tsx` — หน้าตาราง
- `frontend/src/lib/slipStatus.ts` — helper: label/สี/กลุ่ม/ช่วงวัน Bangkok

**Frontend (แก้):**
- `frontend/src/types/api.ts` — เพิ่ม type `Slip`, `SlipSummary`, `SlipsResponse`, `SlipFilters`
- `frontend/src/router.tsx` — lazy import + route `slips`
- `frontend/src/components/layout/Sidebar.tsx` — เมนู owner-only
- `frontend/src/components/layout/MobileNav.tsx` — เมนู owner-only

---

## Task 1: Backend API `GET /api/slips`

**Files:**
- Create: `backend/database/factories/SlipVerificationFactory.php`
- Create: `backend/app/Http/Resources/SlipResource.php`
- Create: `backend/app/Http/Controllers/Api/SlipController.php`
- Create: `backend/tests/Feature/SlipControllerTest.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Models/SlipVerification.php` (เพิ่ม `HasFactory`)

**Interfaces:**
- Produces: `GET /api/slips?bot_id&status&date_from&date_to&search&page&per_page` →
  ```json
  {
    "data": [{ "id": 1, "created_at": "2026-07-09T03:00:00+00:00", "status": "passed",
               "status_label": "ผ่าน", "amount": 1500.0, "trans_ref": "TR001",
               "receiver_account": "xxx-x-x4880-x", "customer_name": "Alice",
               "conversation_id": 12, "raw": { "...data node หรือ null" } }],
    "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1,
              "summary": { "total_amount_passed": 1500.0, "count_total": 1,
                           "count_abnormal": 0, "count_system_error": 0 } }
  }
  ```
- `status` param รับได้หลายค่าคั่นด้วย comma (csv); backend validate ทุกค่าอยู่ในลิสต์ที่อนุญาต

- [ ] **Step 1: เพิ่ม HasFactory ใน model (ถ้ายังไม่มี)**

เปิด `backend/app/Models/SlipVerification.php` ตรวจ `use Illuminate\Database\Eloquent\Factories\HasFactory;` และ `use HasFactory;` ใน class body. ถ้ายังไม่มีให้เพิ่ม (วางถัดจาก `class SlipVerification extends Model` เปิดปีกกา):

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
// ...
class SlipVerification extends Model
{
    use HasFactory;
    // ... (fillable/casts/relations เดิม คงไว้)
```

- [ ] **Step 2: สร้าง factory**

สร้าง `backend/database/factories/SlipVerificationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\SlipVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlipVerification>
 */
class SlipVerificationFactory extends Factory
{
    protected $model = SlipVerification::class;

    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'conversation_id' => null,
            'message_id' => null,
            'trans_ref' => strtoupper($this->faker->bothify('TR########')),
            'amount' => $this->faker->randomFloat(2, 50, 5000),
            'receiver_account' => 'xxx-x-x4880-x',
            'status' => 'passed',
            'raw_response' => ['data' => ['rawSlip' => ['transRef' => 'TR']]],
        ];
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
```

- [ ] **Step 3: เขียน Feature test (ให้ fail ก่อน)**

สร้าง `backend/tests/Feature/SlipControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\SlipVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SlipControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_own_slips_with_summary(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);
        $bot = Bot::factory()->create(['user_id' => $owner->id]);

        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'passed', 'amount' => 1500]);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'fake', 'amount' => 999]);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'api_error', 'amount' => null]);

        $response = $this->getJson('/api/slips');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.summary.total_amount_passed', 1500);
        $response->assertJsonPath('meta.summary.count_abnormal', 1);
        $response->assertJsonPath('meta.summary.count_system_error', 1);
    }

    public function test_non_owner_gets_403(): void
    {
        $member = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($member);

        $this->getJson('/api/slips')->assertForbidden();
    }

    public function test_does_not_leak_other_users_slips(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $other = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);

        $otherBot = Bot::factory()->create(['user_id' => $other->id]);
        SlipVerification::factory()->create(['bot_id' => $otherBot->id, 'status' => 'passed']);

        $response = $this->getJson('/api/slips');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_filters_by_status_csv(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);
        $bot = Bot::factory()->create(['user_id' => $owner->id]);

        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'passed']);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'fake']);
        SlipVerification::factory()->create(['bot_id' => $bot->id, 'status' => 'duplicate']);

        $response = $this->getJson('/api/slips?status=fake,duplicate');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_includes_customer_name_from_conversation(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($owner);
        $bot = Bot::factory()->create(['user_id' => $owner->id]);
        $customer = CustomerProfile::factory()->create(['display_name' => 'Alice']);
        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'customer_profile_id' => $customer->id,
        ]);
        SlipVerification::factory()->create([
            'bot_id' => $bot->id,
            'conversation_id' => $conversation->id,
            'status' => 'passed',
        ]);

        $response = $this->getJson('/api/slips');

        $response->assertOk();
        $response->assertJsonPath('data.0.customer_name', 'Alice');
    }
}
```

> หมายเหตุ: ตรวจว่า `Conversation::factory()` รับ `customer_profile_id` ได้ (ConversationFactory มีอยู่แล้ว). ถ้า field ชื่อไม่ตรง ให้เปิด `backend/database/factories/ConversationFactory.php` แล้วปรับ key ให้ตรง — อย่าเดา.

- [ ] **Step 4: รัน test ให้เห็นว่า fail**

Run: `cd backend && php artisan test --filter=SlipControllerTest`
Expected: FAIL — route `/api/slips` ยังไม่มี (404 / RouteNotFoundException)

- [ ] **Step 5: สร้าง SlipResource**

สร้าง `backend/app/Http/Resources/SlipResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SlipResource extends JsonResource
{
    private const LABELS = [
        'passed' => 'ผ่าน',
        'fake' => 'ปลอม',
        'wrong_account' => 'บัญชีผิด',
        'duplicate' => 'สลิปซ้ำ',
        'amount_mismatch' => 'ยอดไม่ตรง',
        'no_pending_order' => 'ไม่มีออเดอร์ค้าง',
        'unreadable' => 'อ่านสลิปไม่ได้',
        'api_error' => 'API ผิดพลาด',
        'config_error' => 'ตั้งค่าไม่ครบ',
        'image_download_failed' => 'โหลดรูปไม่ได้',
        'pending' => 'รอธนาคารยืนยัน',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at->toIso8601String(),
            'status' => $this->status,
            'status_label' => self::LABELS[$this->status] ?? $this->status,
            'amount' => $this->amount,
            'trans_ref' => $this->trans_ref,
            'receiver_account' => $this->receiver_account,
            'conversation_id' => $this->conversation_id,
            'customer_name' => $this->conversation?->customerProfile?->display_name,
            // passthrough เฉพาะ data node สำหรับกล่องรายละเอียด (null-safe, ไม่เดา path ลึก)
            'raw' => is_array($this->raw_response) ? ($this->raw_response['data'] ?? null) : null,
        ];
    }
}
```

- [ ] **Step 6: สร้าง SlipController**

สร้าง `backend/app/Http/Controllers/Api/SlipController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SlipResource;
use App\Models\SlipVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SlipController extends Controller
{
    private const STATUSES = [
        'passed', 'fake', 'wrong_account', 'duplicate', 'amount_mismatch',
        'no_pending_order', 'unreadable', 'api_error', 'config_error',
        'image_download_failed', 'pending',
    ];

    private const ABNORMAL = ['fake', 'wrong_account', 'duplicate', 'amount_mismatch', 'no_pending_order'];

    private const SYSTEM_ERROR = ['unreadable', 'api_error', 'config_error', 'image_download_failed', 'pending'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isOwner()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $botIds = $user->bots()->pluck('id');

        $validated = $request->validate([
            'bot_id' => ['sometimes', 'integer', Rule::in($botIds)],
            'status' => 'sometimes|string',
            'date_from' => 'sometimes|date',
            'date_to' => 'sometimes|date',
            'search' => 'sometimes|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = SlipVerification::whereIn('bot_id', $botIds)
            ->with(['conversation:id,customer_profile_id', 'conversation.customerProfile:id,display_name']);

        if (isset($validated['bot_id'])) {
            $query->where('bot_id', $validated['bot_id']);
        }

        if (isset($validated['status'])) {
            $statuses = array_values(array_intersect(
                explode(',', $validated['status']),
                self::STATUSES,
            ));
            $query->whereIn('status', $statuses ?: ['__none__']);
        }

        if (isset($validated['date_from'])) {
            $query->where('created_at', '>=', $validated['date_from']);
        }

        if (isset($validated['date_to'])) {
            $query->where('created_at', '<=', $validated['date_to']);
        }

        if (isset($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('trans_ref', 'ilike', "%{$search}%")
                    ->orWhereHas('conversation.customerProfile', fn ($c) => $c->where('display_name', 'ilike', "%{$search}%"));
            });
        }

        // summary จาก query ที่ filter แล้ว (ก่อน paginate) ด้วย clone
        $summaryBase = (clone $query);
        $summary = [
            'total_amount_passed' => (float) (clone $summaryBase)->where('status', 'passed')->sum('amount'),
            'count_total' => (clone $summaryBase)->count(),
            'count_abnormal' => (clone $summaryBase)->whereIn('status', self::ABNORMAL)->count(),
            'count_system_error' => (clone $summaryBase)->whereIn('status', self::SYSTEM_ERROR)->count(),
        ];

        $paginator = $query->orderByDesc('created_at')->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => SlipResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'summary' => $summary,
            ],
        ]);
    }
}
```

- [ ] **Step 7: เพิ่ม route**

เปิด `backend/routes/api.php` หา import block บนสุด เพิ่ม:

```php
use App\Http\Controllers\Api\SlipController;
```

ในกลุ่ม `Route::middleware(['auth:sanctum', 'throttle.api'])->group(function () {` (ถัดจาก group `orders` เพื่อความเป็นระเบียบ) เพิ่ม:

```php
    // Slip verification records (Owner only — enforced in controller)
    Route::get('slips', [SlipController::class, 'index'])->name('slips.index');
```

- [ ] **Step 8: รัน test ให้ผ่าน**

Run: `cd backend && php artisan test --filter=SlipControllerTest`
Expected: PASS ทั้ง 5 เคส

- [ ] **Step 9: Commit**

```bash
git add backend/app/Http/Controllers/Api/SlipController.php backend/app/Http/Resources/SlipResource.php backend/database/factories/SlipVerificationFactory.php backend/tests/Feature/SlipControllerTest.php backend/routes/api.php backend/app/Models/SlipVerification.php
git commit -m "feat(slips): add GET /api/slips owner-only list endpoint with summary"
```

---

## Task 2: Frontend types + `useSlips` hook

**Files:**
- Modify: `frontend/src/types/api.ts`
- Create: `frontend/src/lib/slipStatus.ts`
- Create: `frontend/src/hooks/useSlips.ts`

**Interfaces:**
- Consumes: `GET /api/slips` response จาก Task 1
- Produces:
  - `useSlips(filters: SlipFilters)` → `{ data: { slips: Slip[]; meta: SlipMeta } | undefined, isLoading, error }`
  - `slipStatusMeta(status: string)` → `{ label: string; variant: BadgeVariant }`
  - `bangkokTodayRange()` → `{ date_from: string; date_to: string }` (UTC ISO)
  - `STATUS_GROUPS` → map ชื่อกลุ่ม → รายชื่อ status

- [ ] **Step 1: เพิ่ม types ใน `frontend/src/types/api.ts`**

เพิ่มท้ายไฟล์ (ก่อน type อื่นที่ไม่เกี่ยวข้อง วางต่อจากกลุ่ม Order types ได้):

```tsx
export interface Slip {
  id: number;
  created_at: string;
  status: string;
  status_label: string;
  amount: number | null;
  trans_ref: string | null;
  receiver_account: string | null;
  conversation_id: number | null;
  customer_name: string | null;
  raw: Record<string, unknown> | null;
}

export interface SlipSummary {
  total_amount_passed: number;
  count_total: number;
  count_abnormal: number;
  count_system_error: number;
}

export interface SlipMeta extends PaginationMeta {
  summary: SlipSummary;
}

export interface SlipsResponse {
  data: Slip[];
  meta: SlipMeta;
}

export interface SlipFilters {
  bot_id?: number;
  status?: string;
  date_from?: string;
  date_to?: string;
  search?: string;
  page?: number;
  per_page?: number;
}
```

- [ ] **Step 2: สร้าง `frontend/src/lib/slipStatus.ts`**

```tsx
// helper สำหรับหน้ารายการสลิป: label/สี badge/กลุ่ม status + ช่วงวัน Bangkok

type BadgeVariant = 'success' | 'destructive' | 'warning' | 'secondary';

const STATUS_META: Record<string, { label: string; variant: BadgeVariant }> = {
  passed: { label: 'ผ่าน', variant: 'success' },
  fake: { label: 'ปลอม', variant: 'destructive' },
  wrong_account: { label: 'บัญชีผิด', variant: 'destructive' },
  duplicate: { label: 'สลิปซ้ำ', variant: 'warning' },
  amount_mismatch: { label: 'ยอดไม่ตรง', variant: 'warning' },
  no_pending_order: { label: 'ไม่มีออเดอร์ค้าง', variant: 'warning' },
  unreadable: { label: 'อ่านสลิปไม่ได้', variant: 'secondary' },
  api_error: { label: 'API ผิดพลาด', variant: 'secondary' },
  config_error: { label: 'ตั้งค่าไม่ครบ', variant: 'secondary' },
  image_download_failed: { label: 'โหลดรูปไม่ได้', variant: 'secondary' },
  pending: { label: 'รอธนาคารยืนยัน', variant: 'secondary' },
};

export function slipStatusMeta(status: string): { label: string; variant: BadgeVariant } {
  return STATUS_META[status] ?? { label: status, variant: 'secondary' };
}

// กลุ่ม filter (ค่าที่ส่งเป็น csv ให้ backend)
export const STATUS_GROUPS: Record<string, string[]> = {
  all: [],
  passed: ['passed'],
  abnormal: ['fake', 'wrong_account', 'duplicate', 'amount_mismatch', 'no_pending_order'],
  error: ['unreadable', 'api_error', 'config_error', 'image_download_failed', 'pending'],
};

export const STATUS_GROUP_LABELS: Record<string, string> = {
  all: 'ทั้งหมด',
  passed: 'ผ่าน',
  abnormal: 'ผิดปกติ',
  error: 'error ระบบ',
};

// ช่วง "วันนี้" ตามเวลาไทย (Bangkok = UTC+7, ไม่มี DST) คืนเป็น UTC ISO
export function bangkokTodayRange(): { date_from: string; date_to: string } {
  const OFFSET_MS = 7 * 60 * 60 * 1000;
  const nowBkk = new Date(Date.now() + OFFSET_MS);
  const y = nowBkk.getUTCFullYear();
  const m = nowBkk.getUTCMonth();
  const d = nowBkk.getUTCDate();
  const startUtc = new Date(Date.UTC(y, m, d, 0, 0, 0) - OFFSET_MS);
  const endUtc = new Date(Date.UTC(y, m, d, 23, 59, 59) - OFFSET_MS);
  return { date_from: startUtc.toISOString(), date_to: endUtc.toISOString() };
}
```

- [ ] **Step 3: สร้าง `frontend/src/hooks/useSlips.ts`**

```tsx
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { buildFilterParams } from '@/lib/params';
import { useAuthStore } from '@/stores/authStore';
import type { SlipsResponse, SlipFilters } from '@/types/api';

export function useSlips(filters: SlipFilters = {}, options?: { enabled?: boolean }) {
  const { user } = useAuthStore();
  return useQuery({
    queryKey: ['slips', 'list', filters],
    queryFn: async () => {
      const params = buildFilterParams({
        bot_id: filters.bot_id,
        status: filters.status,
        date_from: filters.date_from,
        date_to: filters.date_to,
        search: filters.search,
        page: filters.page,
        per_page: filters.per_page,
      });
      const queryString = params.toString();
      const url = queryString ? `/slips?${queryString}` : '/slips';
      const response = await api.get<SlipsResponse>(url);
      return {
        slips: response.data.data,
        meta: response.data.meta,
      };
    },
    staleTime: 30_000,
    enabled: !!user && options?.enabled !== false,
  });
}
```

- [ ] **Step 4: ตรวจ typecheck ผ่าน**

Run: `cd frontend && npx tsc --noEmit`
Expected: ไม่มี error ใหม่จากไฟล์ที่เพิ่ม (types/hook/helper)

- [ ] **Step 5: Commit**

```bash
git add frontend/src/types/api.ts frontend/src/lib/slipStatus.ts frontend/src/hooks/useSlips.ts
git commit -m "feat(slips): add Slip types, status helpers, and useSlips hook"
```

---

## Task 3: Frontend `SlipsPage` component

**Files:**
- Create: `frontend/src/pages/SlipsPage.tsx`

**Interfaces:**
- Consumes: `useSlips`, `slipStatusMeta`, `STATUS_GROUPS`, `STATUS_GROUP_LABELS`, `bangkokTodayRange` จาก Task 2; `useBots` จาก `@/hooks/useKnowledgeBase`; common components
- Produces: `export function SlipsPage()` (named export ชื่อ `SlipsPage` ให้ตรงกับ router)

- [ ] **Step 1: สร้างหน้า `frontend/src/pages/SlipsPage.tsx`**

```tsx
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router';
import { Receipt, ChevronLeft, ChevronRight, MessageSquare } from 'lucide-react';
import { useSlips } from '@/hooks/useSlips';
import { useBots } from '@/hooks/useKnowledgeBase';
import { slipStatusMeta, STATUS_GROUPS, STATUS_GROUP_LABELS, bangkokTodayRange } from '@/lib/slipStatus';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { PageHeader } from '@/components/connections';
import { Metric, BotPicker, EmptyState, ErrorState, Toolbar } from '@/components/common';
import type { Slip } from '@/types/api';

const PER_PAGE = 20;

function formatBaht(n: number | null): string {
  if (n == null) return '-';
  return `฿${n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDateTime(iso: string): string {
  // แสดงเป็นเวลาไทย
  return new Date(iso).toLocaleString('th-TH', {
    timeZone: 'Asia/Bangkok',
    day: '2-digit', month: '2-digit', year: '2-digit',
    hour: '2-digit', minute: '2-digit',
  });
}

export function SlipsPage() {
  const navigate = useNavigate();
  const { data: bots = [] } = useBots();

  const [selectedBotId, setSelectedBotId] = useState<number | undefined>(undefined);
  const [statusGroup, setStatusGroup] = useState<string>('all');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const todayRange = useMemo(() => bangkokTodayRange(), []);

  const statusCsv = STATUS_GROUPS[statusGroup]?.join(',') || undefined;

  const { data, isLoading, error } = useSlips({
    bot_id: selectedBotId,
    status: statusCsv,
    date_from: todayRange.date_from,
    date_to: todayRange.date_to,
    search: search.trim() || undefined,
    page,
    per_page: PER_PAGE,
  });

  const slips = data?.slips ?? [];
  const summary = data?.meta.summary;
  const pageCount = data?.meta.last_page ?? 1;

  const botOptions = bots.map((b) => ({ id: b.id, name: b.name }));
  const showBotPicker = botOptions.length > 1;

  return (
    <div className="space-y-4">
      <PageHeader
        title="สลิป / การชำระเงิน"
        description="รายการผลตรวจสลิปจาก EasySlip (ข้อมูลวันนี้)"
        actions={
          showBotPicker ? (
            <div className="w-48">
              <BotPicker bots={botOptions} value={selectedBotId} onChange={(id) => setSelectedBotId(Number(id))} />
            </div>
          ) : undefined
        }
      />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <Metric label="เงินเข้าวันนี้" value={formatBaht(summary?.total_amount_passed ?? 0)} />
        <Metric label="สลิปวันนี้" value={summary?.count_total ?? 0} />
        <Metric label="ผิดปกติ" value={summary?.count_abnormal ?? 0} />
        <Metric label="error ระบบ" value={summary?.count_system_error ?? 0} />
      </div>

      <Toolbar
        search={search}
        onSearchChange={(v) => { setSearch(v); setPage(1); }}
        searchPlaceholder="ค้นหาเลขอ้างอิงหรือชื่อลูกค้า"
        filters={
          <Select value={statusGroup} onValueChange={(v) => { setStatusGroup(v); setPage(1); }}>
            <SelectTrigger className="w-40">
              <SelectValue placeholder="สถานะ" />
            </SelectTrigger>
            <SelectContent>
              {Object.keys(STATUS_GROUPS).map((key) => (
                <SelectItem key={key} value={key}>{STATUS_GROUP_LABELS[key]}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        }
      />

      {isLoading && (
        <div className="py-10 text-center text-muted-foreground text-sm">กำลังโหลด...</div>
      )}
      {error && <ErrorState title="เกิดข้อผิดพลาด" description={String(error)} />}

      {!isLoading && !error && (
        <Card>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-32">เวลา</TableHead>
                    <TableHead>ลูกค้า</TableHead>
                    <TableHead className="w-32 text-right">ยอด</TableHead>
                    <TableHead className="w-36">เลขอ้างอิง</TableHead>
                    <TableHead className="w-32">บัญชีรับ</TableHead>
                    <TableHead className="w-28">สถานะ</TableHead>
                    <TableHead className="w-16 text-right">แชท</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {slips.map((slip: Slip) => {
                    const meta = slipStatusMeta(slip.status);
                    return (
                      <TableRow key={slip.id}>
                        <TableCell className="text-muted-foreground tabular-nums text-xs">
                          {formatDateTime(slip.created_at)}
                        </TableCell>
                        <TableCell className="text-sm">{slip.customer_name ?? '-'}</TableCell>
                        <TableCell className="text-right tabular-nums">{formatBaht(slip.amount)}</TableCell>
                        <TableCell className="text-xs tabular-nums">{slip.trans_ref ?? '-'}</TableCell>
                        <TableCell className="text-xs tabular-nums">{slip.receiver_account ?? '-'}</TableCell>
                        <TableCell><Badge variant={meta.variant}>{meta.label}</Badge></TableCell>
                        <TableCell className="text-right">
                          {slip.conversation_id && (
                            <Button size="sm" variant="ghost"
                              onClick={() => navigate(`/chat?conversation=${slip.conversation_id}`)}>
                              <MessageSquare className="size-4" strokeWidth={1.5} />
                            </Button>
                          )}
                        </TableCell>
                      </TableRow>
                    );
                  })}
                  {slips.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={7} className="py-10 text-center text-muted-foreground">
                        <EmptyState icon={Receipt} title="ยังไม่มีรายการสลิปวันนี้" size="sm" />
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>

            {pageCount > 1 && (
              <div className="flex items-center justify-between border-t px-4 py-3">
                <div className="text-sm text-muted-foreground tabular-nums">หน้า {page} / {pageCount}</div>
                <div className="flex items-center gap-1">
                  <Button size="sm" variant="outline" disabled={page === 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}>
                    <ChevronLeft className="size-4" strokeWidth={1.5} />ก่อนหน้า
                  </Button>
                  <Button size="sm" variant="outline" disabled={page === pageCount}
                    onClick={() => setPage((p) => Math.min(pageCount, p + 1))}>
                    ถัดไป<ChevronRight className="size-4" strokeWidth={1.5} />
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
```

> **หมายเหตุ verify ก่อนเขียน:**
> - เปิด `frontend/src/hooks/useKnowledgeBase.ts` ยืนยัน `useBots()` คืน array ที่มี `id` และ `name` (VipManagementPage ใช้ `useBots` เช่นกัน — ดู `botOptions` ในนั้น). ถ้า shape ต่างให้ปรับ `botOptions`
> - เปิด `frontend/src/pages/ChatPage.tsx` (หรือ router) ยืนยันรูปแบบลิงก์เปิดแชทตาม conversation (query param `?conversation=` เป็นสมมติฐาน) — ถ้าโปรเจกต์ใช้ path แบบอื่น เช่น `/chat/:conversationId` ให้แก้ `navigate(...)` ให้ตรง **อย่าเดา**
> - ยืนยันมี `frontend/src/components/ui/select.tsx` (explorer ระบุว่ามี) — ถ้าไม่มีให้เปลี่ยน filter เป็นปุ่ม tab ธรรมดา

- [ ] **Step 2: ตรวจ typecheck ผ่าน**

Run: `cd frontend && npx tsc --noEmit`
Expected: ไม่มี error จาก `SlipsPage.tsx`

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/SlipsPage.tsx
git commit -m "feat(slips): add SlipsPage table with summary metrics and filters"
```

---

## Task 4: Frontend wiring — route + sidebar + mobile nav

**Files:**
- Modify: `frontend/src/router.tsx`
- Modify: `frontend/src/components/layout/Sidebar.tsx`
- Modify: `frontend/src/components/layout/MobileNav.tsx`

**Interfaces:**
- Consumes: `SlipsPage` (named export) จาก Task 3
- Produces: route `/slips` (owner เห็นเมนู, non-owner ไม่เห็น; backend คือด่านความปลอดภัยจริง)

- [ ] **Step 1: เพิ่ม lazy import + route ใน `frontend/src/router.tsx`**

ถัดจากบรรทัด `const VipManagementPage = lazyWithRetryNamed(...)` เพิ่ม:

```tsx
const SlipsPage = lazyWithRetryNamed(() => import("@/pages/SlipsPage"), "SlipsPage")
```

ในกลุ่ม children ของ RootLayout ถัดจาก route `vip-customers` เพิ่ม:

```tsx
          {
            path: "slips",
            element: <LazyPage><SlipsPage /></LazyPage>,
          },
```

- [ ] **Step 2: เพิ่มเมนู owner-only ใน `frontend/src/components/layout/Sidebar.tsx`**

เพิ่ม `Receipt` ใน import จาก `lucide-react` (ต่อท้าย list icon เดิม):

```tsx
import {
  LayoutDashboard, Bot, BookOpen, MessageSquare, Settings, ChevronLeft,
  Sparkles, LogOut, ChevronsUpDown, Sun, Moon, Users, Zap, ShoppingCart, Star, Receipt,
} from 'lucide-react';
```

ในบล็อก owner-only (ถัดจาก NavLink "Quick Replies") เพิ่ม:

```tsx
          {/* Slips - Owner only */}
          {user?.role === 'owner' && (
            <NavLink to="/slips" className={navLinkClass}>
              <Receipt className="size-4 shrink-0" strokeWidth={1.5} />
              {!sidebarCollapsed && <span>สลิป / การชำระเงิน</span>}
            </NavLink>
          )}
```

- [ ] **Step 3: เพิ่มเมนู owner-only ใน `frontend/src/components/layout/MobileNav.tsx`**

เพิ่ม `Receipt` ใน import จาก `lucide-react`, แล้วในบล็อก owner-only (ถัดจาก "Quick Replies") เพิ่ม:

```tsx
          {user?.role === 'owner' && (
            <NavLink to="/slips" onClick={onNavigate} className={navLinkClass}>
              <Receipt className="size-4 shrink-0" strokeWidth={1.5} />
              <span>สลิป / การชำระเงิน</span>
            </NavLink>
          )}
```

- [ ] **Step 4: Build ผ่าน**

Run: `cd frontend && npm run build`
Expected: build สำเร็จ ไม่มี type error

- [ ] **Step 5: ตรวจด้วยตาจริง (browser)**

รัน dev server (`cd frontend && npm run dev` + backend `cd backend && php artisan serve`) ล็อกอินเป็น owner:
- เห็นเมนู "สลิป / การชำระเงิน" ใน sidebar → คลิกเข้าหน้า → เห็น 4 การ์ดสรุป + ตาราง (หรือ empty state ถ้าไม่มีสลิปวันนี้)
- เปลี่ยน filter สถานะ → ตารางเปลี่ยน
- ล็อกอินเป็น non-owner → ไม่เห็นเมนู; เข้าตรง `/slips` → API ตอบ 403 → หน้าโชว์ ErrorState

- [ ] **Step 6: Commit**

```bash
git add frontend/src/router.tsx frontend/src/components/layout/Sidebar.tsx frontend/src/components/layout/MobileNav.tsx
git commit -m "feat(slips): wire /slips route and owner-only nav menu"
```

---

## Self-Review (ทำหลังเขียนแผน)

**Spec coverage:**
- audit เงินเข้า → การ์ด "เงินเข้าวันนี้" + summary.total_amount_passed ✓ (Task 1 Step 6, Task 3)
- จับสลิปปลอม/ผิดปกติ → filter กลุ่ม abnormal + badge สี + การ์ด "ผิดปกติ" ✓
- ตรวจย้อนหลังรายลูกค้า → คอลัมน์ลูกค้า + search ชื่อ + ปุ่มไปแชท ✓
- เฝ้าระวัง error ระบบ → กลุ่ม error + การ์ด "error ระบบ" ✓
- owner-only 2 ชั้น → backend 403 (Task 1) + ซ่อนเมนู (Task 4) ✓
- ไม่โชว์รูปสลิป → ไม่มี ✓ (raw passthrough ไว้กล่องรายละเอียดเท่านั้น — ยังไม่ทำ dialog ในเฟสนี้ เก็บ raw ไว้ให้ต่อยอด)
- timezone Bangkok → `bangkokTodayRange()` แปลงเป็น UTC ก่อนส่ง ✓

**หมายเหตุ deviation จาก spec:** spec วาดคอลัมน์ "ธนาคาร/ผู้โอน" ดึงจาก raw_response — เปลี่ยนเป็นคอลัมน์ "บัญชีรับ" (`receiver_account`, เป็น column จริง) เพราะโค้ด EasySlip เดิมไม่ได้ map sender ออกมา และ path sender ใน raw ไม่ยืนยัน (เลี่ยงการเดา). ข้อมูล sender ดิบเก็บใน field `raw` ส่งไป frontend แล้ว ต่อยอดเป็นกล่องรายละเอียดได้ภายหลัง

**Placeholder scan:** ไม่มี TBD/TODO ในโค้ด steps; จุด "verify ก่อนเขียน" เป็นการยืนยัน shape ของ API ภายนอก task (useBots/route แชท/select) ไม่ใช่ placeholder ของงานหลัก

**Type consistency:** `SlipResource` (backend) fields ↔ `Slip` interface (frontend) ตรงกัน (id, created_at, status, status_label, amount, trans_ref, receiver_account, conversation_id, customer_name, raw). `meta.summary` ↔ `SlipSummary` ตรงกัน (total_amount_passed, count_total, count_abnormal, count_system_error). Endpoint path `/slips` ↔ hook ↔ route ตรงกัน

**ยังไม่ทำในเฟสนี้ (ต่อยอด):** กล่องรายละเอียดสลิป (dialog แสดง field `raw`), รูปสลิปจริง, export CSV, เลือกช่วงวันเอง (ตอนนี้ fix "วันนี้")
