# Per-Bot Reasoning Effort (UI) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ให้เจ้าของบอทตั้ง reasoning effort (low/medium/high) ต่อบอทจากหน้า Connection แล้วระบบส่งค่านั้นเข้า LLM call พร้อม timeout ที่ปรับตาม effort

**Architecture:** เพิ่มคอลัมน์ `bots.reasoning_effort` (default `medium`) → `RAGService` อ่านค่า, map เป็น request timeout (45/60/120s) + ส่ง `reasoning.effort` เข้า `OpenRouterService::chat()` โดย chat() apply reasoning เฉพาะโมเดลที่รองรับ. Frontend: segmented control ใน AI Models section.

**Tech Stack:** Laravel 12 (PHPUnit), React 19 + TypeScript + shadcn/ui + Tailwind (Vitest)

## Global Constraints

- effort values จำกัด: `low` | `medium` | `high` เท่านั้น (validate ทุกชั้น)
- default = `medium` (คงพฤติกรรมเดิม; บอทที่ไม่มีค่าต้องทำงานเหมือนเดิม)
- timeout map: low=45s, medium=60s, high=120s (จาก config, override ได้ด้วย env)
- ห้าม regress: บอทที่ใช้โมเดล non-reasoning ต้องไม่ถูกส่ง `reasoning` payload
- copy ภาษาไทยตาม pattern เดิม (Panel/hint muted-foreground)

## Deviation from spec (ยืนยันแล้วจากโค้ดจริง)

Spec เขียนว่า "ซ่อน effort control เมื่อโมเดลไม่รองรับ reasoning" — แต่ `ModelSelector.tsx` เป็น **free-text `<Input>`** ไม่มี capabilities ให้ frontend เช็ค → เปลี่ยนเป็น **แสดง control เสมอ + hint** ว่า "มีผลเฉพาะโมเดลที่รองรับ reasoning; โมเดลอื่นระบบข้ามให้อัตโนมัติ". ฝั่ง backend chat() gate ด้วย `supportsReasoning($model)` อยู่แล้ว จึงปลอดภัย.

## File Structure

**Backend**
- `database/migrations/xxxx_add_reasoning_effort_to_bots.php` (create) — คอลัมน์
- `app/Models/Bot.php` (modify) — fillable
- `app/Http/Requests/Bot/UpdateBotRequest.php`, `StoreBotRequest.php` (modify) — validate
- `app/Http/Resources/BotResource.php` (modify) — expose
- `config/services.php` (modify) — effort_timeouts + high_effort_max_tokens
- `app/Services/OpenRouterService.php` (modify) — generateBotResponse params + chat() reasoning gate
- `app/Services/RAGService.php` (modify) — อ่าน effort → reasoning + timeout + max_tokens
- `app/Jobs/ProcessAggregatedMessages.php` (modify) — job timeout
- `config/llm-models.php` (modify) — revert A1

**Frontend**
- `src/types/api.ts` (modify) — Bot type
- `src/hooks/useConnectionForm.ts` (modify) — form field
- `src/components/connections/ReasoningEffortSelector.tsx` (create) — control
- `src/components/connections/sections/AIModelsSection.tsx` (modify) — วาง control
- `src/components/connections/ReasoningEffortSelector.test.tsx` (create) — test

---

### Task 1: Data layer — คอลัมน์ + model + validation + resource

**Files:**
- Create: `backend/database/migrations/2026_07_17_000000_add_reasoning_effort_to_bots.php`
- Modify: `backend/app/Models/Bot.php` (`$fillable`, ~line 31-37)
- Modify: `backend/app/Http/Requests/Bot/UpdateBotRequest.php` (~line 30), `StoreBotRequest.php`
- Modify: `backend/app/Http/Resources/BotResource.php` (~line 37)
- Test: `backend/tests/Feature/Bot/UpdateBotReasoningEffortTest.php`

**Interfaces:**
- Produces: `bots.reasoning_effort` (string, nullable, default `'medium'`); `Bot->reasoning_effort`; validation rule `in:low,medium,high`; BotResource key `reasoning_effort`

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Feature/Bot/UpdateBotReasoningEffortTest.php
namespace Tests\Feature\Bot;

