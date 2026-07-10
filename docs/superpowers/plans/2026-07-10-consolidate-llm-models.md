# Consolidate LLM Models Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collapse the 4-field model config (chat pair + decision pair) into ONE primary+fallback pair used for both intent analysis and answer generation; delete Smart Routing and legacy model columns end-to-end (UI → API → services → DB).

**Architecture:** All model selection already funnels through `Bot::resolvedChatModel()` (added in PR #212). This plan points the intent-analysis (decision) path and vision candidate pools at that same pair, deletes the Smart Routing branch, strips the dead fields from the API surface and frontend form, then drops 8 columns from `bots`.

**Tech Stack:** Laravel 12 (PHPUnit/Pest, Pint), React 19 + TypeScript (Vite), PostgreSQL (Neon).

**Spec:** `docs/superpowers/specs/2026-07-10-consolidate-llm-models-design.md`

## Global Constraints

- Base branch: `fix/form-only-model-fallback` (PR #212) — create work branch `refactor/consolidate-llm-models` from it.
- Models come ONLY from the Connection Settings form: `bots.primary_chat_model` / `bots.fallback_chat_model`. Empty fallback = no fallback. No config substitution (already enforced by PR #212 — do not re-add).
- Columns to drop from `bots` (8): `decision_model`, `fallback_decision_model`, `llm_model`, `llm_fallback_model`, `use_confidence_cascade`, `cascade_confidence_threshold`, `cascade_cheap_model`, `cascade_expensive_model`. Do NOT touch `use_semantic_router` / `semantic_router_*` (separate feature, in use).
- Test the migration on a Neon branch before merging. Production rollback data snapshot is in the spec.
- Run `./vendor/bin/pint --dirty` before every commit (backend), full `php artisan test` before the final commit of each backend task.
- Thai UI copy: the two remaining fields are labeled `LLM Model หลัก` and `โมเดลสำรอง (fallback)`.

---

### Task 1: IntentAnalysisService uses the chat pair

**Files:**
- Modify: `backend/app/Services/IntentAnalysisService.php:45-58` (model selection + skip guard), `:387-413` (delete selectors)
- Test: `backend/tests/Unit/Services/IntentAnalysisServiceTest.php`

**Interfaces:**
- Consumes: `Bot::resolvedChatModel(): ?string` (exists, `backend/app/Models/Bot.php`, returns `primary_chat_model ?: fallback_chat_model`), `$bot->fallback_chat_model` attribute.
- Produces: `analyzeIntent()` keeps its exact return shape (`intent`, `confidence`, `model_used`, `method`, optional `skipped`/`usage`/`error`). Later tasks rely on `skipped => true` meaning "bot has no chat model at all".

- [ ] **Step 1: Update the tests to the new contract (they must FAIL first)**

In `backend/tests/Unit/Services/IntentAnalysisServiceTest.php`:

1. Global replace within this file: `'decision_model' =>` → `'primary_chat_model' =>` (every `Bot::factory()->create([...])`).
2. The no-model test (`test_analyze_intent_returns_default_when_no_decision_model`, ~line 44) becomes:

```php
public function test_analyze_intent_returns_default_when_no_model_configured()
{
    $bot = Bot::factory()->create([
        'user_id' => $this->user->id,
        'primary_chat_model' => null,
        'fallback_chat_model' => null,
        'kb_enabled' => false,
    ]);

    $result = $this->service->analyzeIntent($bot, 'สวัสดีครับ');

    $this->assertSame('chat', $result['intent']);
    $this->assertTrue($result['skipped']);
    $this->assertSame('default', $result['method']);
}
```

3. Rename `test_analyze_intent_calls_llm_when_decision_model_set` (~line 100) → `test_analyze_intent_calls_llm_when_chat_model_set` (body unchanged apart from the factory attribute rename in item 1).
4. Add one new test locking the pair consolidation:

```php
public function test_analyze_intent_uses_primary_chat_model_as_decision_model()
{
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'model' => 'openai/gpt-5.6-luna',
            'choices' => [['message' => ['content' => '{"intent":"chat","confidence":0.9}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], 200),
    ]);

    $bot = Bot::factory()->create([
        'user_id' => $this->user->id,
        'primary_chat_model' => 'openai/gpt-5.6-luna',
        'fallback_chat_model' => 'google/gemini-3.5-flash',
    ]);

    $result = $this->service->analyzeIntent($bot, 'BM ราคาเท่าไหร่');

    $this->assertSame('llm_decision', $result['method']);
    Http::assertSent(function ($request) {
        $body = $request->data();
        $model = $body['model'] ?? ($body['models'][0] ?? null);

        return $model === 'openai/gpt-5.6-luna';
    });
}
```

(Match the existing `Http::fake` style already used in this test file; keep existing `use` imports.)

- [ ] **Step 2: Run the test file — expect failures**

Run: `cd backend && php artisan test --filter=IntentAnalysisServiceTest`
Expected: FAIL — the no-model test fails because the current guard checks `$bot->decision_model`; the new pair test fails because decision model resolution still prefers `decision_model`.

- [ ] **Step 3: Point analyzeIntent at the chat pair**

In `backend/app/Services/IntentAnalysisService.php` replace lines 45-58:

```php
        // Get Decision Model configuration
        $decisionModel = $this->getDecisionModelForBot($bot);
        $fallbackDecisionModel = $this->getFallbackDecisionModelForBot($bot);

        // Skip decision model if not configured (use default behavior)
        if (! $decisionModel && ! $bot->decision_model) {
```

with:

```php
        // Decision uses the same chat pair from Connection Settings (single model pair)
        $decisionModel = $bot->resolvedChatModel();
        $fallbackDecisionModel = $bot->fallback_chat_model;

        // Skip only when the bot has no chat model configured at all
        if (! $decisionModel) {
```

Then delete both methods `getDecisionModelForBot()` and `getFallbackDecisionModelForBot()` (lines ~387-413) including their docblocks.

- [ ] **Step 4: Run the test file — expect pass**

Run: `cd backend && php artisan test --filter=IntentAnalysisServiceTest`
Expected: PASS (all tests).

- [ ] **Step 5: Commit**

```bash
cd /Users/jaochai/Code/bot-fb && ./backend/vendor/bin/pint --dirty
git add backend/app/Services/IntentAnalysisService.php backend/tests/Unit/Services/IntentAnalysisServiceTest.php
git commit -m "refactor(llm): intent analysis ใช้คู่โมเดลหลัก/สำรองเดียวกับงานตอบ"
```

---

### Task 2: Streaming orchestrator decision step uses the chat pair

**Files:**
- Modify: `backend/app/Services/Streaming/StreamingResponseOrchestrator.php:186-202` (skip guard), `:237` (fallback event field)
- Test: `backend/tests/Feature/Api/StreamControllerTest.php:209-224`

**Interfaces:**
- Consumes: `Bot::resolvedChatModel(): ?string`; `IntentAnalysisService::analyzeIntent()` (Task 1 contract: `skipped => true` only when no chat model).
- Produces: SSE event stream unchanged (`decision_skip` / `decision_start` / `decision_result` / `decision_fallback`); `decision_skip` now fires only when the bot has no chat model at all.

- [ ] **Step 1: Update the feature test (it must reflect the new deterministic path)**

In `backend/tests/Feature/Api/StreamControllerTest.php` lines 209-224, the happy-path test currently forces `decision_skip` via `decision_model => null`. Those columns are being deleted. Replace the factory block and comment:

```php
    public function test_happy_path_emits_process_start_and_done_events(): void
    {
        $user = User::factory()->create();
        // Decision step now uses the chat pair: the intent LLM call to 127.0.0.1:1
        // fails fast -> IntentAnalysisService catches -> error_fallback intent (no HTTP left open).
        // No kb_enabled -> kb_skip branch (deterministic).
        // No fallback_chat_model -> chat model attempt fails (127.0.0.1:1), throws.
        // Outer catch emits `error`, finally emits `done`. process_start was already
        // emitted at the top of the stream callback. That's the contract we lock.
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'anthropic/claude-3.5-sonnet',
            'fallback_chat_model' => null,
            'kb_enabled' => false,
        ]);
```

(Only the docblock comment and the removal of the two `decision_*` keys change; assertions below stay as-is.)

- [ ] **Step 2: Run the test — verify current state**

Run: `cd backend && php artisan test --filter=StreamControllerTest`
Expected: PASS already (the assertions are boundary-event based) — this step confirms no accidental breakage before the code change. If it fails, stop and re-read the diff.

- [ ] **Step 3: Point runDecisionModel at the chat pair**

In `backend/app/Services/Streaming/StreamingResponseOrchestrator.php` replace lines 186-191:

```php
    private function runDecisionModel(Bot $bot, string $message, ?string $apiKey, array &$metrics, callable $onSseEvent): array
    {
        $decisionModel = $bot->decision_model;

        // Skip if no decision model configured
        if (empty($decisionModel)) {
```

with:

```php
    private function runDecisionModel(Bot $bot, string $message, ?string $apiKey, array &$metrics, callable $onSseEvent): array
    {
        // Decision uses the same chat pair from Connection Settings (single model pair)
        $decisionModel = $bot->resolvedChatModel();

        // Skip only when the bot has no chat model configured at all
        if (! $decisionModel) {
```

And at line ~237 replace the fallback event field:

```php
                'fallback_model' => $bot->fallback_decision_model,
```

with:

```php
                'fallback_model' => $bot->fallback_chat_model,
```

- [ ] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter="StreamControllerTest|IntentAnalysisServiceTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /Users/jaochai/Code/bot-fb && ./backend/vendor/bin/pint --dirty
git add backend/app/Services/Streaming/StreamingResponseOrchestrator.php backend/tests/Feature/Api/StreamControllerTest.php
git commit -m "refactor(streaming): decision step ใช้คู่โมเดลหลัก/สำรองเดียว (emulator = production)"
```

---

### Task 3: RAGService — delete Smart Routing + dead selectors

**Files:**
- Modify: `backend/app/Services/RAGService.php:183` (caller), `:229-233` (smart_routing metadata), `:435-496` (delete resolveSmartChatModel), `:520-562` (delete dead selectors + deprecated alias)
- Test: `backend/tests/Unit/Services/RAGServiceTest.php:423-467`

**Interfaces:**
- Consumes: `getChatModelForBot(Bot): ?string` (stays — delegates to `Bot::resolvedChatModel()`), `getFallbackChatModelForBot(Bot): ?string` (stays).
- Produces: `generateResponse()` result array WITHOUT the `smart_routing` key (grep confirmed no consumer). `detectComplexity()` and Chain-of-Thought logic stay untouched (still used for max-tokens multiplier and CoT prompt).

- [ ] **Step 1: Update RAGServiceTest factory attributes**

In `backend/tests/Unit/Services/RAGServiceTest.php` lines 437 and 467, replace `'decision_model' => 'some/decider'` with `'primary_chat_model' => 'some/decider'`. Keep the test names `test_greeting_skips_decision_model` / `test_substantive_message_invokes_decision_model` — rename to `test_greeting_skips_intent_analysis` / `test_substantive_message_invokes_intent_analysis` and update any in-test comments mentioning decision_model.

- [ ] **Step 2: Run — confirm tests still pass (contract unchanged: greeting skips, substantive calls LLM)**

Run: `cd backend && php artisan test --filter=RAGServiceTest`
Expected: PASS (greeting-skip is message-based, not model-based; substantive path now resolves the decision model from `primary_chat_model` per Task 1).

- [ ] **Step 3: Delete Smart Routing**

In `backend/app/Services/RAGService.php`:

1. Line 183: replace

```php
        $chatModel = $this->resolveSmartChatModel($bot, $intent, $complexity);
```

with:

```php
        $chatModel = $this->getChatModelForBot($bot);
```

2. Delete the whole `resolveSmartChatModel()` method (docblock starts ~line 435 "Resolve the chat model with Smart Routing", method ends ~line 496).
3. Delete the `smart_routing` metadata block (lines ~229-233):

```php
        $result['smart_routing'] = [
            'enabled' => (bool) $bot->use_confidence_cascade,
            'routed_model' => $chatModel,
            'complexity_source' => isset($intent['complexity']) ? 'enhanced_decision' : 'heuristic',
        ];
```

4. Delete the three dead selector methods with their docblocks: `getDecisionModelForBot()` (~:520-536), `getFallbackDecisionModelForBot()` (~:538-554), and the deprecated `getModelForBot()` alias (~:556-562).

- [ ] **Step 4: Verify no dangling references**

Run: `grep -n "resolveSmartChatModel\|smart_routing\|use_confidence_cascade\|cascade_" backend/app/Services/RAGService.php`
Expected: no output.

Run: `cd backend && php artisan test --filter=RAGServiceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /Users/jaochai/Code/bot-fb && ./backend/vendor/bin/pint --dirty
git add backend/app/Services/RAGService.php backend/tests/Unit/Services/RAGServiceTest.php
git commit -m "refactor(rag): ลบ Smart Routing และ selector ที่ตายแล้ว — เหลือคู่โมเดลเดียว"
```

---

### Task 4: Vision candidate pools → [primary, fallback]

**Files:**
- Modify: `backend/app/Jobs/ProcessLINEWebhook.php` (`getVisionModel`, candidates array ~line 1258), `backend/app/Services/LineWebhook/LineWebhookResponseService.php:338-351`, `backend/app/Services/StickerReplyService.php:143-161`
- Test: `backend/tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php:89-90,345-346`

**Interfaces:**
- Consumes: `$bot->primary_chat_model`, `$bot->fallback_chat_model` attributes; `ModelCapabilityService::supportsVision(string): bool` (unchanged).
- Produces: `getVisionModel()` signatures unchanged; candidate pool is now the 2-element chat pair.

- [ ] **Step 1: Remove decision fields from the vision test factories**

In `backend/tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php` delete the two pairs of lines (at ~:89-90 and ~:345-346):

```php
            'decision_model' => null,
            'fallback_decision_model' => null,
```

- [ ] **Step 2: Shrink the three candidate arrays**

Apply the same edit in all three files (docblock + array):

`backend/app/Jobs/ProcessLINEWebhook.php` (~:1249-1266) and `backend/app/Services/StickerReplyService.php` (~:143-161) — replace:

```php
     * Checks supportsVision() for each model in priority order:
     * 1. primary_chat_model
     * 2. fallback_chat_model
     * 3. decision_model
     * 4. fallback_decision_model
```

with:

```php
     * Checks supportsVision() for each model in priority order:
     * 1. primary_chat_model
     * 2. fallback_chat_model
```

and replace (adjust `$this->bot` / `$bot` / `$ctx->bot` per file):

```php
        $candidates = [
            $this->bot->primary_chat_model,
            $this->bot->fallback_chat_model,
            $this->bot->decision_model,
            $this->bot->fallback_decision_model,
        ];
```

with:

```php
        $candidates = [
            $this->bot->primary_chat_model,
            $this->bot->fallback_chat_model,
        ];
```

`backend/app/Services/LineWebhook/LineWebhookResponseService.php` (~:338-351) — same array edit with `$ctx->bot->…`, and its docblock line

```php
     * Priority: primary_chat_model → fallback_chat_model → decision_model → fallback_decision_model.
```

becomes:

```php
     * Priority: primary_chat_model → fallback_chat_model.
```

- [ ] **Step 3: Verify no `decision_model` reads remain in services/jobs**

Run: `grep -rn "decision_model" backend/app`
Expected: no output.

- [ ] **Step 4: Run related tests**

Run: `cd backend && php artisan test --filter="LineWebhookResponseService|StickerReply|ProcessLINEWebhook"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /Users/jaochai/Code/bot-fb && ./backend/vendor/bin/pint --dirty
git add backend/app/Jobs/ProcessLINEWebhook.php backend/app/Services/LineWebhook/LineWebhookResponseService.php backend/app/Services/StickerReplyService.php backend/tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php
git commit -m "refactor(vision): เลือกโมเดลจากคู่หลัก/สำรองเท่านั้น"
```

---

### Task 5: Strip the API surface (model, resource, requests, OpenApi)

**Files:**
- Modify: `backend/app/Models/Bot.php:33-34,50-53,79-80`, `backend/app/Http/Resources/BotResource.php:37-43`, `backend/app/Http/Requests/Bot/StoreBotRequest.php:28-29`, `backend/app/Http/Requests/Bot/UpdateBotRequest.php:30-36`, `backend/app/OpenApi/OpenApi.php:93,119`

**Interfaces:**
- Consumes: nothing new.
- Produces: `BotResource` JSON no longer contains `decision_model`, `fallback_decision_model`, `use_confidence_cascade`, `cascade_cheap_model`, `cascade_expensive_model`. Store/Update requests reject nothing — the keys are simply no longer validated/fillable, so they are ignored if sent. Frontend (Task 7) stops sending them.

- [ ] **Step 1: Bot model**

In `backend/app/Models/Bot.php` `$fillable`, delete these entries (keep `use_semantic_router`, `semantic_router_threshold`, `semantic_router_fallback`):

```php
        'decision_model',
        'fallback_decision_model',
```

```php
        // Confidence Cascade settings
        'use_confidence_cascade',
        'cascade_confidence_threshold',
        'cascade_cheap_model',
        'cascade_expensive_model',
```

In `$casts`, delete:

```php
        // Confidence Cascade settings
        'use_confidence_cascade' => 'boolean',
        'cascade_confidence_threshold' => 'float',
```

- [ ] **Step 2: BotResource**

In `backend/app/Http/Resources/BotResource.php` delete lines 37-43:

```php
            'decision_model' => $this->decision_model,
            'fallback_decision_model' => $this->fallback_decision_model,

            // Smart Routing (Confidence Cascade)
            'use_confidence_cascade' => $this->use_confidence_cascade ?? false,
            'cascade_cheap_model' => $this->cascade_cheap_model,
            'cascade_expensive_model' => $this->cascade_expensive_model,
```

- [ ] **Step 3: Form requests**

`backend/app/Http/Requests/Bot/StoreBotRequest.php` — delete lines 28-29:

```php
            'decision_model' => ['nullable', 'string', 'max:100'],
            'fallback_decision_model' => ['nullable', 'string', 'max:100'],
```

`backend/app/Http/Requests/Bot/UpdateBotRequest.php` — delete lines 30-36:

```php
            'decision_model' => ['nullable', 'string', 'max:100'],
            'fallback_decision_model' => ['nullable', 'string', 'max:100'],

            // Smart Routing (Confidence Cascade)
            'use_confidence_cascade' => ['sometimes', 'boolean'],
            'cascade_cheap_model' => ['nullable', 'string', 'max:100'],
            'cascade_expensive_model' => ['nullable', 'string', 'max:100'],
```

(If StoreBotRequest also has the Smart Routing block, delete it there too — verify with grep in Step 5.)

- [ ] **Step 4: OpenApi annotations**

In `backend/app/OpenApi/OpenApi.php` delete every `@OA\Property` line whose property is `decision_model` or `fallback_decision_model` (currently lines 93 and 119; grep to catch all):

Run: `grep -n "decision_model\|fallback_decision\|cascade\|use_confidence" backend/app/OpenApi/OpenApi.php`
Delete each reported annotation line.

- [ ] **Step 5: Verify + run bot API tests**

Run: `grep -rn "decision_model\|fallback_decision\|cascade_cheap\|cascade_expensive\|use_confidence_cascade\|cascade_confidence_threshold" backend/app`
Expected: no output.

Run: `cd backend && php artisan test --filter="BotController|BotResource|Bot"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd /Users/jaochai/Code/bot-fb && ./backend/vendor/bin/pint --dirty
git add backend/app/Models/Bot.php backend/app/Http/Resources/BotResource.php backend/app/Http/Requests/Bot/StoreBotRequest.php backend/app/Http/Requests/Bot/UpdateBotRequest.php backend/app/OpenApi/OpenApi.php
git commit -m "refactor(api): ตัด decision/cascade fields ออกจาก Bot API surface"
```

---

### Task 6: Migration — drop 8 columns (test on Neon branch first)

**Files:**
- Create: `backend/database/migrations/2026_07_10_120000_drop_legacy_model_columns_from_bots_table.php`

**Interfaces:**
- Consumes: nothing — all code reads were removed in Tasks 1-5.
- Produces: `bots` table without the 8 legacy columns. `down()` restores schema (not data — data snapshot lives in the spec).

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ยุบเหลือคู่โมเดลเดียว (primary_chat_model + fallback_chat_model):
     * ลบคอลัมน์ decision pair, Smart Routing cascade และ legacy llm_model/llm_fallback_model
     * Snapshot ค่าเดิมของบอท 26/28 อยู่ใน docs/superpowers/specs/2026-07-10-consolidate-llm-models-design.md
     */
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn([
                'decision_model',
                'fallback_decision_model',
                'llm_model',
                'llm_fallback_model',
                'use_confidence_cascade',
                'cascade_confidence_threshold',
                'cascade_cheap_model',
                'cascade_expensive_model',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // Definitions mirror the original migrations (2025_12_24, 2025_12_27, 2025_12_31)
            $table->string('llm_model', 100)->default('anthropic/claude-3.5-sonnet')->after('default_flow_id');
            $table->string('llm_fallback_model', 100)->default('openai/gpt-4o-mini')->after('llm_model');
            $table->string('decision_model')->nullable()->after('fallback_chat_model');
            $table->string('fallback_decision_model')->nullable()->after('decision_model');
            $table->boolean('use_confidence_cascade')->default(false)->after('semantic_router_fallback');
            $table->float('cascade_confidence_threshold')->default(0.7)->after('use_confidence_cascade');
            $table->string('cascade_cheap_model')->nullable()->after('cascade_confidence_threshold');
            $table->string('cascade_expensive_model')->nullable()->after('cascade_cheap_model');
        });
    }
};
```

- [ ] **Step 2: Test up+down locally (SQLite/test DB)**

Run: `cd backend && php artisan migrate && php artisan migrate:rollback --step=1 && php artisan migrate`
Expected: all three commands succeed.

- [ ] **Step 3: Test on a Neon branch (production schema copy)**

Using the Neon MCP tools in the main session (project `solitary-math-34010034`, org iMaew):
1. `create_branch` (temporary, from default branch).
2. `run_sql` on the temp branch: paste the equivalent DDL to confirm no dependency errors:
   `ALTER TABLE bots DROP COLUMN decision_model, DROP COLUMN fallback_decision_model, DROP COLUMN llm_model, DROP COLUMN llm_fallback_model, DROP COLUMN use_confidence_cascade, DROP COLUMN cascade_confidence_threshold, DROP COLUMN cascade_cheap_model, DROP COLUMN cascade_expensive_model;`
3. `run_sql`: `SELECT primary_chat_model, fallback_chat_model FROM bots ORDER BY id;` → expect bot 26/28 rows intact.
4. `delete_branch` (cleanup).
Expected: DDL runs clean; remaining columns intact.

- [ ] **Step 4: Full backend suite**

Run: `cd backend && php artisan test`
Expected: PASS (0 failures; ~771+ tests).

- [ ] **Step 5: Commit**

```bash
cd /Users/jaochai/Code/bot-fb && ./backend/vendor/bin/pint --dirty
git add backend/database/migrations/2026_07_10_120000_drop_legacy_model_columns_from_bots_table.php
git commit -m "refactor(db): drop 8 legacy model columns จากตาราง bots"
```

---

### Task 7: Frontend — 2 fields only

**Files:**
- Modify: `frontend/src/components/ModelSelector.tsx:25-82`, `frontend/src/components/connections/sections/AIModelsSection.tsx:32-45`, `frontend/src/hooks/useConnectionForm.ts:11-12,18-20,29-30,36-38,65-66,72-74`, `frontend/src/pages/EditConnectionPage.tsx:132-133,136-138,156-157,160-162`, `frontend/src/types/api.ts:69-72,79-81,110-111,117-119,129-130,136-138`

**Interfaces:**
- Consumes: backend API that no longer returns/accepts the removed keys (Task 5).
- Produces: `ModelConfiguration` component with exactly these props: `{ primaryModel: string; fallbackModel: string; onPrimaryChange: (value: string) => void; onFallbackChange: (value: string) => void; }`.

- [ ] **Step 1: ModelConfiguration → 2 fields**

Replace the `ModelConfiguration` section of `frontend/src/components/ModelSelector.tsx` (lines 25-82) with:

```tsx
// Convenience component for the single primary+fallback model pair
interface ModelConfigurationProps {
  primaryModel: string;
  fallbackModel: string;
  onPrimaryChange: (value: string) => void;
  onFallbackChange: (value: string) => void;
}

export function ModelConfiguration({
  primaryModel,
  fallbackModel,
  onPrimaryChange,
  onFallbackChange,
}: ModelConfigurationProps) {
  return (
    <div className="grid grid-cols-2 gap-4">
      <ModelSelector
        label="LLM Model หลัก"
        value={primaryModel}
        onChange={onPrimaryChange}
      />
      <ModelSelector
        label="โมเดลสำรอง (fallback)"
        value={fallbackModel}
        onChange={onFallbackChange}
      />
    </div>
  );
}
```

- [ ] **Step 2: AIModelsSection**

In `frontend/src/components/connections/sections/AIModelsSection.tsx` replace the `<ModelConfiguration …/>` usage (lines 35-45) with:

```tsx
        <ModelConfiguration
          primaryModel={formData.primary_chat_model}
          fallbackModel={formData.fallback_chat_model}
          onPrimaryChange={(value) => handleChange('primary_chat_model', value)}
          onFallbackChange={(value) => handleChange('fallback_chat_model', value)}
        />
```

Also update the Panel description (line 33) from `เลือก model สำหรับตอบคำถามและตัดสินใจ` to `โมเดลเดียวใช้ทั้งตอบคำถามและตัดสินใจ (หลัก + สำรอง)`.

- [ ] **Step 3: Form hook, payloads, types**

`frontend/src/hooks/useConnectionForm.ts` — delete these lines:
- interface fields: `decision_model: string;`, `fallback_decision_model: string;`, `use_confidence_cascade: boolean;`, `cascade_cheap_model: string;`, `cascade_expensive_model: string;`
- DEFAULT_FORM_DATA entries: `decision_model: 'openai/gpt-4o-mini',`, `fallback_decision_model: 'openai/gpt-4o',`, `use_confidence_cascade: false,`, `cascade_cheap_model: 'openai/gpt-4o-mini',`, `cascade_expensive_model: 'openai/gpt-5-mini',`
- load-back lines 65-66, 72-74 (the `decision_model`, `fallback_decision_model`, `use_confidence_cascade`, `cascade_cheap_model`, `cascade_expensive_model` assignments).

`frontend/src/pages/EditConnectionPage.tsx` — delete the same 5 keys from BOTH payload objects (update ~:132-138, create ~:156-162).

`frontend/src/types/api.ts` — delete from the `Bot` interface: `decision_model`, `fallback_decision_model`, `llm_model`, `use_confidence_cascade`, `cascade_cheap_model`, `cascade_expensive_model` (lines 69-72, 79-81); delete the same optional keys from the two request interfaces (lines 110-111, 117-119, 129-130, 136-138).

- [ ] **Step 4: Verify zero references + build**

Run: `grep -rn "decision_model\|fallback_decision\|cascade_cheap\|cascade_expensive\|use_confidence_cascade\|llm_model" frontend/src`
Expected: no output.

Run: `cd frontend && npm run build`
Expected: build succeeds with no TypeScript errors.

- [ ] **Step 5: Commit**

```bash
git add frontend/src
git commit -m "refactor(ui): หน้า AI Models เหลือ 2 ช่อง — โมเดลหลัก + สำรอง"
```

---

### Task 8: Final verification + PR

**Files:**
- Modify: `docs/superpowers/specs/2026-07-10-consolidate-llm-models-design.md` (column count 6→8: add `cascade_confidence_threshold`, `llm_model` — both discovered during planning, zero code readers)

**Interfaces:** none.

- [ ] **Step 1: Repo-wide reference sweep**

Run: `grep -rn "decision_model\|fallback_decision\|cascade_cheap\|cascade_expensive\|use_confidence_cascade\|cascade_confidence_threshold\|llm_fallback_model" backend/app backend/tests frontend/src`
Expected: no output. (Migration files under `backend/database/migrations` MAY still mention them — that's correct, do not edit old migrations.)

- [ ] **Step 2: Full suites**

Run: `cd backend && ./vendor/bin/pint --dirty && php artisan test`
Expected: Pint pass; 0 test failures.

Run: `cd frontend && npm run build`
Expected: success.

- [ ] **Step 3: Update spec column list + commit**

In the spec's "Database migration" section change "Drop 6 columns" to "Drop 8 columns" and add `llm_model`, `cascade_confidence_threshold` to the list with note "(discovered during planning — no code readers)".

```bash
git add docs/superpowers/specs/2026-07-10-consolidate-llm-models-design.md
git commit -m "docs(spec): ปรับจำนวนคอลัมน์ที่ลบเป็น 8 (พบเพิ่มตอนวางแผน)"
```

- [ ] **Step 4: Push + PR**

```bash
git push -u origin refactor/consolidate-llm-models
gh pr create --base main --title "refactor(llm): ยุบเหลือโมเดลหลัก + สำรอง คู่เดียวทั้งระบบ" --body "(สรุปจาก spec: ยุบ 4 ช่องเหลือ 2, ลบ Smart Routing + 8 คอลัมน์ legacy, intent ใช้คู่เดียวกับงานตอบ — ดู docs/superpowers/specs/2026-07-10-consolidate-llm-models-design.md)

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```

Note: PR base is `main`, but the branch contains PR #212's commits — merge PR #212 first, then this PR shows only the consolidation diff. Deploy order: merge #212 → deploy → merge this → deploy (migration runs on Railway release).
