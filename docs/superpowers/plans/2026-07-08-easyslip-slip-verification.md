# EasySlip Slip Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ตรวจสลิปโอนเงินจริงกับธนาคารผ่าน EasySlip API ก่อนบอทยืนยันรับเงิน พร้อมหน้าตั้งค่าเต็มรูปแบบ (แท็บใหม่ต่อบอท + API token ระดับ user)

**Architecture:** EasySlip-first — image branch ใน pipeline (`LineWebhookResponseService::generateImageResponse`) ส่งรูปให้ EasySlip ตรวจก่อน (verify-by-URL); เป็นสลิปจึงตัดสินผ่าน/ไม่ผ่านด้วย 4 เช็ค (สลิปจริง/บัญชีร้าน/ยอดตรง/ไม่ซ้ำ) แล้วตอบด้วย template; ไม่ใช่สลิปหรือ API ล่มตกไป vision flow เดิม การสร้าง order ใช้กลไกเดิม (ข้อความมีแท็ก `[ยืนยันชำระเงิน]` → Stage 4 `executePlugins` → Telegram plugin → OrderService) ไม่แก้อะไร

**Tech Stack:** Laravel 13 (backend/), React 19 + TypeScript 6 (frontend/), PostgreSQL (Neon), Pest/PHPUnit-class-style tests, Vitest

**Spec:** `docs/superpowers/specs/2026-07-08-easyslip-slip-verification-design.md`

## Global Constraints

- ห้ามแก้ legacy image path ใน `ProcessLINEWebhook.php` (bot 26 ใช้ pipeline ใหม่แล้ว)
- ห้ามแตะ `bot_hitl_settings.easy_slip_enabled` (stub เดิม — ไม่ใช้ ไม่ลบ)
- Migration เป็น additive เท่านั้น (เพิ่มคอลัมน์/ตารางใหม่ ไม่แก้ของเดิม)
- ทุกจุดที่ EasySlip fail (ล่ม/timeout/token ผิด) ต้อง fallback ไป vision เดิม — **ห้ามทำให้ลูกค้าไม่ได้รับตอบ**
- EasySlip API: `POST https://api.easyslip.com/v2/verify/bank` Bearer token, JSON body `{"url": <imageUrl>, "checkDuplicate": false}`; response `{success: true, data: {isDuplicate, matchedAccount, amountInSlip, rawSlip: {transRef, amount: {amount}, receiver: {bank: {id}, account: {name: {th}, bank: {account: "xxx-x-x4880-x"}}}, ...}}}`; token test: `GET https://api.easyslip.com/v2/info`
- เราเช็คสลิปซ้ำเองใน DB (ไม่ใช้ checkDuplicate ของ EasySlip) — partial unique index บน (bot_id, trans_ref) WHERE status='passed'
- Backend test style: PHPUnit class (`extends Tests\TestCase`, `use RefreshDatabase`) ตาม `tests/Feature/LINEWebhookTest.php`
- Commit message ลงท้าย `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`; ห้ามใช้ `--no-verify`
- รันคำสั่ง backend จาก `/Users/jaochai/Code/bot-fb/backend`, frontend จาก `/Users/jaochai/Code/bot-fb/frontend`

---

### Task 1: Migrations + Models (schema ทั้งหมดของฟีเจอร์)

**Files:**
- Create: `backend/database/migrations/2026_07_08_100001_add_slip_verification_to_bot_settings.php`
- Create: `backend/database/migrations/2026_07_08_100002_create_slip_verifications_table.php`
- Create: `backend/database/migrations/2026_07_08_100003_add_easyslip_token_to_user_settings.php`
- Create: `backend/app/Models/SlipVerification.php`
- Modify: `backend/app/Models/BotSetting.php` (fillable + casts)
- Modify: `backend/app/Models/UserSetting.php` (fillable + casts + hidden + helpers)
- Test: `backend/tests/Feature/SlipVerificationSchemaTest.php`

**Interfaces:**
- Consumes: ไม่มี (task แรก)
- Produces:
  - คอลัมน์ `bot_settings`: `slip_verification_enabled` (bool), `slip_receiver_account` (string|null), `slip_amount_tolerance` (decimal 8,2 default 0), `slip_success_message` (text|null), `slip_fail_message` (text|null)
  - Model `App\Models\SlipVerification` (ตาราง `slip_verifications`): fillable `bot_id, conversation_id, message_id, trans_ref, amount, receiver_account, status, raw_response`; casts `raw_response => array, amount => float`
  - `UserSetting::getEasySlipApiToken(): ?string`, `UserSetting::hasEasySlipToken(): bool`, accessor `masked_easyslip_token`

- [ ] **Step 1: เขียน failing test**

```php
<?php
// backend/tests/Feature/SlipVerificationSchemaTest.php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\SlipVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlipVerificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bot_settings_has_slip_verification_fields(): void
    {
        $bot = Bot::factory()->create();
        $settings = BotSetting::create([
            'bot_id' => $bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
            'slip_amount_tolerance' => 5.00,
            'slip_success_message' => 'เงินเข้าแล้ว {amount} บาท',
            'slip_fail_message' => 'ขอตรวจสอบสักครู่',
        ]);

        $this->assertTrue($settings->fresh()->slip_verification_enabled);
        $this->assertSame('223-3-24880-3', $settings->fresh()->slip_receiver_account);
    }

    public function test_slip_verification_records_persist(): void
    {
        $bot = Bot::factory()->create();
        $row = SlipVerification::create([
            'bot_id' => $bot->id,
            'conversation_id' => null,
            'message_id' => null,
            'trans_ref' => 'TR001',
            'amount' => 1500.00,
            'receiver_account' => 'xxx-x-x4880-x',
            'status' => 'passed',
            'raw_response' => ['data' => ['transRef' => 'TR001']],
        ]);

        $this->assertSame('passed', $row->fresh()->status);
        $this->assertSame(['data' => ['transRef' => 'TR001']], $row->fresh()->raw_response);
    }

    public function test_duplicate_passed_trans_ref_rejected_per_bot(): void
    {
        $bot = Bot::factory()->create();
        SlipVerification::create(['bot_id' => $bot->id, 'trans_ref' => 'TRDUP', 'status' => 'passed']);

        // แถว failed ซ้ำ trans_ref ได้ (partial index)
        SlipVerification::create(['bot_id' => $bot->id, 'trans_ref' => 'TRDUP', 'status' => 'duplicate']);
        $this->assertSame(2, SlipVerification::count());

        // แถว passed ซ้ำ trans_ref ใน bot เดียวกันต้องพัง
        $this->expectException(\Illuminate\Database\QueryException::class);
        SlipVerification::create(['bot_id' => $bot->id, 'trans_ref' => 'TRDUP', 'status' => 'passed']);
    }

    public function test_user_settings_easyslip_token_helpers(): void
    {
        $user = User::factory()->create();
        $settings = $user->getOrCreateSettings();

        $this->assertFalse($settings->hasEasySlipToken());

        $settings->easyslip_api_token = 'test-token-1234';
        $settings->save();

        $this->assertTrue($settings->fresh()->hasEasySlipToken());
        $this->assertSame('test-token-1234', $settings->fresh()->getEasySlipApiToken());
        $this->assertStringEndsWith('1234', $settings->fresh()->masked_easyslip_token);
    }
}
```

- [ ] **Step 2: รัน test ให้ fail**

Run: `php artisan test --filter=SlipVerificationSchemaTest`
Expected: FAIL (column not found / class not found)

- [ ] **Step 3: สร้าง migrations**

```php
<?php
// backend/database/migrations/2026_07_08_100001_add_slip_verification_to_bot_settings.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->boolean('slip_verification_enabled')->default(false);
            $table->string('slip_receiver_account')->nullable();
            $table->decimal('slip_amount_tolerance', 8, 2)->default(0);
            $table->text('slip_success_message')->nullable();
            $table->text('slip_fail_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'slip_verification_enabled',
                'slip_receiver_account',
                'slip_amount_tolerance',
                'slip_success_message',
                'slip_fail_message',
            ]);
        });
    }
};
```

```php
<?php
// backend/database/migrations/2026_07_08_100002_create_slip_verifications_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slip_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->onDelete('cascade');
            $table->foreignId('conversation_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('message_id')->nullable()->constrained()->onDelete('set null');
            $table->string('trans_ref')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('receiver_account')->nullable();
            // passed|fake|duplicate|amount_mismatch|wrong_account|no_pending_order|api_error
            $table->string('status', 32);
            $table->jsonb('raw_response')->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'created_at']);
        });

        // กันสลิปซ้ำ: trans_ref เดิมห้ามมีสถานะ passed ซ้ำใน bot เดียวกัน
        DB::statement(
            'CREATE UNIQUE INDEX slip_verifications_passed_trans_ref_unique
             ON slip_verifications (bot_id, trans_ref) WHERE status = \'passed\''
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('slip_verifications');
    }
};
```

```php
<?php
// backend/database/migrations/2026_07_08_100003_add_easyslip_token_to_user_settings.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->text('easyslip_api_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('easyslip_api_token');
        });
    }
};
```

- [ ] **Step 4: สร้าง/แก้ Models**