use App\Models\Bot;
use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UpdateBotReasoningEffortTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_update_reasoning_effort(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->for($user)->create();

        $this->actingAs($user)
            ->patchJson("/api/bots/{$bot->id}", ['reasoning_effort' => 'high'])
            ->assertOk()
            ->assertJsonPath('data.reasoning_effort', 'high');

        $this->assertSame('high', $bot->fresh()->reasoning_effort);
    }

    public function test_rejects_invalid_reasoning_effort(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->for($user)->create();

        $this->actingAs($user)
            ->patchJson("/api/bots/{$bot->id}", ['reasoning_effort' => 'ultra'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('reasoning_effort');
    }

    public function test_defaults_to_medium(): void
    {
        $bot = Bot::factory()->create();
        $this->assertSame('medium', $bot->fresh()->reasoning_effort);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/Bot/UpdateBotReasoningEffortTest.php`
Expected: FAIL (column/validation/route ยังไม่มี — อาจ error `reasoning_effort` unknown)

- [ ] **Step 3: Create the migration**

```php
<?php
// backend/database/migrations/2026_07_17_000000_add_reasoning_effort_to_bots.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // nullable: validation อนุญาต null ได้ → กัน NOT NULL violation (500); RAGService `?: 'medium'` รับ null อยู่แล้ว
            $table->string('reasoning_effort', 10)->nullable()->default('medium')->after('utility_model');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('reasoning_effort');
        });
    }
};
```

- [ ] **Step 4: Add to Bot fillable**

`backend/app/Models/Bot.php` — เพิ่มใน `$fillable` array (ใกล้ `'utility_model'`):
```php
        'utility_model',
        'reasoning_effort',
```

- [ ] **Step 5: Add validation rule (both requests)**

`UpdateBotRequest.php` และ `StoreBotRequest.php` — เพิ่มใน `rules()`:
```php
            'reasoning_effort' => ['nullable', 'in:low,medium,high'],
```

- [ ] **Step 6: Expose in BotResource**

`backend/app/Http/Resources/BotResource.php` — เพิ่มหลัง `'utility_model'`:
```php
            'utility_model' => $this->utility_model,
            'reasoning_effort' => $this->reasoning_effort,
```

- [ ] **Step 7: Run migration + tests**

Run: `cd backend && php artisan migrate && php artisan test tests/Feature/Bot/UpdateBotReasoningEffortTest.php`
Expected: PASS (3 tests)

- [ ] **Step 8: Commit**

```bash
git add backend/database/migrations backend/app/Models/Bot.php backend/app/Http/Requests/Bot backend/app/Http/Resources/BotResource.php backend/tests/Feature/Bot/UpdateBotReasoningEffortTest.php
git commit -m "feat(bot): เพิ่ม reasoning_effort ต่อบอท (column+validation+resource)"
```

---

### Task 2: OpenRouterService — รับ effort/timeout + gate reasoning ตามโมเดล

**Files:**
- Modify: `backend/config/services.php` (openrouter block, ~line 43)
- Modify: `backend/app/Services/OpenRouterService.php` (`generateBotResponse` ~line 302, chat() reasoning block ~line 101)
- Test: `backend/tests/Unit/Services/OpenRouterServiceTest.php` (เพิ่ม test)

**Interfaces:**
- Consumes: `bots.reasoning_effort` (Task 1)
- Produces: `config('services.openrouter.effort_timeouts')`, `config('services.openrouter.high_effort_max_tokens')`; `generateBotResponse(..., ?array $reasoning = null, ?int $timeout = null)`; chat() ส่ง `payload['reasoning']` เฉพาะเมื่อ `supportsReasoning($model)` โดยใช้ effort จาก `$reasoning` เมื่อมี

- [ ] **Step 1: Write the failing test**

```php
// เพิ่มใน backend/tests/Unit/Services/OpenRouterServiceTest.php
    public function test_generate_bot_response_sends_effort_only_for_reasoning_models(): void
    {
        // reasoning model → effort ถูกส่ง
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response(['data' => [
                ['id' => 'openai/o1', 'supported_parameters' => ['reasoning']],
            ]], 200),
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'g', 'model' => 'openai/o1',
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $this->service->generateBotResponse(
            userMessage: 'hi',
            model: 'openai/o1',
            reasoning: ['effort' => 'high'],
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }
            return ($request->data()['reasoning']['effort'] ?? null) === 'high';
        });
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/Services/OpenRouterServiceTest.php --filter=test_generate_bot_response_sends_effort_only_for_reasoning_models`
Expected: FAIL — `generateBotResponse` ยังไม่มี param `reasoning` (ArgumentCountError/unknown named arg)

- [ ] **Step 3: Add effort_timeouts config**

`backend/config/services.php` — ใน `'openrouter' => [ ... ]` เพิ่มหลัง `'timeout'`:
```php
        'timeout' => env('OPENROUTER_TIMEOUT', 45),
        // medium=45 = no-regress (บอทเดิมทุกตัว default medium ต้องได้ 45s เท่าเดิม); high=90 (LINE loading ตันที่ 60s อยู่แล้ว)
        'effort_timeouts' => [
            'low' => (int) env('OPENROUTER_TIMEOUT_LOW', 45),
            'medium' => (int) env('OPENROUTER_TIMEOUT_MEDIUM', 45),
            'high' => (int) env('OPENROUTER_TIMEOUT_HIGH', 90),
        ],
        'high_effort_max_tokens' => (int) env('OPENROUTER_HIGH_EFFORT_MAX_TOKENS', 8000),
```

- [ ] **Step 4: Add params to generateBotResponse**

`OpenRouterService.php` — signature (~line 302) เพิ่ม 2 param ท้าย:
```php
        ?string $apiKeyOverride = null,
        ?array $reasoning = null,
        ?int $timeout = null
    ): array {
```
และ return call (~line 311) เปลี่ยนเป็น:
```php
        return $this->chat($messages, $model, $temperature, $maxTokens, true, $apiKeyOverride, $fallbackModel, $timeout, $reasoning);
```

- [ ] **Step 5: Gate reasoning ใน chat() ตาม supportsReasoning เท่านั้น**

`OpenRouterService.php` chat() (~line 101) เปลี่ยนเงื่อนไข จาก `if ($reasoning || $capService->supportsReasoning($model))` เป็น:
```php
            // ส่ง reasoning เฉพาะโมเดลที่รองรับ; effort จาก caller (bot setting) เมื่อมี ไม่งั้น default ของโมเดล
            if ($capService->supportsReasoning($model)) {
                $payload['reasoning'] = $reasoning ?? [
                    'effort' => $capService->getDefaultReasoningEffort($model) ?? 'medium',
                ];
                Log::debug('Using reasoning mode', ['model' => $model, 'reasoning' => $payload['reasoning']]);
            }
```

- [ ] **Step 6: แก้ B1 recursion — fallback ต้องไม่รับ high effort/timeout**

`OpenRouterService.php` — ในบล็อก `catch (\Exception $e)` ที่เป็น client-side fallback (จาก #239) เปลี่ยน 2 บรรทัดในการเรียก recursive `chat()`:
```php
                return $this->chat(
                    $messages,
                    $fallbackModel,
                    $temperature,
                    $maxTokens,
                    useFallback: false,
                    apiKeyOverride: $apiKey,
                    fallbackModelOverride: null,
                    timeout: config('services.openrouter.timeout', 45), // fast escape — ไม่ inherit high 90s
                    reasoning: null, // fallback ใช้ default effort ของตัวเอง ไม่รับ high มา (กัน worst-case + กัน gemini-2.5 ได้ high)
                );
```

- [ ] **Step 7: Negative test — non-reasoning model ต้องไม่ได้ reasoning payload**

```php
// เพิ่มใน OpenRouterServiceTest.php
    public function test_generate_bot_response_omits_reasoning_for_non_reasoning_model(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response(['data' => [
                ['id' => 'google/gemini-2.0-flash-001', 'supported_parameters' => []],
            ]], 200),
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'g', 'model' => 'google/gemini-2.0-flash-001',
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $this->service->generateBotResponse(
            userMessage: 'hi',
            model: 'google/gemini-2.0-flash-001',
            reasoning: ['effort' => 'high'],
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }
            return ! isset($request->data()['reasoning']);
        });
    }