```php
<?php
// backend/app/Models/SlipVerification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlipVerification extends Model
{
    protected $fillable = [
        'bot_id',
        'conversation_id',
        'message_id',
        'trans_ref',
        'amount',
        'receiver_account',
        'status',
        'raw_response',
    ];

    protected $casts = [
        'raw_response' => 'array',
        'amount' => 'float',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
```

แก้ `backend/app/Models/BotSetting.php` — เพิ่มใน `$fillable` (ต่อท้าย `'auto_assignment_mode',`):

```php
        // Slip verification feature
        'slip_verification_enabled',
        'slip_receiver_account',
        'slip_amount_tolerance',
        'slip_success_message',
        'slip_fail_message',
```

และเพิ่มใน `$casts` (ต่อท้าย `'auto_assignment_enabled' => 'boolean',`):

```php
        'slip_verification_enabled' => 'boolean',
        'slip_amount_tolerance' => 'float',
```

แก้ `backend/app/Models/UserSetting.php`:
- เพิ่ม `'easyslip_api_token',` ใน `$fillable`
- เพิ่ม `'easyslip_api_token' => 'encrypted',` ใน `$casts`
- เพิ่ม `'easyslip_api_token',` ใน `$hidden`
- เพิ่ม methods (วางถัดจาก `hasOpenRouterKey()` ตามแพทเทิร์น `getOpenRouterApiKey()` ที่มี try/catch decrypt):

```php
    /**
     * Safely get EasySlip API token, handling decryption errors.
     */
    public function getEasySlipApiToken(): ?string
    {
        try {
            return $this->easyslip_api_token;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to decrypt EasySlip API token', [
                'user_id' => $this->user_id,
            ]);

            return null;
        }
    }

    public function hasEasySlipToken(): bool
    {
        return ! empty($this->getEasySlipApiToken());
    }

    /**
     * Get masked EasySlip token for display (last 4 chars).
     */
    public function getMaskedEasyslipTokenAttribute(): ?string
    {
        $token = $this->getEasySlipApiToken();
        if (! $token) {
            return null;
        }

        return '••••••••'.substr($token, -4);
    }
```

(หมายเหตุ: เปิดไฟล์ UserSetting.php ดูก่อน — ให้ตามแพทเทิร์น masked accessor ของ OpenRouter ที่มีอยู่แล้วเป๊ะๆ รวมถึงรูปแบบ mask string)

- [ ] **Step 5: รัน test ให้ผ่าน**

Run: `php artisan test --filter=SlipVerificationSchemaTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations backend/app/Models backend/tests/Feature/SlipVerificationSchemaTest.php
git commit -m "feat(slip): add slip verification schema — bot settings, slip_verifications table, easyslip token

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: SlipVerificationResult + logic ล้วน (account match / expected amount)

**Files:**
- Create: `backend/app/Services/Payment/SlipVerificationResult.php`
- Create: `backend/app/Services/Payment/SlipVerificationService.php` (เฉพาะ logic ล้วน — HTTP อยู่ Task 3)
- Test: `backend/tests/Unit/SlipVerificationLogicTest.php`

**Interfaces:**
- Consumes: `App\Services\Payment\PaymentMessageDetector` (มีอยู่แล้ว: `isPaymentMessage(string): bool`, `parsePaymentData(string): ?array` คืน `['items' => [['name' => ..., 'total' => ...]], 'total' => '1,500']`)
- Produces:
  - `SlipVerificationResult` readonly DTO: `bool $isSlip, bool $passed, ?string $failReason, ?float $amount, ?string $transRef, ?float $expectedAmount, ?string $orderSummary`
  - `SlipVerificationService::accountMatches(string $configured, string $masked): bool` (static)
  - `SlipVerificationService::findExpectedPayment(array $conversationHistory): ?array` คืน `['total' => float, 'summary' => string]` หรือ null
  - constructor: `__construct(private readonly PaymentMessageDetector $detector)`

- [ ] **Step 1: เขียน failing test**

```php
<?php
// backend/tests/Unit/SlipVerificationLogicTest.php

namespace Tests\Unit;

use App\Services\Payment\PaymentMessageDetector;
use App\Services\Payment\SlipVerificationService;
use PHPUnit\Framework\TestCase;

class SlipVerificationLogicTest extends TestCase
{
    private function service(): SlipVerificationService
    {
        return new SlipVerificationService(new PaymentMessageDetector);
    }

    // --- accountMatches: เทียบเลขบัญชีที่ EasySlip mask มา กับเลขที่ตั้งค่าไว้ ---

    public function test_account_matches_masked_account(): void
    {
        // configured: 223-3-24880-3 → digits 2233248803
        // masked:     xxx-x-x4880-x → ตำแหน่งจากท้าย: x,0,8,8,4,x,x,x,x,x
        $this->assertTrue(SlipVerificationService::accountMatches('223-3-24880-3', 'xxx-x-x4880-x'));
    }

    public function test_account_mismatch_detected(): void
    {
        $this->assertFalse(SlipVerificationService::accountMatches('223-3-24880-3', 'xxx-x-x9999-x'));
    }

    public function test_account_with_no_visible_digits_fails(): void
    {
        $this->assertFalse(SlipVerificationService::accountMatches('223-3-24880-3', 'xxx-x-xxxxx-x'));
    }

    public function test_account_masked_longer_than_configured_fails(): void
    {
        $this->assertFalse(SlipVerificationService::accountMatches('4880', 'xxx-x-x4880-x'));
    }

    // --- findExpectedPayment: หายอดออเดอร์ล่าสุดจาก history ---

    public function test_finds_latest_payment_total_from_history(): void
    {
        $history = [
            ['sender' => 'user', 'content' => 'สนใจ BM ครับ'],
            ['sender' => 'bot', 'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3"],
            ['sender' => 'user', 'content' => 'โอเค'],
        ];

        $result = $this->service()->findExpectedPayment($history);

        $this->assertNotNull($result);
        $this->assertSame(1500.0, $result['total']);
        $this->assertStringContainsString('Nolimit BM', $result['summary']);
    }

    public function test_no_payment_message_returns_null(): void
    {
        $history = [
            ['sender' => 'user', 'content' => 'สวัสดีครับ'],
            ['sender' => 'bot', 'content' => 'สวัสดีครับ มีอะไรให้ช่วยไหมครับ'],
        ];

        $this->assertNull($this->service()->findExpectedPayment($history));
    }
}
```

- [ ] **Step 2: รัน test ให้ fail**

Run: `php artisan test --filter=SlipVerificationLogicTest`
Expected: FAIL — class SlipVerificationService not found

- [ ] **Step 3: implement**

```php
<?php
// backend/app/Services/Payment/SlipVerificationResult.php

namespace App\Services\Payment;

class SlipVerificationResult
{
    public function __construct(
        public readonly bool $isSlip,
        public readonly bool $passed,
        public readonly ?string $failReason = null,
        public readonly ?float $amount = null,
        public readonly ?string $transRef = null,
        public readonly ?float $expectedAmount = null,
        public readonly ?string $orderSummary = null,
    ) {}
}
```

```php
<?php
// backend/app/Services/Payment/SlipVerificationService.php

namespace App\Services\Payment;

class SlipVerificationService
{
    public function __construct(
        private readonly PaymentMessageDetector $detector,
    ) {}

    /**
     * เทียบเลขบัญชีที่ตั้งค่าไว้ กับเลขบัญชี mask จาก EasySlip (เช่น "xxx-x-x4880-x").
     * กติกา: ตัดอักขระที่ไม่ใช่ตัวเลข/x ทั้งสองฝั่ง แล้วเทียบตำแหน่งจากท้าย
     * เฉพาะตำแหน่งที่ EasySlip เปิดเผยตัวเลข ต้องตรงทุกตัว และต้องมีตัวเลขเปิดเผยอย่างน้อย 1 ตัว
     */
    public static function accountMatches(string $configured, string $masked): bool
    {
        $configuredDigits = array_reverse(str_split(preg_replace('/\D/', '', $configured)));
        $maskedChars = array_reverse(str_split(preg_replace('/[^0-9xX]/', '', $masked)));

        if (count($maskedChars) === 0 || count($configuredDigits) < count($maskedChars)) {
            return false;
        }

        $visibleDigits = 0;
        foreach ($maskedChars as $i => $char) {
            if ($char === 'x' || $char === 'X') {
                continue;
            }
            $visibleDigits++;
            if ($configuredDigits[$i] !== $char) {
                return false;
            }
        }

        return $visibleDigits > 0;
    }

    /**
     * หาข้อความสรุปยอดโอนล่าสุดของบอทใน history แล้วคืนยอด + สรุปรายการ
     *
     * @param  array<int, array{sender: string, content: string}>  $conversationHistory
     * @return array{total: float, summary: string}|null
     */
    public function findExpectedPayment(array $conversationHistory): ?array
    {
        foreach (array_reverse($conversationHistory) as $msg) {
            if (($msg['sender'] ?? '') !== 'bot') {
                continue;
            }
            $content = $msg['content'] ?? '';
            if (! $this->detector->isPaymentMessage($content)) {
                continue;
            }
            $data = $this->detector->parsePaymentData($content);
            if ($data === null) {
                continue;
            }

            $itemNames = array_map(fn (array $item) => $item['name'], $data['items']);

            return [
                'total' => (float) str_replace(',', '', $data['total']),
                'summary' => $itemNames === [] ? '-' : implode(', ', $itemNames),
            ];
        }

        return null;
    }
}
```

- [ ] **Step 4: รัน test ให้ผ่าน**

Run: `php artisan test --filter=SlipVerificationLogicTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Payment/SlipVerificationResult.php backend/app/Services/Payment/SlipVerificationService.php backend/tests/Unit/SlipVerificationLogicTest.php
git commit -m "feat(slip): add slip verification result DTO and matching logic

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: SlipVerificationService::verify — เรียก EasySlip + ตัดสิน + บันทึกประวัติ

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationService.php`
- Test: `backend/tests/Feature/SlipVerificationServiceTest.php`

**Interfaces:**
- Consumes: Task 1 (`SlipVerification` model, bot_settings columns), Task 2 (`accountMatches`, `findExpectedPayment`, `SlipVerificationResult`)
- Produces:
  - `verify(Bot $bot, ?Conversation $conversation, ?Message $message, string $imageUrl, array $conversationHistory): SlipVerificationResult`
  - เรียก EasySlip ด้วย token จาก `$bot->user->settings->getEasySlipApiToken()`
  - บันทึกแถว `slip_verifications` ทุกครั้งที่ EasySlip ตอบว่าเป็นสลิป และเมื่อ api_error (trans_ref null); ไม่บันทึกเมื่อไม่ใช่สลิป
  - Mapping ผลลัพธ์:
    - HTTP 200 + data → เป็นสลิป → เช็คต่อ 4 ขั้น
    - HTTP 400 → ไม่ใช่สลิป/รูปอ่านไม่ได้ → `isSlip=false, failReason=null` (ไป vision)
    - HTTP 404 → สลิปหาไม่พบในระบบธนาคาร → `isSlip=true, passed=false, failReason='fake'`
    - HTTP 401/403/429/5xx/ConnectionException → `isSlip=false, failReason='api_error'`
  - ลำดับเช็ค (fail อันแรกที่เจอ): `wrong_account` → `duplicate` → `no_pending_order` → `amount_mismatch`

- [ ] **Step 1: เขียน failing test**

```php
<?php
// backend/tests/Feature/SlipVerificationServiceTest.php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Payment\SlipVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlipVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private array $paymentHistory;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'tok-123']);