```

- [ ] **Step 8: Run tests**

Run: `cd backend && php artisan test tests/Unit/Services/OpenRouterServiceTest.php`
Expected: PASS (29 tests = 27 เดิม + 2 ใหม่)

- [ ] **Step 9: Commit**

```bash
git add backend/config/services.php backend/app/Services/OpenRouterService.php backend/tests/Unit/Services/OpenRouterServiceTest.php
git commit -m "feat(llm): generateBotResponse รับ effort/timeout + ส่ง reasoning เฉพาะโมเดลที่รองรับ + fallback ไม่ inherit high"
```

---

### Task 3: RAGService — adaptive effort → reasoning + timeout + max_tokens

**Files:**
- Modify: `backend/app/Services/RAGService.php` (เพิ่ม method `resolveReasoningEffort`, max_tokens ~line 190-194, generateBotResponse call ~line 210-219)
- Test: `backend/tests/Unit/Services/ResolveReasoningEffortTest.php`, `backend/tests/Feature/RAG/ReasoningEffortWiringTest.php`

**Interfaces:**
- Consumes: `Bot->reasoning_effort` (Task 1); `generateBotResponse(reasoning:, timeout:)` (Task 2); `config('services.openrouter.effort_timeouts'/'high_effort_max_tokens')`; `$complexity['is_complex']` (มีอยู่แล้วใน generateResponse); `$this->openRouter->supportsReasoning($model)` (มีอยู่แล้ว)
- Produces: `RAGService::resolveReasoningEffort(string $botEffort, bool $isComplex): string`; LLM call ที่ carry effort (แบบ adaptive) + timeout ที่ map แล้ว

**Adaptive rule:** ค่าบอทเป็น **เพดาน** — ข้อความ complex ใช้ค่าเต็ม, ข้อความไม่ complex cap ที่ medium (กัน high latency/cost บนข้อความง่าย ~80%)

- [ ] **Step 1: Write the failing unit test (resolveReasoningEffort — deterministic)**

```php
<?php
// backend/tests/Unit/Services/ResolveReasoningEffortTest.php
namespace Tests\Unit\Services;

use App\Services\RAGService;
use Tests\TestCase;

class ResolveReasoningEffortTest extends TestCase
{
    private function resolve(string $botEffort, bool $isComplex): string
    {
        $svc = app(RAGService::class);
        $m = new \ReflectionMethod($svc, 'resolveReasoningEffort');
        $m->setAccessible(true);

        return $m->invoke($svc, $botEffort, $isComplex);
    }

    public function test_complex_message_uses_full_bot_effort(): void
    {
        $this->assertSame('high', $this->resolve('high', true));
        $this->assertSame('low', $this->resolve('low', true));
    }

    public function test_simple_message_caps_high_at_medium(): void
    {
        $this->assertSame('medium', $this->resolve('high', false));
    }