        $this->bot = Bot::factory()->create(['user_id' => $user->id]);
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
            'slip_amount_tolerance' => 0,
        ]);

        $this->paymentHistory = [
            ['sender' => 'bot', 'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3"],
        ];
    }

    private function easySlipResponse(float $amount = 1500, string $transRef = 'TR100', string $account = 'xxx-x-x4880-x'): array
    {
        return [
            'status' => 200,
            'data' => [
                'transRef' => $transRef,
                'amount' => ['amount' => $amount],
                'receiver' => [
                    'bank' => ['id' => '004'],
                    'account' => ['name' => ['th' => 'ร้านค้า'], 'bank' => ['account' => $account]],
                ],
            ],
        ];
    }

    private function verify(): \App\Services\Payment\SlipVerificationResult
    {
        return app(SlipVerificationService::class)->verify(
            $this->bot, null, null, 'https://example.com/slip.jpg', $this->paymentHistory
        );
    }

    public function test_valid_slip_passes_all_checks(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertTrue($result->isSlip);
        $this->assertTrue($result->passed);
        $this->assertSame(1500.0, $result->amount);
        $this->assertSame('Nolimit BM', $result->orderSummary);
        $this->assertDatabaseHas('slip_verifications', ['trans_ref' => 'TR100', 'status' => 'passed']);
    }

    public function test_wrong_receiver_account_fails(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse(account: 'xxx-x-x9999-x'))]);

        $result = $this->verify();

        $this->assertTrue($result->isSlip);
        $this->assertFalse($result->passed);
        $this->assertSame('wrong_account', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'wrong_account']);
    }

    public function test_duplicate_trans_ref_fails(): void
    {
        SlipVerification::create(['bot_id' => $this->bot->id, 'trans_ref' => 'TR100', 'status' => 'passed']);
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('duplicate', $result->failReason);
    }

    public function test_amount_mismatch_fails(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse(amount: 1000))]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('amount_mismatch', $result->failReason);
        $this->assertSame(1500.0, $result->expectedAmount);
    }

    public function test_amount_within_tolerance_passes(): void
    {
        $this->bot->settings->update(['slip_amount_tolerance' => 10]);
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse(amount: 1495))]);

        $this->assertTrue($this->verify()->passed);
    }

    public function test_no_pending_order_fails(): void
    {
        $this->paymentHistory = [['sender' => 'user', 'content' => 'สวัสดี']];
        Http::fake(['developer.easyslip.com/*' => Http::response($this->easySlipResponse())]);

        $result = $this->verify();

        $this->assertFalse($result->passed);
        $this->assertSame('no_pending_order', $result->failReason);
    }

    public function test_http_400_means_not_a_slip(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response(['status' => 400, 'message' => 'invalid_image'], 400)]);

        $result = $this->verify();

        $this->assertFalse($result->isSlip);
        $this->assertNull($result->failReason);
        $this->assertSame(0, SlipVerification::count());
    }

    public function test_http_404_means_fake_slip(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response(['status' => 404, 'message' => 'slip_not_found'], 404)]);

        $result = $this->verify();

        $this->assertTrue($result->isSlip);
        $this->assertSame('fake', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'fake']);
    }

    public function test_server_error_is_api_error(): void
    {
        Http::fake(['developer.easyslip.com/*' => Http::response(['message' => 'server error'], 500)]);

        $result = $this->verify();

        $this->assertFalse($result->isSlip);
        $this->assertSame('api_error', $result->failReason);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'api_error']);
    }

    public function test_missing_token_is_api_error_without_http_call(): void
    {
        $this->bot->user->settings->update(['easyslip_api_token' => null]);
        Http::fake();

        $result = $this->verify();

        $this->assertSame('api_error', $result->failReason);
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: รัน test ให้ fail**

Run: `php artisan test --filter=SlipVerificationServiceTest`
Expected: FAIL — method verify() not found

- [ ] **Step 3: implement `verify()` ใน SlipVerificationService**

เพิ่ม imports + methods ต่อไปนี้ในคลาสเดิม:

```php
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SlipVerification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
```

```php
    private const VERIFY_URL = 'https://developer.easyslip.com/api/v1/verify';

    public function verify(
        Bot $bot,
        ?Conversation $conversation,
        ?Message $message,
        string $imageUrl,
        array $conversationHistory,
    ): SlipVerificationResult {
        $token = $bot->user?->settings?->getEasySlipApiToken();
        if (! $token) {
            Log::warning('Slip verification enabled but EasySlip token missing', ['bot_id' => $bot->id]);

            return $this->record($bot, $conversation, $message, null, new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
            ));
        }

        try {
            $response = Http::timeout(15)
                ->withToken($token)
                ->post(self::VERIFY_URL, ['url' => $imageUrl, 'checkDuplicate' => false]);
        } catch (ConnectionException $e) {
            Log::warning('EasySlip connection failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);

            return $this->record($bot, $conversation, $message, null, new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
            ));
        }

        if ($response->status() === 400) {
            // รูปไม่ใช่สลิป/อ่านไม่ได้ → ไป vision flow เดิม ไม่บันทึก
            return new SlipVerificationResult(isSlip: false, passed: false);
        }

        if ($response->status() === 404) {
            // อ่าน QR ได้แต่ไม่พบธุรกรรมในระบบธนาคาร → สลิปปลอม/สลิปเก่าผิดปกติ
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'fake',
            ));
        }

        if (! $response->successful()) {
            Log::warning('EasySlip API error', [
                'bot_id' => $bot->id, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500),
            ]);

            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
            ));
        }

        $data = $response->json('data');
        if (! is_array($data) || empty($data['transRef'])) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
            ));
        }

        $transRef = (string) $data['transRef'];
        $slipAmount = (float) ($data['amount']['amount'] ?? 0);
        $receiverAccount = (string) ($data['receiver']['account']['bank']['account'] ?? '');

        // เช็ค 1: บัญชีปลายทางต้องเป็นบัญชีร้าน
        $configured = (string) ($bot->settings?->slip_receiver_account ?? '');
        if ($configured === '' || ! self::accountMatches($configured, $receiverAccount)) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'wrong_account',
                amount: $slipAmount, transRef: $transRef,
            ), $receiverAccount);
        }

        // เช็ค 2: สลิปซ้ำ (เคย passed แล้วใน bot นี้)
        $isDuplicate = SlipVerification::where('bot_id', $bot->id)
            ->where('trans_ref', $transRef)
            ->where('status', 'passed')
            ->exists();
        if ($isDuplicate) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'duplicate',
                amount: $slipAmount, transRef: $transRef,
            ), $receiverAccount);
        }

        // เช็ค 3: ต้องมีออเดอร์ค้างชำระใน history
        $expected = $this->findExpectedPayment($conversationHistory);
        if ($expected === null) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'no_pending_order',
                amount: $slipAmount, transRef: $transRef,
            ), $receiverAccount);
        }

        // เช็ค 4: ยอดต้องตรง (± tolerance)
        $tolerance = (float) ($bot->settings?->slip_amount_tolerance ?? 0);
        if (abs($slipAmount - $expected['total']) > $tolerance) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'amount_mismatch',
                amount: $slipAmount, transRef: $transRef,
                expectedAmount: $expected['total'], orderSummary: $expected['summary'],
            ), $receiverAccount);
        }

        return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
            isSlip: true, passed: true,
            amount: $slipAmount, transRef: $transRef,
            expectedAmount: $expected['total'], orderSummary: $expected['summary'],
        ), $receiverAccount);
    }

    /**
     * บันทึกผลการตรวจลงตาราง slip_verifications (ไม่ throw — ประวัติพังต้องไม่ล้มการตอบ)
     */
    private function record(
        Bot $bot,
        ?Conversation $conversation,
        ?Message $message,
        ?array $rawResponse,
        SlipVerificationResult $result,
        ?string $receiverAccount = null,
    ): SlipVerificationResult {
        try {
            SlipVerification::create([
                'bot_id' => $bot->id,
                'conversation_id' => $conversation?->id,
                'message_id' => $message?->id,
                'trans_ref' => $result->transRef,
                'amount' => $result->amount,
                'receiver_account' => $receiverAccount,
                'status' => $result->passed ? 'passed' : ($result->failReason ?? 'api_error'),
                'raw_response' => $rawResponse,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record slip verification', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);
        }

        return $result;
    }
```

- [ ] **Step 4: รัน test ให้ผ่าน**

Run: `php artisan test --filter=SlipVerificationServiceTest`
Expected: PASS (10 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Payment/SlipVerificationService.php backend/tests/Feature/SlipVerificationServiceTest.php
git commit -m "feat(slip): verify slips against EasySlip with 4-check decision + audit trail

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: แจ้งเตือนแอดมินผ่าน Telegram เมื่อตรวจไม่ผ่าน

**Files:**
- Modify: `backend/app/Services/Payment/SlipVerificationService.php`
- Test: `backend/tests/Feature/SlipVerificationAlertTest.php`

**Interfaces:**
- Consumes: `FlowPlugin` model (`type='telegram'`, `config['access_token']`, `config['chat_id']`), `$bot->defaultFlow` relation
- Produces: `notifyAdmin(Bot $bot, ?Conversation $conversation, SlipVerificationResult $result): void` — หา Telegram plugin ที่ enabled จาก default flow ของบอท แล้วส่งข้อความแจ้งเหตุผล; ไม่มี plugin → log warning เฉยๆ ไม่ throw

- [ ] **Step 1: เขียน failing test**

```php
<?php
// backend/tests/Feature/SlipVerificationAlertTest.php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\Payment\SlipVerificationResult;
use App\Services\Payment\SlipVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlipVerificationAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_telegram_alert_with_fail_reason(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งออเดอร์',
            'enabled' => true,
            'config' => ['access_token' => 'tg-token', 'chat_id' => '-100123'],
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $result = new SlipVerificationResult(
            isSlip: true, passed: false, failReason: 'amount_mismatch',
            amount: 1000.0, transRef: 'TR1', expectedAmount: 1500.0,
        );

        app(SlipVerificationService::class)->notifyAdmin($bot->fresh(), null, $result);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org/bottg-token/sendMessage')
                && str_contains($request['text'], 'ยอดไม่ตรง')
                && str_contains($request['text'], '1,000')
                && str_contains($request['text'], '1,500');
        });
    }

    public function test_no_telegram_plugin_does_not_throw(): void
    {
        $bot = Bot::factory()->create();
        Http::fake();

        app(SlipVerificationService::class)->notifyAdmin($bot, null, new SlipVerificationResult(
            isSlip: true, passed: false, failReason: 'fake',
        ));

        Http::assertNothingSent();
        $this->addToAssertionCount(1); // ไม่ throw = ผ่าน
    }
}
```

- [ ] **Step 2: รัน test ให้ fail**

Run: `php artisan test --filter=SlipVerificationAlertTest`
Expected: FAIL — method notifyAdmin not found

- [ ] **Step 3: implement `notifyAdmin` ใน SlipVerificationService**

```php
    private const FAIL_REASON_LABELS = [
        'fake' => 'ไม่พบธุรกรรมในระบบธนาคาร (อาจเป็นสลิปปลอม)',
        'duplicate' => 'สลิปซ้ำ (เคยใช้ยืนยันไปแล้ว)',
        'amount_mismatch' => 'ยอดไม่ตรงกับออเดอร์',
        'wrong_account' => 'โอนเข้าบัญชีอื่น (ไม่ใช่บัญชีร้าน)',
        'no_pending_order' => 'ไม่พบออเดอร์ค้างชำระในบทสนทนา',
        'api_error' => 'ระบบตรวจสลิป (EasySlip) ใช้งานไม่ได้ชั่วคราว',
    ];

    public function notifyAdmin(Bot $bot, ?Conversation $conversation, SlipVerificationResult $result): void
    {
        $plugin = $bot->defaultFlow?->plugins()
            ->where('type', 'telegram')
            ->where('enabled', true)
            ->first();

        if (! $plugin) {
            Log::warning('Slip alert: no enabled telegram plugin', ['bot_id' => $bot->id]);

            return;
        }

        $token = $plugin->config['access_token'] ?? '';
        $chatId = $plugin->config['chat_id'] ?? '';
        if (empty($token) || empty($chatId)) {
            Log::warning('Slip alert: telegram plugin missing config', ['plugin_id' => $plugin->id]);

            return;
        }

        $reason = self::FAIL_REASON_LABELS[$result->failReason] ?? ($result->failReason ?? 'unknown');
        $lines = ["⚠️ ตรวจสลิปไม่ผ่าน — {$bot->name}", "เหตุผล: {$reason}"];
        if ($result->amount !== null) {
            $lines[] = 'ยอดในสลิป: '.number_format($result->amount, 2).' บาท';
        }
        if ($result->expectedAmount !== null) {
            $lines[] = 'ยอดออเดอร์: '.number_format($result->expectedAmount, 2).' บาท';
        }
        if ($result->transRef !== null) {
            $lines[] = "เลขอ้างอิง: {$result->transRef}";
        }
        if ($conversation !== null) {
            $lines[] = "Conversation: #{$conversation->id}";
        }
        $lines[] = 'กรุณาตรวจสอบในแชทด่วน';

        try {
            Http::retry(2, 500)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => implode("\n", $lines),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Slip alert: telegram send failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);
        }
    }
```

หมายเหตุ: test ข้อแรก assert `1,000`/`1,500` — `number_format(1000.0, 2)` ให้ `1,000.00` ซึ่ง `str_contains` เจอ `1,000` อยู่แล้ว ผ่านได้

- [ ] **Step 4: รัน test ให้ผ่าน**

Run: `php artisan test --filter=SlipVerificationAlertTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Payment/SlipVerificationService.php backend/tests/Feature/SlipVerificationAlertTest.php
git commit -m "feat(slip): telegram admin alert on failed slip verification

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: เกี่ยว SlipVerificationService เข้า pipeline image branch

**Files:**
- Modify: `backend/app/Services/LineWebhook/LineWebhookResponseService.php` (constructor + `generateImageResponse` + method ใหม่)
- Test: `backend/tests/Feature/SlipVerificationPipelineTest.php`

**Interfaces:**
- Consumes: Task 3 `verify(...)`, Task 4 `notifyAdmin(...)`, `WebhookContext` (`$ctx->bot`, `$ctx->conversation`, `$ctx->userMessage`, `$ctx->metadata`, `$ctx->response`), `ResponseEnvelope::text()`, `getVisionConversationHistory()`
- Produces: พฤติกรรมใหม่ใน image branch:
  - ปิด feature/ไม่มี token → พฤติกรรมเดิม 100% (ไม่เรียก EasySlip)
  - slip ผ่าน → ตอบ success template, บันทึก bot message (`metadata.slip_verification=true, slip_status='passed'`), **ไม่เรียก vision**
  - slip ไม่ผ่าน → ตอบ fail template + `notifyAdmin`, **ไม่เรียก vision**
  - ไม่ใช่สลิป → vision เดิม
  - api_error → `notifyAdmin` + vision เดิม
- Default templates (constants ใน `LineWebhookResponseService`):
  - `SLIP_SUCCESS_TEMPLATE = "เงินเข้าแล้ว {amount} บาท ✅\nออเดอร์: {order_summary}\nส่งใน 5-10 นาที ขอบคุณครับ\n[ยืนยันชำระเงิน]"`
  - `SLIP_FAIL_TEMPLATE = "ได้รับสลิปแล้วครับ ขอตรวจสอบยอดสักครู่ เดี๋ยวแอดมินยืนยันให้อีกครั้งนะครับ 🙏"`

- [ ] **Step 1: เขียน failing test**

ก่อนเขียน ดู `tests/Unit/Jobs/ProcessAggregatedMessagesShouldGenerateTest.php` และ `tests/Feature/LINEWebhookTest.php` ว่า pipeline ถูก invoke ยังไงใน test ที่มีอยู่ — ถ้ามี helper สร้าง `WebhookContext` ให้ใช้ตาม ถ้าไม่มีให้สร้างตรงๆ แบบด้านล่าง (`new WebhookContext($bot, $event)` แล้ว set properties เอง)

```php
<?php
// backend/tests/Feature/SlipVerificationPipelineTest.php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\User;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\WebhookContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlipVerificationPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'tok-123']);

        $this->bot = Bot::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'primary_chat_model' => 'google/gemini-3.5-flash',
        ]);
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
        ]);

        $profile = CustomerProfile::factory()->create();
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => $profile->id,
            'is_handover' => false,
        ]);

        // ประวัติ: บอทสรุปยอดไว้แล้ว (ทำให้มี pending order 1,500)
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender' => 'bot',
            'type' => 'text',
            'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3",
        ]);
    }

    private function makeContext(): WebhookContext
    {
        $userMessage = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'sender' => 'user',
            'type' => 'image',
            'content' => '[รูปภาพ]',
            'media_url' => 'https://cdn.example.com/slip.jpg',
        ]);

        $ctx = new WebhookContext($this->bot, [
            'type' => 'message',
            'message' => ['type' => 'image', 'id' => 'msg-1'],
            'source' => ['userId' => 'U123'],
            'replyToken' => 'rt-1',
        ]);
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $userMessage;

        return $ctx;
    }

    public function test_passed_slip_replies_confirmation_without_vision(): void
    {
        Http::fake([
            'developer.easyslip.com/*' => Http::response([
                'status' => 200,
                'data' => [
                    'transRef' => 'TR900',
                    'amount' => ['amount' => 1500],
                    'receiver' => ['bank' => ['id' => '004'], 'account' => ['name' => ['th' => 'ร้าน'], 'bank' => ['account' => 'xxx-x-x4880-x']]],
                ],
            ]),
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([], 500), // ต้องไม่ถูกเรียก
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertNotNull($ctx->response);
        $this->assertStringContainsString('เงินเข้าแล้ว 1,500 บาท', $ctx->response->text);
        $this->assertStringContainsString('[ยืนยันชำระเงิน]', $ctx->response->text);

        $botMessage = $ctx->metadata['bot_message'];
        $this->assertTrue($botMessage->metadata['slip_verification']);
        $this->assertSame('passed', $botMessage->metadata['slip_status']);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'openrouter.ai'));
    }

    public function test_failed_slip_replies_fail_template_and_alerts(): void
    {
        Http::fake([
            'developer.easyslip.com/*' => Http::response([
                'status' => 200,
                'data' => [
                    'transRef' => 'TR901',
                    'amount' => ['amount' => 900],
                    'receiver' => ['bank' => ['id' => '004'], 'account' => ['name' => ['th' => 'ร้าน'], 'bank' => ['account' => 'xxx-x-x4880-x']]],
                ],
            ]),
            'api.line.me/*' => Http::response(['ok' => true]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertStringContainsString('ขอตรวจสอบยอดสักครู่', $ctx->response->text);
        $this->assertStringNotContainsString('[ยืนยันชำระเงิน]', $ctx->response->text);
        $this->assertSame('amount_mismatch', $ctx->metadata['bot_message']->metadata['slip_status']);
    }

    public function test_non_slip_image_falls_through_to_vision(): void
    {
        Http::fake([
            'developer.easyslip.com/*' => Http::response(['status' => 400, 'message' => 'invalid_image'], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'รูปแมวน่ารักครับ']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertNotNull($ctx->response);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'openrouter.ai'));
    }

    public function test_disabled_feature_never_calls_easyslip(): void
    {
        $this->bot->settings->update(['slip_verification_enabled' => false]);
        Http::fake([
            'api.line.me/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'ตอบจาก vision']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'easyslip.com'));
    }
}
```

หมายเหตุ implementation ของ test: ถ้า `ResponseEnvelope` ไม่มี public `text` property ให้เปิดไฟล์ `backend/app/Services/LineWebhook/ResponseEnvelope.php` ดูชื่อ property/method จริงแล้วปรับ assertion; ถ้า `CustomerProfile`/`Message` factory ต้องการ field เพิ่ม ให้ดู factory จริงแล้วเติม; ถ้า OpenRouter ถูกเรียกผ่าน base URL อื่น ให้ดู `OpenRouterService` แล้วแก้ URL ใน `Http::fake`

- [ ] **Step 2: รัน test ให้ fail**

Run: `php artisan test --filter=SlipVerificationPipelineTest`
Expected: FAIL — ตอนนี้รูปสลิปยังไปเข้า vision (test แรก assert ว่าไม่เรียก openrouter จะพัง)

- [ ] **Step 3: implement hook**

แก้ `LineWebhookResponseService`:

1. เพิ่ม import: `use App\Services\Payment\SlipVerificationService;` และ `use App\Services\Payment\SlipVerificationResult;`
2. เพิ่ม constructor param: `private readonly SlipVerificationService $slipVerification,`
3. เพิ่ม constants ใต้ `ORDER_CONTEXT_KEYWORDS`:

```php
    private const SLIP_SUCCESS_TEMPLATE = "เงินเข้าแล้ว {amount} บาท ✅\nออเดอร์: {order_summary}\nส่งใน 5-10 นาที ขอบคุณครับ\n[ยืนยันชำระเงิน]";

    private const SLIP_FAIL_TEMPLATE = 'ได้รับสลิปแล้วครับ ขอตรวจสอบยอดสักครู่ เดี๋ยวแอดมินยืนยันให้อีกครั้งนะครับ 🙏';
```

4. ใน `generateImageResponse()` — แทรกหลังบรรทัด `$this->line->showLoadingIndicator($ctx->bot, $ctx->userId(), 30);` (ก่อน `try {` ของ vision):

```php
        // Slip verification (EasySlip-first) — ผ่าน/ไม่ผ่านตอบเลย ไม่เข้า vision
        if ($this->trySlipVerification($ctx, $imageUrl)) {
            return;
        }
```

5. เพิ่ม methods ใหม่ท้ายคลาส:

```php
    /**
     * ตรวจสลิปกับ EasySlip ก่อนเข้า vision
     * คืน true = จัดการตอบแล้ว (ข้าม vision), false = ไป vision ต่อ (ไม่ใช่สลิป/ปิด feature/API ล่ม)
     */
    private function trySlipVerification(WebhookContext $ctx, string $imageUrl): bool
    {
        $settings = $ctx->bot->settings;
        if (! $settings?->slip_verification_enabled) {
            return false;
        }

        try {
            $this->conversationContext->autoClearIfIdle($ctx->conversation);
            $history = $this->getVisionConversationHistory($ctx->conversation);

            $result = $this->slipVerification->verify(
                $ctx->bot,
                $ctx->conversation,
                $ctx->userMessage,
                $imageUrl,
                $history,
            );
        } catch (\Throwable $e) {
            Log::error('Slip verification crashed, falling back to vision', [
                'bot_id' => $ctx->bot->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($result->failReason === 'api_error') {
            $this->slipVerification->notifyAdmin($ctx->bot, $ctx->conversation, $result);

            return false; // fallback vision — ลูกค้าต้องได้รับตอบ
        }

        if (! $result->isSlip) {
            return false; // รูปทั่วไป → vision เดิม
        }

        $settings = $ctx->bot->settings;
        if ($result->passed) {
            $template = $settings->slip_success_message ?: self::SLIP_SUCCESS_TEMPLATE;
            $text = str_replace(
                ['{amount}', '{order_summary}'],
                [number_format($result->amount ?? 0), $result->orderSummary ?? '-'],
                $template,
            );
        } else {
            $text = $settings->slip_fail_message ?: self::SLIP_FAIL_TEMPLATE;
            $this->slipVerification->notifyAdmin($ctx->bot, $ctx->conversation, $result);
        }

        $botMessage = $ctx->conversation->messages()->create([
            'sender' => 'bot',
            'content' => $text,
            'type' => 'text',
            'metadata' => [
                'slip_verification' => true,
                'slip_status' => $result->passed ? 'passed' : $result->failReason,
                'slip_trans_ref' => $result->transRef,
                'image_url' => $imageUrl,
            ],
        ]);

        $ctx->metadata['bot_message'] = $botMessage;
        $ctx->response = ResponseEnvelope::text($text);

        Log::info('Slip verification handled image', [
            'bot_id' => $ctx->bot->id,
            'conversation_id' => $ctx->conversation->id,
            'status' => $result->passed ? 'passed' : $result->failReason,
            'trans_ref' => $result->transRef,
        ]);

        return true;
    }
```

หมายเหตุ: vision path เดิมเรียก `autoClearIfIdle` อยู่แล้วใน try block — เรียกซ้ำไม่มีผลเสีย (idempotent) แต่ถ้าอยากสะอาด ย้ายมาเรียกก่อน `trySlipVerification` จุดเดียวแล้วลบจาก vision block ก็ได้ (surgical: เลือกอย่างใดอย่างหนึ่ง อย่าให้เรียกซ้ำสองรอบใน slip path)

- [ ] **Step 4: รัน test ให้ผ่าน + รัน test เดิมทั้งหมดของ pipeline**

Run: `php artisan test --filter=SlipVerificationPipelineTest`
Expected: PASS (4 tests)

Run: `php artisan test tests/Feature/LINEWebhookTest.php`
Expected: PASS ทั้งหมด (ยืนยันไม่ทำของเดิมพัง — บอทที่ไม่เปิด feature ไม่กระทบ)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/LineWebhook/LineWebhookResponseService.php backend/tests/Feature/SlipVerificationPipelineTest.php
git commit -m "feat(slip): hook EasySlip-first verification into pipeline image branch

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: BotSettingController — รับ/คืนค่า slip settings

**Files:**
- Modify: `backend/app/Http/Controllers/Api/BotSettingController.php` (validation rules ใน `update()`)
- Test: `backend/tests/Feature/BotSlipSettingsApiTest.php`

**Interfaces:**
- Consumes: Task 1 (คอลัมน์ + fillable)
- Produces: PUT `/api/bots/{bot}/settings` รับ fields: `slip_verification_enabled` (boolean), `slip_receiver_account` (nullable string ≤50), `slip_amount_tolerance` (numeric 0-10000), `slip_success_message` (nullable string ≤1000), `slip_fail_message` (nullable string ≤1000); GET เดิมคืนค่าอัตโนมัติ (คืน `$settings->fresh()` อยู่แล้ว)

- [ ] **Step 1: เขียน failing test**

```php
<?php
// backend/tests/Feature/BotSlipSettingsApiTest.php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotSlipSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_slip_settings(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
            'slip_amount_tolerance' => 5,
            'slip_success_message' => 'เงินเข้าแล้ว {amount} บาท ✅ [ยืนยันชำระเงิน]',
            'slip_fail_message' => 'ขอตรวจสอบสักครู่ครับ',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('bot_settings', [
            'bot_id' => $bot->id,
            'slip_verification_enabled' => true,
            'slip_receiver_account' => '223-3-24880-3',
        ]);
    }

    public function test_tolerance_over_limit_rejected(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [
            'slip_amount_tolerance' => 99999,
        ])->assertStatus(422);
    }
}
```

(ถ้า test เดิมของ settings API ใช้ Sanctum `actingAs($user, 'sanctum')` หรือ token header — เปิด `tests/Feature/BotApiTest.php` ดูแพทเทิร์น auth แล้วใช้ตามนั้น)

- [ ] **Step 2: รัน test ให้ fail**

Run: `php artisan test --filter=BotSlipSettingsApiTest`
Expected: FAIL — fields ถูก validation ทิ้ง (ไม่อยู่ใน `$validated`) → assertDatabaseHas พัง

- [ ] **Step 3: เพิ่ม validation rules**

ใน `BotSettingController::update()` เพิ่มต่อท้าย rules `'auto_assignment_mode' => ...`:

```php
            // Slip verification settings
            'slip_verification_enabled' => 'boolean',
            'slip_receiver_account' => 'nullable|string|max:50',
            'slip_amount_tolerance' => 'numeric|min:0|max:10000',
            'slip_success_message' => 'nullable|string|max:1000',
            'slip_fail_message' => 'nullable|string|max:1000',
```

- [ ] **Step 4: รัน test ให้ผ่าน**

Run: `php artisan test --filter=BotSlipSettingsApiTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Api/BotSettingController.php backend/tests/Feature/BotSlipSettingsApiTest.php
git commit -m "feat(slip): accept slip verification fields in bot settings API

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: UserSettingController — จัดการ EasySlip token (update/test/clear)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/UserSettingController.php`
- Modify: `backend/routes/api.php` (ใน `Route::prefix('settings')` group)
- Test: `backend/tests/Feature/EasySlipTokenApiTest.php`

**Interfaces:**
- Consumes: Task 1 (`easyslip_api_token` column + helpers)
- Produces:
  - `GET /api/settings` (ของเดิม `show()`) เพิ่ม `easyslip_configured` (bool) + `easyslip_token_masked` (string|null) ใน data
  - `PUT /api/settings/easyslip` body `{token: string}` → บันทึก token
  - `POST /api/settings/test-easyslip` → เรียก `GET https://developer.easyslip.com/api/v1/me` ด้วย token ที่บันทึกไว้ → `{success: bool, message, quota?: {used, max, remaining}}`
  - `DELETE /api/settings/easyslip` → ล้าง token

- [ ] **Step 1: เขียน failing test**

```php
<?php
// backend/tests/Feature/EasySlipTokenApiTest.php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EasySlipTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_show_and_clear_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/settings/easyslip', ['token' => 'es-token-9876'])
            ->assertOk();

        $show = $this->actingAs($user)->getJson('/api/settings');
        $show->assertOk()
            ->assertJsonPath('data.easyslip_configured', true);
        $this->assertStringEndsWith('9876', $show->json('data.easyslip_token_masked'));

        $this->actingAs($user)->deleteJson('/api/settings/easyslip')->assertOk();
        $this->actingAs($user)->getJson('/api/settings')
            ->assertJsonPath('data.easyslip_configured', false);
    }

    public function test_test_connection_returns_quota(): void
    {
        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'es-token-1']);

        Http::fake([
            'developer.easyslip.com/api/v1/me' => Http::response([
                'status' => 200,
                'data' => ['application' => 'bot-fb', 'usedQuota' => 16, 'maxQuota' => 250, 'remainingQuota' => 234],
            ]),
        ]);

        $this->actingAs($user)->postJson('/api/settings/test-easyslip')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('quota.remaining', 234);
    }

    public function test_test_connection_without_token_fails(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/settings/test-easyslip')
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_test_connection_invalid_token(): void
    {
        $user = User::factory()->create();
        $user->getOrCreateSettings()->update(['easyslip_api_token' => 'bad-token']);

        Http::fake([
            'developer.easyslip.com/api/v1/me' => Http::response(['status' => 401, 'message' => 'unauthorized'], 401),
        ]);

        $this->actingAs($user)->postJson('/api/settings/test-easyslip')
            ->assertOk()
            ->assertJsonPath('success', false);
    }
}
```

(ปรับวิธี auth ตามแพทเทิร์น test settings เดิมเช่นเดียวกับ Task 6)

- [ ] **Step 2: รัน test ให้ fail**

Run: `php artisan test --filter=EasySlipTokenApiTest`
Expected: FAIL — 404 route not found

- [ ] **Step 3: implement**

`routes/api.php` — เพิ่มใน `Route::prefix('settings')` group (หลังบรรทัด `settings.line.clear`):

```php
        Route::put('/easyslip', [UserSettingController::class, 'updateEasySlip'])->name('settings.easyslip.update');
        Route::post('/test-easyslip', [UserSettingController::class, 'testEasySlip'])->name('settings.easyslip.test');
        Route::delete('/easyslip', [UserSettingController::class, 'clearEasySlip'])->name('settings.easyslip.clear');
```

`UserSettingController`:

1. ใน `show()` เพิ่มใน data array:

```php
                'easyslip_configured' => $settings?->hasEasySlipToken() ?? false,
                'easyslip_token_masked' => $settings?->masked_easyslip_token,
```

2. เพิ่ม methods (ตามแพทเทิร์น OpenRouter ในไฟล์เดียวกัน):

```php
    /**
     * Update EasySlip API token.
     */
    public function updateEasySlip(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:500',
        ]);

        $settings = $request->user()->getOrCreateSettings();
        $settings->easyslip_api_token = $validated['token'];
        $settings->save();

        return response()->json([
            'message' => 'EasySlip settings updated successfully',
            'data' => [
                'easyslip_configured' => $settings->hasEasySlipToken(),
                'easyslip_token_masked' => $settings->masked_easyslip_token,
            ],
        ]);
    }

    /**
     * Test EasySlip connection + quota.
     */
    public function testEasySlip(Request $request): JsonResponse
    {
        $settings = $request->user()->settings;

        if (! $settings?->hasEasySlipToken()) {
            return response()->json([
                'success' => false,
                'message' => 'EasySlip API token not configured',
            ], 400);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withToken($settings->getEasySlipApiToken())
                ->get('https://developer.easyslip.com/api/v1/me');

            if (! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token ไม่ถูกต้องหรือหมดอายุ (HTTP '.$response->status().')',
                ]);
            }

            $data = $response->json('data', []);

            return response()->json([
                'success' => true,
                'message' => 'เชื่อมต่อ EasySlip สำเร็จ',
                'quota' => [
                    'used' => $data['usedQuota'] ?? null,
                    'max' => $data['maxQuota'] ?? null,
                    'remaining' => $data['remainingQuota'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'เชื่อมต่อ EasySlip ไม่ได้: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Clear EasySlip token.
     */
    public function clearEasySlip(Request $request): JsonResponse
    {
        $settings = $request->user()->settings;
        if ($settings) {
            $settings->easyslip_api_token = null;
            $settings->save();
        }

        return response()->json(['message' => 'EasySlip token cleared']);
    }
```

(ใช้ import `Http` แบบ facade ตามสไตล์ไฟล์เดิม — ถ้าไฟล์มี `use Illuminate\Support\Facades\Http;` แล้วก็เรียก `Http::` ตรงๆ)

- [ ] **Step 4: รัน test ให้ผ่าน**

Run: `php artisan test --filter=EasySlipTokenApiTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/Api/UserSettingController.php backend/routes/api.php backend/tests/Feature/EasySlipTokenApiTest.php
git commit -m "feat(slip): EasySlip token management endpoints (save/test/clear)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: Frontend — แท็บ "ตรวจสลิป" ในหน้าตั้งค่าบอท

**Files:**
- Create: `frontend/src/components/bot-settings/SlipVerificationTab.tsx`
- Modify: `frontend/src/types/api.ts` (interface `BotSettings`)
- Modify: `frontend/src/pages/BotSettingsPage.tsx` (TABS, form data, sync, dirty, save, render)
- Test: `frontend/src/components/bot-settings/SlipVerificationTab.test.tsx`

**Interfaces:**
- Consumes: Task 6 API fields; component pattern จาก `StickerReplyTab.tsx` (`Panel`, `SettingRow`, `Switch`, `Input`, `Textarea`, `onChange(field, value)`)
- Produces: `SlipVerificationTab` props: `{ slip_verification_enabled: boolean; slip_receiver_account: string; slip_amount_tolerance: number; slip_success_message: string; slip_fail_message: string; onChange: (field: string, value: unknown) => void }`

- [ ] **Step 1: เพิ่ม fields ใน types**

`frontend/src/types/api.ts` — ใน `interface BotSettings` ต่อท้าย `reply_sticker_ai_prompt`:

```typescript
  // Slip verification settings
  slip_verification_enabled: boolean;
  slip_receiver_account: string | null;
  slip_amount_tolerance: number;
  slip_success_message: string | null;
  slip_fail_message: string | null;
```

- [ ] **Step 2: เขียน component test (failing)**

ดูก่อนว่า component tests เดิมใช้แพทเทิร์นไหน (`ls frontend/src/components/**/*.test.tsx` — ถ้าโปรเจกต์ยังไม่มี component test ให้ดู `frontend/src/test/` setup) แล้วเขียนตาม ตัวอย่าง:

```tsx
// frontend/src/components/bot-settings/SlipVerificationTab.test.tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { SlipVerificationTab } from './SlipVerificationTab';

const baseProps = {
  slip_verification_enabled: false,
  slip_receiver_account: '',
  slip_amount_tolerance: 0,
  slip_success_message: '',
  slip_fail_message: '',
  onChange: vi.fn(),
};

describe('SlipVerificationTab', () => {
  it('toggle เปิดใช้งานเรียก onChange', () => {
    const onChange = vi.fn();
    render(<SlipVerificationTab {...baseProps} onChange={onChange} />);

    fireEvent.click(screen.getByRole('switch'));
    expect(onChange).toHaveBeenCalledWith('slip_verification_enabled', true);
  });

  it('ซ่อนรายละเอียดเมื่อปิดใช้งาน', () => {
    render(<SlipVerificationTab {...baseProps} />);
    expect(screen.queryByLabelText(/เลขบัญชี/)).toBeNull();
  });

  it('แสดงช่องตั้งค่าเมื่อเปิดใช้งาน', () => {
    render(<SlipVerificationTab {...baseProps} slip_verification_enabled={true} />);
    expect(screen.getByText(/เลขบัญชีร้าน/)).toBeInTheDocument();
    expect(screen.getByText(/ยอดคลาดเคลื่อน/)).toBeInTheDocument();
  });
});
```

Run: `npx vitest run src/components/bot-settings/SlipVerificationTab.test.tsx`
Expected: FAIL — module not found

- [ ] **Step 3: สร้าง SlipVerificationTab**

```tsx
// frontend/src/components/bot-settings/SlipVerificationTab.tsx
import { Receipt, ShieldCheck } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Panel } from '@/components/common';
import { SettingRow } from '@/components/connections';

interface SlipVerificationTabProps {
  slip_verification_enabled: boolean;
  slip_receiver_account: string;
  slip_amount_tolerance: number;
  slip_success_message: string;
  slip_fail_message: string;
  onChange: (field: string, value: unknown) => void;
}

export function SlipVerificationTab({
  slip_verification_enabled,
  slip_receiver_account,
  slip_amount_tolerance,
  slip_success_message,
  slip_fail_message,
  onChange,
}: SlipVerificationTabProps) {
  return (
    <div className="space-y-6">
      <Panel
        icon={Receipt}
        title="ตรวจสลิปอัตโนมัติ (EasySlip)"
        description="ตรวจสลิปโอนเงินกับธนาคารจริงก่อนบอทยืนยันรับเงิน — ต้องตั้งค่า EasySlip API Token ในหน้า Settings ก่อน"
      >
        <div className="px-5 py-4 space-y-5">
          <SettingRow label="เปิดตรวจสลิปอัตโนมัติ" htmlFor="slip-toggle">
            <Switch
              id="slip-toggle"
              checked={slip_verification_enabled}
              onCheckedChange={(checked) => onChange('slip_verification_enabled', checked)}
            />
          </SettingRow>

          {slip_verification_enabled && (
            <div className="ml-4 pl-4 border-l-2 border-muted space-y-5 mt-2">
              <SettingRow
                label="เลขบัญชีร้าน (รับเงินเข้า)"
                htmlFor="slip-account"
                description="สลิปต้องโอนเข้าบัญชีนี้เท่านั้นถึงจะผ่าน เช่น 223-3-24880-3"
                orientation="vertical"
              >
                <Input
                  id="slip-account"
                  placeholder="223-3-24880-3"
                  value={slip_receiver_account}
                  onChange={(e) => onChange('slip_receiver_account', e.target.value)}
                />
              </SettingRow>

              <SettingRow
                label="ยอดคลาดเคลื่อนได้ไม่เกิน (บาท)"
                htmlFor="slip-tolerance"
                description="0 = ยอดต้องตรงกับออเดอร์เป๊ะ"
                orientation="vertical"
              >
                <Input
                  id="slip-tolerance"
                  type="number"
                  min={0}
                  max={10000}
                  value={slip_amount_tolerance}
                  onChange={(e) => onChange('slip_amount_tolerance', Number(e.target.value))}
                  className="max-w-[160px]"
                />
              </SettingRow>

              <SettingRow
                label="ข้อความเมื่อตรวจผ่าน"
                htmlFor="slip-success"
                description="ใช้ {amount} = ยอดเงิน, {order_summary} = รายการสินค้า — เว้นว่าง = ใช้ข้อความเริ่มต้น (ต้องมี [ยืนยันชำระเงิน] เพื่อให้ระบบบันทึกออเดอร์)"
                orientation="vertical"
              >
                <Textarea
                  id="slip-success"
                  placeholder={'เงินเข้าแล้ว {amount} บาท ✅\nออเดอร์: {order_summary}\nส่งใน 5-10 นาที ขอบคุณครับ\n[ยืนยันชำระเงิน]'}
                  value={slip_success_message}
                  onChange={(e) => onChange('slip_success_message', e.target.value)}
                  rows={4}
                />
              </SettingRow>

              <SettingRow
                label="ข้อความเมื่อตรวจไม่ผ่าน"
                htmlFor="slip-fail"
                description="บอทจะไม่ยืนยันออเดอร์ และแจ้งเตือนแอดมินทาง Telegram อัตโนมัติ"
                orientation="vertical"
              >
                <Textarea
                  id="slip-fail"
                  placeholder="ได้รับสลิปแล้วครับ ขอตรวจสอบยอดสักครู่ เดี๋ยวแอดมินยืนยันให้อีกครั้งนะครับ 🙏"
                  value={slip_fail_message}
                  onChange={(e) => onChange('slip_fail_message', e.target.value)}
                  rows={3}
                />
              </SettingRow>

              <div className="flex items-start gap-2 rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground">
                <ShieldCheck className="size-4 shrink-0 mt-0.5" strokeWidth={1.5} />
                <p>
                  ระบบตรวจ 4 อย่าง: สลิปจริง (เช็คกับธนาคาร) · โอนเข้าบัญชีร้าน · ยอดตรงกับออเดอร์ ·
                  ไม่ใช่สลิปซ้ำ — รูปที่ไม่ใช่สลิปจะตอบด้วย AI ตามปกติ
                </p>
              </div>
            </div>
          )}
        </div>
      </Panel>
    </div>
  );
}
```

- [ ] **Step 4: wiring ใน BotSettingsPage.tsx**

1. imports: `import { Receipt } from 'lucide-react';` (เพิ่มใน import lucide เดิม) และ `import { SlipVerificationTab } from '@/components/bot-settings/SlipVerificationTab';`
2. `TABS` เพิ่ม: `{ value: 'slip', label: 'ตรวจสลิป', icon: Receipt },`
3. `BotSettingsFormData` เพิ่ม:

```typescript
  slip_verification_enabled: boolean;
  slip_receiver_account: string;
  slip_amount_tolerance: number;
  slip_success_message: string;
  slip_fail_message: string;
```

4. `DEFAULT_FORM` เพิ่ม:

```typescript
  slip_verification_enabled: false,
  slip_receiver_account: '',
  slip_amount_tolerance: 0,
  slip_success_message: '',
  slip_fail_message: '',
```

5. sync `useEffect` (setFormData จาก serverSettings) เพิ่ม:

```typescript
      slip_verification_enabled: (s.slip_verification_enabled as boolean) ?? false,
      slip_receiver_account: (s.slip_receiver_account as string) ?? '',
      slip_amount_tolerance: (s.slip_amount_tolerance as number) ?? 0,
      slip_success_message: (s.slip_success_message as string) ?? '',
      slip_fail_message: (s.slip_fail_message as string) ?? '',
```

6. dirty check เพิ่ม (ใน `const dirty = ...` chain):

```typescript
      formData.slip_verification_enabled !== ((s.slip_verification_enabled as boolean) ?? false) ||
      formData.slip_receiver_account !== ((s.slip_receiver_account as string) ?? '') ||
      formData.slip_amount_tolerance !== ((s.slip_amount_tolerance as number) ?? 0) ||
      formData.slip_success_message !== ((s.slip_success_message as string) ?? '') ||
      formData.slip_fail_message !== ((s.slip_fail_message as string) ?? '') ||
```

7. `handleSave` payload เพิ่ม:

```typescript
        slip_verification_enabled: formData.slip_verification_enabled,
        slip_receiver_account: formData.slip_receiver_account || null,
        slip_amount_tolerance: formData.slip_amount_tolerance,
        slip_success_message: formData.slip_success_message || null,
        slip_fail_message: formData.slip_fail_message || null,
```

8. render (หลัง block `{tab === 'sticker' && ...}`):

```tsx
          {tab === 'slip' && (
            <SlipVerificationTab
              slip_verification_enabled={formData.slip_verification_enabled}
              slip_receiver_account={formData.slip_receiver_account}
              slip_amount_tolerance={formData.slip_amount_tolerance}
              slip_success_message={formData.slip_success_message}
              slip_fail_message={formData.slip_fail_message}
              onChange={onFieldChange}
            />
          )}
```

- [ ] **Step 5: รัน test + typecheck ให้ผ่าน**

Run: `npx vitest run src/components/bot-settings/SlipVerificationTab.test.tsx`
Expected: PASS (3 tests)

Run: `npx tsc --noEmit`
Expected: no errors

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/bot-settings/SlipVerificationTab.tsx frontend/src/components/bot-settings/SlipVerificationTab.test.tsx frontend/src/types/api.ts frontend/src/pages/BotSettingsPage.tsx
git commit -m "feat(slip): slip verification settings tab in bot settings page

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 9: Frontend — ช่อง EasySlip API Token ในหน้า Settings รวม

**Files:**
- Modify: `frontend/src/hooks/useUserSettings.ts` (hooks ใหม่ 3 ตัว + type)
- Modify: `frontend/src/pages/SettingsPage.tsx` (section ใหม่)
- Modify: `frontend/src/types/api.ts` (ถ้า `UserSettings` type อยู่ที่นี่ — เพิ่ม `easyslip_configured: boolean; easyslip_token_masked: string | null;`)

**Interfaces:**
- Consumes: Task 7 endpoints (`PUT /settings/easyslip` body `{token}`, `POST /settings/test-easyslip`, `DELETE /settings/easyslip`), แพทเทิร์น hooks เดิมใน `useUserSettings.ts` (`useUpdateOpenRouterSettings`, `useTestOpenRouterConnection`, `useClearOpenRouterKey`)
- Produces: `useUpdateEasySlipToken()`, `useTestEasySlipConnection()`, `useClearEasySlipToken()` + UI section "EasySlip API Token" ใน SettingsPage

- [ ] **Step 1: เพิ่ม hooks**

เปิด `frontend/src/hooks/useUserSettings.ts` ดูแพทเทิร์น mutation ของ OpenRouter (invalidateQueries key อะไร) แล้วเพิ่มตามแพทเทิร์นเดิมเป๊ะ:

```typescript
export function useUpdateEasySlipToken() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (data: { token: string }) => {
      const response = await apiPut<ApiResponse<UserSettings>>('/settings/easyslip', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['user-settings'] });
    },
  });
}