    public function test_simple_message_keeps_low_and_medium(): void
    {
        $this->assertSame('low', $this->resolve('low', false));
        $this->assertSame('medium', $this->resolve('medium', false));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/Services/ResolveReasoningEffortTest.php`
Expected: FAIL — method `resolveReasoningEffort` ยังไม่มี (ReflectionException)

- [ ] **Step 3: เพิ่ม method resolveReasoningEffort ใน RAGService**

`RAGService.php` — เพิ่ม method (วางใกล้ helper อื่น เช่นหลัง `getFallbackChatModelForBot`):
```php
    /**
     * ค่าบอทเป็นเพดาน: complex ใช้เต็ม, ไม่ complex cap ที่ medium (ประหยัด latency/cost ข้อความง่าย)
     */
    protected function resolveReasoningEffort(string $botEffort, bool $isComplex): string
    {
        if ($isComplex) {
            return $botEffort;
        }
        $rank = ['low' => 0, 'medium' => 1, 'high' => 2];

        return ($rank[$botEffort] ?? 1) > 1 ? 'medium' : $botEffort;
    }
```

- [ ] **Step 4: Run unit test**

Run: `cd backend && php artisan test tests/Unit/Services/ResolveReasoningEffortTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Wire ใน generateResponse — effort + timeout + token headroom (gate B7)**

`RAGService.php` — ก่อนบล็อก Step 10 (ก่อน `$result = $this->openRouter->generateBotResponse(`) แทรก:
```php
        // Step 9d: Reasoning effort (ต่อบอท, adaptive) → request timeout + token headroom
        $botEffort = $bot->reasoning_effort ?: 'medium';
        $effort = $this->resolveReasoningEffort($botEffort, $complexity['is_complex']);
        $effortTimeouts = config('services.openrouter.effort_timeouts', []);
        $requestTimeout = $effortTimeouts[$effort] ?? config('services.openrouter.timeout', 45);
        // token headroom เฉพาะ high + โมเดล reasoning จริง (กัน API 400 / override ค่าที่เจ้าของตั้งต่ำ)
        if ($effort === 'high' && $this->openRouter->supportsReasoning($chatModel)) {
            $maxTokens = max($maxTokens, config('services.openrouter.high_effort_max_tokens', 8000));
        }
```
และแก้ call generateBotResponse เพิ่ม 2 named arg ท้าย:
```php
            apiKeyOverride: $apiKey,
            reasoning: ['effort' => $effort],
            timeout: $requestTimeout,
        );
```

- [ ] **Step 6: Write integration test (bot=medium → effort=medium ถึง LLM; deterministic ทุกความซับซ้อน)**

```php
<?php
// backend/tests/Feature/RAG/ReasoningEffortWiringTest.php
namespace Tests\Feature\RAG;

use App\Models\Bot;
use App\Services\RAGService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReasoningEffortWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_bot_effort_reaches_llm_payload(): void
    {
        // bot=medium → adaptive คงเป็น medium ไม่ว่าข้อความ complex หรือไม่ → assertion deterministic
        config(['services.openrouter.api_key' => 'k']);
        Http::fake([
            'openrouter.ai/api/v1/models' => Http::response(['data' => [
                ['id' => 'openai/o1', 'supported_parameters' => ['reasoning']],
            ]], 200),
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'g', 'model' => 'openai/o1',
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $bot = Bot::factory()->create([
            'primary_chat_model' => 'openai/o1',
            'reasoning_effort' => 'medium',
        ]);

        app(RAGService::class)->generateResponse(bot: $bot, userMessage: 'สวัสดี');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'chat/completions')) {
                return false;
            }
            return ($request->data()['reasoning']['effort'] ?? null) === 'medium';
        });
    }
}
```

- [ ] **Step 7: Run integration test + RAG regression**

Run: `cd backend && php artisan test tests/Feature/RAG/ReasoningEffortWiringTest.php && php artisan test --filter=RAG`
Expected: PASS (ไม่ regress)

- [ ] **Step 8: Commit**

```bash
git add backend/app/Services/RAGService.php backend/tests/Unit/Services/ResolveReasoningEffortTest.php backend/tests/Feature/RAG/ReasoningEffortWiringTest.php
git commit -m "feat(rag): adaptive reasoning_effort (bot เป็นเพดาน, ข้อความง่าย→medium) → LLM effort+timeout+headroom"
```

---

### Task 4: Safeguard — job timeouts (ทุก path) + retry_after + revert A1

**Files:**
- Modify: `backend/app/Jobs/ProcessAggregatedMessages.php` (`$timeout` line 44)
- Modify: `backend/app/Jobs/ProcessLINEWebhook.php`, `ProcessFacebookWebhook.php`, `ProcessTelegramWebhook.php` (เพิ่ม `$timeout`)
- Modify: `backend/config/llm-models.php` (ลบ entry `openai/gpt-5.6-luna`)

**Interfaces:**
- Produces: ทุก job ที่ generate LLM มี `$timeout = 200` รองรับ high (90s primary + 45s fallback + 45s intent ≈ 180 < 200)

> **B1 (blocker):** FB/TG generate LLM ใน job ตรงๆ (ไม่มี aggregation), LINE ก็ตรงๆ เมื่อปิด multi-bubble — แต่ 3 job นี้ **ไม่มี `$timeout`** → high จะเกิน worker timeout → job ถูกฆ่า → retry 3× → ตอบซ้ำ. ต้องตั้ง `$timeout` ทั้งหมด ไม่ใช่แค่ ProcessAggregatedMessages

- [ ] **Step 1: ตั้ง $timeout ให้ job ที่ generate LLM ทั้ง 4 ตัว**

`ProcessAggregatedMessages.php` line 44 เปลี่ยนเป็น:
```php
    public int $timeout = 200;
```
`ProcessLINEWebhook.php`, `ProcessFacebookWebhook.php`, `ProcessTelegramWebhook.php` — เพิ่ม property ในคลาส (ใกล้ `$tries`):
```php
    /**
     * รองรับ reasoning effort=high (primary 90s + fallback 45s + intent 45s ≈ 180s).
     * ต้อง < queue retry_after (ดู deploy gate) กัน re-dispatch ซ้อน.
     */
    public int $timeout = 200;
```

- [ ] **Step 2: Revert A1 (ลบ luna config)**

`config/llm-models.php` — ลบทั้งบล็อก (รวม comment):
```php
        // Override ONLY the reasoning effort. ...
        'openai/gpt-5.6-luna' => [
            'default_reasoning_effort' => 'low',
        ],
```

- [ ] **Step 3: Verify config + jobs load**

Run: `cd backend && php -l config/llm-models.php && for j in ProcessAggregatedMessages ProcessLINEWebhook ProcessFacebookWebhook ProcessTelegramWebhook; do php -l app/Jobs/$j.php; done && php artisan config:clear`
Expected: No syntax errors ทุกไฟล์

- [ ] **Step 4: Commit**

```bash
git add backend/app/Jobs/ProcessAggregatedMessages.php backend/app/Jobs/ProcessLINEWebhook.php backend/app/Jobs/ProcessFacebookWebhook.php backend/app/Jobs/ProcessTelegramWebhook.php backend/config/llm-models.php
git commit -m "fix(queue): ตั้ง job timeout 200s ทุก LLM path รองรับ effort=high + revert luna override"
```

> ⚠️ **Deploy gate (blocker B2 — นอกโค้ด, สำคัญสุด):** ตัวที่คุมจริงคือ **queue `retry_after`** ไม่ใช่ worker `--timeout` (job `$timeout` property override worker --timeout อยู่แล้ว). prod ใช้ Redis → `config/queue.php:51` `REDIS_QUEUE_RETRY_AFTER` **default 90s** → job 200s จะถูก re-dispatch ตอน 90s → **ประมวลผล/ตอบซ้ำ**. **ต้องตั้ง `REDIS_QUEUE_RETRY_AFTER ≥ 210` บน Railway ก่อน/พร้อม deploy** (ค่าเดิม 90 ละเมิดกับ timeout=150 เดิมอยู่แล้ว — แก้ทีเดียว). ยืนยันค่าจริงใน Railway env.

---

### Task 5: Frontend — types + form wiring + submit payload

**Files:**
- Modify: `frontend/src/types/api.ts` (Bot interface ~line 57-70; `CreateConnectionData` ~line 97; `UpdateConnectionData` ~line 112)
- Modify: `frontend/src/hooks/useConnectionForm.ts` (interface ~line 5, defaults ~line 21, load mapping ~line 57)
- Modify: `frontend/src/pages/EditConnectionPage.tsx` (submit payload — update ~line 132, create ~line 153)

**Interfaces:**
- Consumes: BotResource `reasoning_effort` (Task 1)
- Produces: `ConnectionFormData.reasoning_effort: 'low'|'medium'|'high'`; ค่าถูกส่งใน payload บันทึกบอท

- [ ] **Step 1: เพิ่มใน Bot type**

`src/types/api.ts` — ใน `interface Bot` (หลัง `utility_model`):
```ts
  utility_model: string | null;
  reasoning_effort: 'low' | 'medium' | 'high' | null;
```
และใน type ของ payload อัปเดตบอท (บล็อกที่มี `primary_chat_model?: string;` ~line 101 และ ~line 116) เพิ่ม:
```ts
  reasoning_effort?: 'low' | 'medium' | 'high';
```

- [ ] **Step 2: เพิ่มใน ConnectionFormData + defaults + load**

`src/hooks/useConnectionForm.ts`:
- interface (หลัง `utility_model: string;`):
```ts
  utility_model: string;
  reasoning_effort: 'low' | 'medium' | 'high';
```
- `DEFAULT_FORM_DATA` (หลัง `utility_model: '',`):
```ts
  utility_model: '',
  reasoning_effort: 'medium',
```
- load จาก existingBot (หลัง `utility_model: existingBot.utility_model || '',`):
```ts
        utility_model: existingBot.utility_model || '',
        reasoning_effort: existingBot.reasoning_effort || 'medium',
```

- [ ] **Step 3: เพิ่ม reasoning_effort ใน submit payload (2 จุด)**

`frontend/src/pages/EditConnectionPage.tsx` สร้าง payload ทีละ field (ไม่ spread) — ต้องเพิ่มทั้ง update และ create path:

- update (`updateMutation.mutateAsync({...})`, หลัง `utility_model: formData.utility_model,` ~line 132):
```tsx
          utility_model: formData.utility_model,
          reasoning_effort: formData.reasoning_effort,
```
- create (`createData` object, หลัง `utility_model: formData.utility_model,` ~line 153):
```tsx
          utility_model: formData.utility_model,
          reasoning_effort: formData.reasoning_effort,
```
(type ของ payload มาจาก api.ts ที่แก้ใน Step 1 แล้ว จึงผ่าน tsc)

- [ ] **Step 4: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: ไม่มี error ใหม่

- [ ] **Step 5: Commit**

```bash
git add frontend/src/types/api.ts frontend/src/hooks/useConnectionForm.ts frontend/src/pages/EditConnectionPage.tsx
git commit -m "feat(ui): wire reasoning_effort เข้า connection form (types+state+submit)"
```

---

### Task 6: Frontend — ReasoningEffortSelector component + วางใน AIModelsSection

**Files:**
- Create: `frontend/src/components/connections/ReasoningEffortSelector.tsx`
- Modify: `frontend/src/components/connections/sections/AIModelsSection.tsx`

**Interfaces:**
- Consumes: `formData.reasoning_effort`, `handleChange` (Task 5)
- Produces: `<ReasoningEffortSelector value onChange />`

- [ ] **Step 1: สร้าง component (segmented แบบมีรายละเอียด)**

```tsx
// frontend/src/components/connections/ReasoningEffortSelector.tsx
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type Effort = 'low' | 'medium' | 'high';

interface ReasoningEffortSelectorProps {
  value: Effort;
  onChange: (value: Effort) => void;
}

const OPTIONS: { value: Effort; title: string; hint: string }[] = [
  { value: 'low', title: 'Low', hint: 'เร็วสุด · ประหยัด' },
  { value: 'medium', title: 'Medium', hint: 'สมดุล (แนะนำ)' },
  { value: 'high', title: 'High', hint: 'ฉลาดสุด · ช้ากว่า · แพงกว่า' },
];

export function ReasoningEffortSelector({ value, onChange }: ReasoningEffortSelectorProps) {
  return (
    <div className="space-y-2">
      <Label className="text-sm text-muted-foreground">Reasoning Effort</Label>
      <div className="flex flex-col gap-2" role="radiogroup" aria-label="Reasoning Effort">
        {OPTIONS.map((opt) => (
          <button
            key={opt.value}
            type="button"
            role="radio"
            aria-checked={value === opt.value}
            onClick={() => onChange(opt.value)}
            className={cn(
              'flex items-center justify-between rounded-md border px-3 py-2 text-sm text-left transition-colors',
              value === opt.value
                ? 'border-primary bg-primary/5 ring-1 ring-primary'
                : 'border-input hover:bg-muted/50',
            )}
          >
            <span className="font-medium">{opt.title}</span>
            <span className="text-xs text-muted-foreground">{opt.hint}</span>
          </button>
        ))}
      </div>
      <p className="text-xs text-muted-foreground">
        มีผลเฉพาะโมเดลที่รองรับ reasoning (เช่น o1, gpt-5) — โมเดลอื่นระบบข้ามให้อัตโนมัติ
        และข้อความง่ายระบบจะลดระดับให้เองเพื่อความเร็ว
      </p>
    </div>
  );
}
```

- [ ] **Step 2: วางใน AIModelsSection**

`AIModelsSection.tsx` — import + render ใต้ `<ModelConfiguration ... />` (ในกล่อง Panel เดียวกัน):
```tsx
import { ReasoningEffortSelector } from '@/components/connections/ReasoningEffortSelector';
```
และหลัง `</ModelConfiguration>`... (ปิด tag ของ ModelConfiguration) เพิ่ม ภายใน Panel:
```tsx
        <ModelConfiguration
          primaryModel={formData.primary_chat_model}
          fallbackModel={formData.fallback_chat_model}
          utilityModel={formData.utility_model}
          onPrimaryChange={(value) => handleChange('primary_chat_model', value)}
          onFallbackChange={(value) => handleChange('fallback_chat_model', value)}
          onUtilityChange={(value) => handleChange('utility_model', value)}
        />
        <div className="mt-4">
          <ReasoningEffortSelector
            value={formData.reasoning_effort}
            onChange={(value) => handleChange('reasoning_effort', value)}
          />
        </div>
```

- [ ] **Step 3: Styling pass (ui-styling)**

ใช้ skill `ui-ux-pro-max:ui-styling` เพื่อ polish spacing/สี/interaction state ให้เข้ากับ shadcn theme เดิม (ยึด token: `border-primary`, `bg-primary/5`, `muted-foreground`). อย่าเพิ่ม dependency ใหม่.

- [ ] **Step 4: Typecheck + build**

Run: `cd frontend && npx tsc --noEmit && npm run build`
Expected: สำเร็จ ไม่มี error

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/connections/ReasoningEffortSelector.tsx frontend/src/components/connections/sections/AIModelsSection.tsx
git commit -m "feat(ui): ReasoningEffortSelector segmented control ใน AI Models section"
```

---

### Task 7: Frontend test

**Files:**
- Create: `frontend/src/components/connections/ReasoningEffortSelector.test.tsx`

- [ ] **Step 1: Write test**

```tsx
// frontend/src/components/connections/ReasoningEffortSelector.test.tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { ReasoningEffortSelector } from './ReasoningEffortSelector';

describe('ReasoningEffortSelector', () => {
  it('marks the current value as checked', () => {
    render(<ReasoningEffortSelector value="high" onChange={() => {}} />);
    expect(screen.getByRole('radio', { name: /High/ })).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByRole('radio', { name: /Low/ })).toHaveAttribute('aria-checked', 'false');
  });

  it('calls onChange with the clicked effort', () => {
    const onChange = vi.fn();
    render(<ReasoningEffortSelector value="medium" onChange={onChange} />);
    fireEvent.click(screen.getByRole('radio', { name: /High/ }));
    expect(onChange).toHaveBeenCalledWith('high');
  });
});
```

- [ ] **Step 2: Run test**

Run: `cd frontend && npx vitest run src/components/connections/ReasoningEffortSelector.test.tsx`
Expected: PASS (2 tests)

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/connections/ReasoningEffortSelector.test.tsx
git commit -m "test(ui): ReasoningEffortSelector — checked state + onChange"
```

---

## Verification (จบทุก task)

- [ ] `cd backend && php artisan test` — ทั้งชุดผ่าน
- [ ] `cd frontend && npx tsc --noEmit && npx vitest run` — ผ่าน
- [ ] Manual (owner): เปิดหน้า Connection บอทที่ใช้ reasoning model → เลือก High → บันทึก → ส่งข้อความ **ซับซ้อน** (เช่นให้คำนวณ) → ตอบได้ ไม่ error, ตรวจ DB `bots.reasoning_effort = high`; ส่งข้อความง่าย ("สวัสดี") → ตอบเร็ว (adaptive ลดเป็น medium)
- [ ] **Deploy gate (blocker):** ตั้ง `REDIS_QUEUE_RETRY_AFTER ≥ 210` บน Railway (ปัจจุบัน 90) **ก่อน** deploy; `php artisan migrate`

## Global Constraints (ย้ำ)

- effort = low/medium/high เท่านั้น · column default medium (nullable)
- timeout map: **low=45 · medium=45 (no-regress) · high=90** (LINE loading cap 60s)
- **adaptive:** ค่าบอทเป็นเพดาน — ข้อความไม่ complex cap ที่ medium
- non-reasoning model **ต้องไม่ได้** reasoning payload; fallback (B1 recursion) **ไม่รับ** high effort/timeout
- ทุก job ที่ generate LLM มี `$timeout=200`; deploy gate = `retry_after ≥ 210` (ไม่ใช่ worker --timeout)
- copy ไทย