export function useTestEasySlipConnection() {
  return useMutation({
    mutationFn: async () => {
      const response = await apiPost<{
        success: boolean;
        message: string;
        quota?: { used: number | null; max: number | null; remaining: number | null };
      }>('/settings/test-easyslip', {});
      return response;
    },
  });
}

export function useClearEasySlipToken() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async () => {
      const response = await apiDelete<{ message: string }>('/settings/easyslip');
      return response;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['user-settings'] });
    },
  });
}
```

(ชื่อ query key `['user-settings']` ให้เช็คจากไฟล์จริง — ใช้ key เดียวกับ `useUserSettings()` เดิม)

- [ ] **Step 2: เพิ่ม section ใน SettingsPage**

เปิด `SettingsPage.tsx` ดูโครงสร้าง section "OpenRouter API Key" (Panel/Card + Input password + ปุ่มบันทึก/ทดสอบ/ลบ + masked display) แล้วสร้าง section "EasySlip API Token" ใต้ section LINE ตามแพทเทิร์นเดิมทุกประการ:

- Input type password + state `easySlipToken`
- ปุ่ม "บันทึก" → `updateEasySlip.mutateAsync({ token: easySlipToken })` → toast สำเร็จ + ล้าง input
- ปุ่ม "ทดสอบการเชื่อมต่อ" → `testEasySlip.mutateAsync()` → toast แสดง `message` + โควตาคงเหลือ (`quota.remaining` เช่น "เหลือโควตา 234 สลิป")
- ปุ่ม "ลบ" → `clearEasySlip.mutateAsync()`
- แสดง masked token เมื่อ `settings?.easyslip_configured`
- ลิงก์สมัคร: `https://easyslip.com/` ("รับ API Token ที่ EasySlip")
- คำอธิบาย: "ใช้สำหรับฟีเจอร์ตรวจสลิปอัตโนมัติ — เปิดใช้ต่อบอทได้ที่ ตั้งค่าบอท → ตรวจสลิป"

- [ ] **Step 3: typecheck + build**

Run: `npx tsc --noEmit && npm run build`
Expected: ผ่านทั้งคู่

- [ ] **Step 4: Commit**

```bash
git add frontend/src/hooks/useUserSettings.ts frontend/src/pages/SettingsPage.tsx frontend/src/types/api.ts
git commit -m "feat(slip): EasySlip token management UI in settings page

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 10: Verification รวม + เตรียม deploy

**Files:** ไม่มีไฟล์ใหม่ (ยกเว้นแก้จากผล review)

- [ ] **Step 1: รัน backend test ทั้งชุด**

Run: `cd /Users/jaochai/Code/bot-fb/backend && php artisan test`
Expected: PASS ทั้งหมด (ของเดิม + ใหม่ ~25 tests ที่เพิ่มมา)

- [ ] **Step 2: รัน frontend ทั้งชุด**

Run: `cd /Users/jaochai/Code/bot-fb/frontend && npx vitest run && npx tsc --noEmit && npm run build`
Expected: PASS ทั้งหมด

- [ ] **Step 3: Lint**

Run: `cd /Users/jaochai/Code/bot-fb/backend && ./vendor/bin/pint --dirty --test` (แก้ด้วย `./vendor/bin/pint --dirty` ถ้า fail)
Run: `cd /Users/jaochai/Code/bot-fb/frontend && npm run lint`
Expected: ผ่าน

- [ ] **Step 4: /simplify + /code-review ก่อนสรุป** (ตามกติกาโปรเจกต์: ใช้ /simplify ก่อน commit สุดท้ายเสมอ)

- [ ] **Step 5: Checklist ก่อนเปิดใช้จริง (manual — ทำร่วมกับ user)**

1. Merge + deploy ไป Railway (migration รันอัตโนมัติตอน deploy ตาม flow เดิม; ตรวจว่า `php artisan migrate` อยู่ใน start/deploy command แล้ว)
2. User สมัคร EasySlip แพ็กเกจ Start (99 บาท/250 สลิป) → เอา token มาใส่หน้า Settings → กด "ทดสอบการเชื่อมต่อ" ต้องเห็นโควตา
3. เปิดสวิตช์ที่ ตั้งค่าบอท (bot 26) → แท็บ "ตรวจสลิป" → ใส่เลขบัญชี `223-3-24880-3` → บันทึก
4. ทดสอบจริง: ส่งสลิปจริงยอดตรง 1 ใบ (ต้องได้ "เงินเข้าแล้ว ... ✅" + order เกิดใน dashboard) / ส่งสลิปเดิมซ้ำ (ต้องได้ข้อความตรวจสอบ + Telegram alert "สลิปซ้ำ") / ส่งรูปทั่วไป (ต้องได้คำตอบ vision ปกติ)
5. ดู Sentry + `slip_verifications` table 24 ชม.แรก

---

## Self-Review Notes

- Spec coverage: schema (T1), decision logic 4 เช็ค (T2-T3), Telegram alert (T4), pipeline hook + fallback + template (T5), bot settings API (T6), token API (T7), UI แท็บบอท (T8), UI token (T9), verify+deploy (T10) — ครบทุก section ของ spec; "นอกขอบเขต" ไม่มี task ใดไปแตะ (ไม่มีการแก้ ProcessLINEWebhook legacy, ไม่แตะ bot_hitl_settings)
- จุดที่ผู้ execute ต้องเช็คไฟล์จริงก่อนเขียน (ระบุไว้ใน step แล้ว): รูปแบบ masked accessor ใน UserSetting, แพทเทิร์น auth ใน API tests, โครงสร้าง `ResponseEnvelope`, query key ใน useUserSettings, โครงสร้าง section ใน SettingsPage
- Type consistency: `SlipVerificationResult` fields ตรงกันทุก task; `findExpectedPayment` (ไม่ใช่ findExpectedAmount) ใช้ชื่อเดียวกันใน T2/T3; template placeholders `{amount}`/`{order_summary}` ตรงกันใน T5/T8
