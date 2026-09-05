# Track 3 — RAGService Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the 1,099-line `App\Services\RAGService` into an orchestrator plus three collaborators (`RAGKnowledgeBase`, `RAGPromptBuilder`, `RAGIntentDetector`) under `app/Services/RAG/`, moving method bodies verbatim, with zero caller changes and the full test suite green after every task.

**Architecture:** `RAGService` keeps its constructor signature (9 positional args — `AppServiceProvider` and two tests construct it positionally) and builds the three collaborators itself in the constructor body from the deps it already receives. Every method that leaves `RAGService` becomes a `public` method on its new class; `RAGService` keeps thin delegating wrappers for the methods that callers or tests reach (public ones called from outside, plus the protected ones tests invoke via reflection). No `app()` inside the new classes. One PR, one branch, four code tasks + one verification task.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12 (`php artisan test`), Mockery/`createMock`, sqlite test DB, Laravel Pint.

**Spec:** `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §7 (Track 3)

## Global Constraints

- **Verbatim-move rule (spec §8):** method bodies are cut from `RAGService.php` and pasted unchanged into the new class. The only permitted edits inside a moved body are (a) `$this->someDependency` → the same name as a promoted constructor property on the new class, (b) `$this->otherMovedMethod(` when that method moved to the *same* new class (unchanged), or → `$this->promptBuilder->` / `$this->knowledgeBase->` / `$this->intentDetector->` when it moved to a different class. Docblocks travel with their method. Do not reformat, rename, reorder parameters, or "tidy".
- **Constructor injection only** in the three new classes. No `app()`, no facades other than `Log`/`config()` that the moved bodies already use.
- **Constructor signature of `RAGService` does not change** (positional construction in `app/Providers/AppServiceProvider.php:62-74`, `tests/Unit/Services/RAGServiceTest.php:45` and `:415`).
- **Property `RAGService::$semanticCache` must stay** (read via `ReflectionProperty` in `app/Services/PromptEval/PromptEvalRunner.php:118` and `tests/Unit/PromptEvalRunnerTest.php:38`).
- **Wrappers that must remain on `RAGService`:** public `isSimpleMessage`, `detectComplexity`, `detectToolIntent`, `formatKnowledgeBaseContext`, `injectStockStatus`, `getFlowKnowledgeBaseContext`, `flowHasKnowledgeBases`; protected `buildEnhancedPrompt`, `buildPurchaseHistoryBlock` (reflected in `RAGServiceTest`), `resolveReasoningEffort` (reflected in `ResolveReasoningEffortTest`; it does not move), `getApiKeyForBot`.
- **Each new file ≤ 350 LOC** (`wc -l`).
- Every task ends with `php artisan test --parallel --compact` green (baseline on `main`: 1157 passed / 17 skipped) and `vendor/bin/pint --test` clean. Run from `backend/`.
- `php artisan prompt:eval` cannot run locally (no `OPENROUTER_API_KEY` in `.env`). The owner runs it on the Railway shell before and after merge; the PR description carries a checkbox for both numbers. Workers must not attempt it.
- Branch `refactor/rag-split` from `main`. Workers never commit; the controller commits after each task's audit.
- Commit message footer (required, controller adds it):
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH
  ```

## Verified facts this plan relies on (2026-09-05, `main` = 2efc4c9)

- `backend/app/Services/RAGService.php` is 1,099 lines, one class, one constant `SIMPLE_MESSAGE_PATTERN` (line 28, used only by `isSimpleMessage` line 291). Constructor (lines 30-40) promotes nine `protected` properties: `semanticSearchService`, `hybridSearchService`, `openRouter`, `intentAnalysis`, `flowCacheService`, `?queryEnhancement`, `?semanticCache`, `?cragService`, `stockInjectionService`.
- Method → dependency map (only `$this->` uses):
  - `generateResponse` (56-286): `openRouter`, `intentAnalysis`, `flowCacheService`, `semanticCache`, and calls almost every helper.
  - `isSimpleMessage` (288-292): constant only. `detectComplexity` (808-890), `detectToolIntent` (899-964), `detectLanguage` (1009-1022): no deps, `config()` only.
  - `shouldUseKnowledgeBase` (300-309): `flowCacheService`. `getKnowledgeBaseContext` (316-328): `flowCacheService`, `getFlowKnowledgeBaseContext`. `formatKnowledgeBaseContext` (334-347) → `formatThaiContext` (352-368) / `formatEnglishContext` (373-389). `getFlowKnowledgeBaseContext` (629-698): `hybridSearchService`, `getApiKeyForBot`, `applyCRAG`, `formatKnowledgeBaseContext`. `flowHasKnowledgeBases` (701-704). `applyCRAG` (1033-1098): `cragService`, `hybridSearchService`, `Collection` type.
  - `buildEnhancedPrompt` (394-455): `stockInjectionService`. `buildPurchaseHistoryBlock` (461-510): `Order` model, `formatThaiDate` (515-524). `injectStockStatus` (530-534): `stockInjectionService`. `buildChainOfThoughtInstruction` (974-1000), `getSystemPromptForBot` (589-611): `Flow` model + `Log`, `getDefaultSystemPrompt` (614-623).
  - `getChatModelForBot` (543-546), `getFallbackChatModelForBot` (552-555), `resolveReasoningEffort` (560-568), `getApiKeyForBot` (577-581), `testRAG` (709-740: `hybridSearchService`, `queryEnhancement`, `shouldUseKnowledgeBase`, `getKnowledgeBaseContext`), `shouldSkipCache` (745-800: `stockInjectionService`) stay on `RAGService`.
- `getApiKeyForBot` is needed by both `generateResponse` (stays) and `getFlowKnowledgeBaseContext` (moves). Resolution: the body moves to `RAGKnowledgeBase::getApiKeyForBot` (public) and `RAGService::getApiKeyForBot` becomes a protected wrapper delegating to it. Spec §7 lists it under the orchestrator; the wrapper preserves that surface.
- External callers of `RAGService` (grep `app/`): `AIService` (`generateResponse`), `StreamingResponseOrchestrator` (`formatKnowledgeBaseContext`, `injectStockStatus`), `FlowController` (`injectStockStatus`), `PromptEvalRunner` (`generateResponse` + `semanticCache` reflection). None of them changes in this PR.
- `tests/Unit/Services/RAGServiceTest.php` reflects `shouldSkipCache`, `buildEnhancedPrompt`, `buildPurchaseHistoryBlock` on the `RAGService` instance and constructs it with `createMock` deps + real `StockInjectionService`. It must pass unchanged.
- `config('rag.context_template')` defaults to `'thai'` (`config/rag.php:67`).

## File Structure

| File | Responsibility |
|---|---|
| `backend/app/Services/RAG/RAGIntentDetector.php` (new, Task 1) | Pure message classifiers: `isSimpleMessage`, `detectComplexity`, `detectToolIntent`, `detectLanguage`. No constructor deps. |
| `backend/app/Services/RAG/RAGPromptBuilder.php` (new, Task 2) | System-prompt assembly: `buildEnhancedPrompt`, `buildPurchaseHistoryBlock`, `formatThaiDate`, `injectStockStatus`, `buildChainOfThoughtInstruction`, `getSystemPromptForBot`, `getDefaultSystemPrompt`. Dep: `StockInjectionService`. |
| `backend/app/Services/RAG/RAGKnowledgeBase.php` (new, Task 3) | KB retrieval + formatting + CRAG: `shouldUseKnowledgeBase`, `getKnowledgeBaseContext`, `getFlowKnowledgeBaseContext`, `flowHasKnowledgeBases`, `formatKnowledgeBaseContext`, `formatThaiContext`, `formatEnglishContext`, `applyCRAG`, `getApiKeyForBot`. Deps: `HybridSearchService`, `FlowCacheService`, `?CRAGService`. |
| `backend/app/Services/RAGService.php` (modified, Tasks 1-4) | Orchestrator: `generateResponse`, `testRAG`, `shouldSkipCache`, model/effort/api-key helpers, delegating wrappers. Builds the three collaborators in its constructor. |
| `backend/tests/Unit/Services/RAG/RAGIntentDetectorTest.php`, `RAGPromptBuilderTest.php`, `RAGKnowledgeBaseTest.php` (new) | Direct unit tests of each collaborator (small, behavior-pinning). |

---

### Task 1: RAGIntentDetector

**Files:**
- Create: `backend/app/Services/RAG/RAGIntentDetector.php`
- Create: `backend/tests/Unit/Services/RAG/RAGIntentDetectorTest.php`
- Modify: `backend/app/Services/RAGService.php` (lines 28, 30-40, 288-292, 808-890, 899-964, 1009-1022, and the four call sites inside `generateResponse`)

**Interfaces:**
- Produces: `App\Services\RAG\RAGIntentDetector` with `public function isSimpleMessage(string $userMessage): bool`, `public function detectComplexity(string $userMessage): array` (`['is_complex' => bool, 'score' => int, 'reasons' => string[]]`), `public function detectToolIntent(string $userMessage, array $enabledTools = []): array` (`['needs_tool' => bool, 'tool_hint' => ?string, 'reasons' => string[]]`), `public function detectLanguage(string $message): string` (`'thai'|'english'`).
- `RAGService` gains `private RAGIntentDetector $intentDetector` (built in ctor) and keeps public wrappers `isSimpleMessage`, `detectComplexity`, `detectToolIntent`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Unit/Services/RAG/RAGIntentDetectorTest.php`:

```php
<?php

namespace Tests\Unit\Services\RAG;

use App\Services\RAG\RAGIntentDetector;
use Tests\TestCase;

class RAGIntentDetectorTest extends TestCase
{
    private RAGIntentDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new RAGIntentDetector;
    }

    public function test_simple_message_matches_greetings_only(): void
    {
        $this->assertTrue($this->detector->isSimpleMessage('สวัสดี'));
        $this->assertTrue($this->detector->isSimpleMessage(' hello '));
        $this->assertFalse($this->detector->isSimpleMessage('ขอราคาสินค้า Nolimit Level Up ทุกแพ็กเกจ'));
    }

    public function test_detect_complexity_short_circuits_on_greeting(): void
    {
        $this->assertSame(
            ['is_complex' => false, 'score' => 0, 'reasons' => ['greeting_detected']],
            $this->detector->detectComplexity('สวัสดีครับ')
        );
    }

    public function test_detect_complexity_flags_multiple_questions(): void
    {
        $result = $this->detector->detectComplexity('ราคาเท่าไหร่? ส่งกี่วัน?');

        $this->assertContains('multiple_questions', $result['reasons']);
        $this->assertTrue($result['is_complex']);
    }

    public function test_detect_tool_intent_only_for_enabled_tools(): void
    {
        $this->assertFalse($this->detector->detectToolIntent('คำนวณราคา 3 ชิ้น')['needs_tool']);

        $result = $this->detector->detectToolIntent('คำนวณราคา 3 ชิ้น', ['calculate']);
        $this->assertTrue($result['needs_tool']);
        $this->assertSame('calculate', $result['tool_hint']);
    }

    public function test_detect_language(): void
    {
        $this->assertSame('thai', $this->detector->detectLanguage('สวัสดีครับ ราคาเท่าไหร่'));
        $this->assertSame('english', $this->detector->detectLanguage('how much is it'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/Services/RAG/RAGIntentDetectorTest.php --compact`
Expected: FAIL — `Class "App\Services\RAG\RAGIntentDetector" not found`

- [ ] **Step 3: Create the class by moving the four methods**

Create `backend/app/Services/RAG/RAGIntentDetector.php`:

```php
<?php

namespace App\Services\RAG;

/**
 * Message classifiers split out of RAGService (Track 3). Bodies are moved
 * verbatim; no dependencies.
 */
class RAGIntentDetector
{
    private const SIMPLE_MESSAGE_PATTERN = '/^(สวัสดี|หวัดดี|ดี(ครับ|ค่ะ)?|ขอบคุณ|ขอบใจ|บาย|ลาก่อน|ok|oke|โอเค|hi|hello|hey|thanks|thank you|bye|good\s?(morning|evening|night))$/iu';

    // ── paste, in this order, exactly as they appear in RAGService.php (docblock included): ──
    // isSimpleMessage            (RAGService.php lines 284-292)  — keep `public`
    // detectComplexity           (lines 802-890)                — keep `public`
    // detectToolIntent           (lines 892-964)                — keep `public`
    // detectLanguage             (lines 1001-1022)              — change `protected` → `public`
}
```

Cut those four methods (with their docblocks) and the `SIMPLE_MESSAGE_PATTERN` constant out of `RAGService.php`. The only edit inside a moved body: none are needed (they reference `self::SIMPLE_MESSAGE_PATTERN` and `config()` only).

- [ ] **Step 4: Wire the detector into RAGService**

In `backend/app/Services/RAGService.php`:

1. Add `use App\Services\RAG\RAGIntentDetector;` to the imports (alphabetical: after `use App\Models\Order;` block, before `use Illuminate\...`).
2. Give the constructor a body and a property:

```php
    private RAGIntentDetector $intentDetector;

    public function __construct(
        protected SemanticSearchService $semanticSearchService,
        protected HybridSearchService $hybridSearchService,
        protected OpenRouterService $openRouter,
        protected IntentAnalysisService $intentAnalysis,
        protected FlowCacheService $flowCacheService,
        protected ?QueryEnhancementService $queryEnhancement = null,
        protected ?SemanticCacheService $semanticCache = null,
        protected ?CRAGService $cragService = null,
        protected StockInjectionService $stockInjectionService = new StockInjectionService
    ) {
        $this->intentDetector = new RAGIntentDetector;
    }
```

3. Add delegating wrappers where the moved methods used to be (keep the original public docblock's first line as a one-liner):

```php
    /** @see RAGIntentDetector::isSimpleMessage() */
    public function isSimpleMessage(string $userMessage): bool
    {
        return $this->intentDetector->isSimpleMessage($userMessage);
    }

    /** @see RAGIntentDetector::detectComplexity() */
    public function detectComplexity(string $userMessage): array
    {
        return $this->intentDetector->detectComplexity($userMessage);
    }

    /** @see RAGIntentDetector::detectToolIntent() */
    public function detectToolIntent(string $userMessage, array $enabledTools = []): array
    {
        return $this->intentDetector->detectToolIntent($userMessage, $enabledTools);
    }
```

4. Inside `generateResponse`, replace the single call `$this->detectLanguage(` with `$this->intentDetector->detectLanguage(` (no wrapper: protected, no external/test caller). Leave `$this->isSimpleMessage(`, `$this->detectComplexity(` calls in `generateResponse` as they are (they hit the wrappers).

- [ ] **Step 5: Run the new test and the RAG-related suites**

Run: `cd backend && php artisan test tests/Unit/Services/RAG tests/Unit/Services/RAGServiceTest.php tests/Unit/Services/ResolveReasoningEffortTest.php tests/Feature/RAG --compact`
Expected: all PASS.

- [ ] **Step 6: Full suite + Pint**

Run: `cd backend && php artisan test --parallel --compact && vendor/bin/pint --test`
Expected: 0 failed; Pint `passed`. If Pint flags a file you touched, run `vendor/bin/pint <file>` and re-run `--test`.

- [ ] **Step 7: Report** (controller commits): files changed, test summary line, `wc -l app/Services/RAG/RAGIntentDetector.php app/Services/RAGService.php`.

---

### Task 2: RAGPromptBuilder

**Files:**
- Create: `backend/app/Services/RAG/RAGPromptBuilder.php`
- Create: `backend/tests/Unit/Services/RAG/RAGPromptBuilderTest.php`
- Modify: `backend/app/Services/RAGService.php` (methods at original lines 394-455, 461-510, 515-524, 530-534, 589-611, 614-623, 974-1000 and their call sites in `generateResponse`)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `App\Services\RAG\RAGPromptBuilder` with `__construct(StockInjectionService $stockInjectionService)` and public methods `buildEnhancedPrompt(string $basePrompt, string $kbContext, ?Bot $bot = null, array $memoryNotes = [], string $purchaseHistoryBlock = ''): string` (copy the exact signature from RAGService line 394), `buildPurchaseHistoryBlock(?Conversation $conversation): string`, `injectStockStatus(string $prompt): string`, `buildChainOfThoughtInstruction(string $language = 'thai'): string`, `getSystemPromptForBot(Bot $bot): string`, `getDefaultSystemPrompt(Bot $bot): string`; `formatThaiDate` stays `private`.
- `RAGService` gains `private RAGPromptBuilder $promptBuilder`; wrappers: public `injectStockStatus`, protected `buildEnhancedPrompt`, protected `buildPurchaseHistoryBlock`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Unit/Services/RAG/RAGPromptBuilderTest.php`:

```php
<?php

namespace Tests\Unit\Services\RAG;

use App\Models\Bot;
use App\Models\User;
use App\Services\RAG\RAGPromptBuilder;
use App\Services\StockInjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RAGPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    private RAGPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new RAGPromptBuilder(app(StockInjectionService::class));
    }

    public function test_system_prompt_prefers_bot_prompt_then_default(): void
    {
        $user = User::factory()->create();
        $withPrompt = Bot::factory()->create(['user_id' => $user->id, 'system_prompt' => 'You are Bot A.']);
        $withoutPrompt = Bot::factory()->create(['user_id' => $user->id, 'system_prompt' => null, 'default_flow_id' => null, 'name' => 'Shop B']);

        $this->assertSame('You are Bot A.', $this->builder->getSystemPromptForBot($withPrompt));
        $this->assertStringContainsString('helpful AI assistant for Shop B', $this->builder->getSystemPromptForBot($withoutPrompt));
    }

    public function test_chain_of_thought_instruction_is_localised(): void
    {
        $this->assertStringContainsString('คำถามซับซ้อน', $this->builder->buildChainOfThoughtInstruction('thai'));
        $this->assertStringContainsString('Complex Questions', $this->builder->buildChainOfThoughtInstruction('english'));
    }

    public function test_enhanced_prompt_places_base_before_kb_context(): void
    {
        $prompt = $this->builder->buildEnhancedPrompt('BASE PERSONA', 'KB CONTEXT');

        $this->assertLessThan(strpos($prompt, 'KB CONTEXT'), strpos($prompt, 'BASE PERSONA'));
    }

    public function test_purchase_history_block_is_empty_without_conversation(): void
    {
        $this->assertSame('', $this->builder->buildPurchaseHistoryBlock(null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/Services/RAG/RAGPromptBuilderTest.php --compact`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the class by moving the seven methods**

Create `backend/app/Services/RAG/RAGPromptBuilder.php`:

```php
<?php

namespace App\Services\RAG;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Order;
use App\Services\StockInjectionService;
use Illuminate\Support\Facades\Log;

/**
 * System-prompt assembly split out of RAGService (Track 3). Bodies are moved
 * verbatim.
 */
class RAGPromptBuilder
{
    public function __construct(
        private readonly StockInjectionService $stockInjectionService,
    ) {}

    // ── paste, in this order, exactly as they appear in RAGService.php (docblocks included): ──
    // buildEnhancedPrompt            (original lines ~390-455)  — `protected` → `public`
    // buildPurchaseHistoryBlock      (~457-510)                 — `protected` → `public`
    // formatThaiDate                 (~512-524)                 — stays `private`
    // injectStockStatus              (~526-534)                 — keep `public`
    // getSystemPromptForBot          (~583-611)                 — `protected` → `public`
    // getDefaultSystemPrompt         (~614-623)                 — `protected` → `public`
    // buildChainOfThoughtInstruction (~966-1000)                — `protected` → `public`
}
```

Inside the moved bodies the only references are `$this->stockInjectionService` (same name — unchanged), `$this->formatThaiDate(`, `$this->getDefaultSystemPrompt(` (same class — unchanged), `Order::query()`, `Flow::find`, `Log::debug`. Nothing else to edit. Remove `use App\Models\Order;` from `RAGService.php` if it is now unused there (check with grep; `Flow` and `Conversation` are still used by `generateResponse`'s signature).

- [ ] **Step 4: Wire the builder into RAGService**

1. Import `use App\Services\RAG\RAGPromptBuilder;`.
2. Add property `private RAGPromptBuilder $promptBuilder;` and in the constructor body, after the intent detector line: `$this->promptBuilder = new RAGPromptBuilder($this->stockInjectionService);`
3. Wrappers (put them where the moved methods were):

```php
    /** @see RAGPromptBuilder::buildEnhancedPrompt() */
    protected function buildEnhancedPrompt(string $basePrompt, string $kbContext, ?Bot $bot = null, array $memoryNotes = [], string $purchaseHistoryBlock = ''): string
    {
        return $this->promptBuilder->buildEnhancedPrompt($basePrompt, $kbContext, $bot, $memoryNotes, $purchaseHistoryBlock);
    }

    /** @see RAGPromptBuilder::buildPurchaseHistoryBlock() */
    protected function buildPurchaseHistoryBlock(?Conversation $conversation): string
    {
        return $this->promptBuilder->buildPurchaseHistoryBlock($conversation);
    }

    /** @see RAGPromptBuilder::injectStockStatus() */
    public function injectStockStatus(string $prompt): string
    {
        return $this->promptBuilder->injectStockStatus($prompt);
    }
```

   (Copy the parameter list of `buildEnhancedPrompt` from the original declaration — if it differs from the one shown here, the original wins.)
4. In `generateResponse`, rewrite the three direct calls: `$this->getSystemPromptForBot(` → `$this->promptBuilder->getSystemPromptForBot(`, `$this->buildChainOfThoughtInstruction(` → `$this->promptBuilder->buildChainOfThoughtInstruction(`. Leave `$this->buildEnhancedPrompt(` and `$this->buildPurchaseHistoryBlock(` (they hit the wrappers that tests reflect).

- [ ] **Step 5: Run the RAG suites**

Run: `cd backend && php artisan test tests/Unit/Services/RAG tests/Unit/Services/RAGServiceTest.php tests/Feature/RAG tests/Feature/Payment/OrderPayloadEndToEndTest.php --compact`
Expected: PASS (RAGServiceTest's reflection helpers still find `buildEnhancedPrompt` / `buildPurchaseHistoryBlock` on `RAGService`).

- [ ] **Step 6: Full suite + Pint**

Run: `cd backend && php artisan test --parallel --compact && vendor/bin/pint --test`
Expected: 0 failed; Pint passed.

- [ ] **Step 7: Report**: files changed, test summary, `wc -l app/Services/RAG/RAGPromptBuilder.php app/Services/RAGService.php`.

---

### Task 3: RAGKnowledgeBase

**Files:**
- Create: `backend/app/Services/RAG/RAGKnowledgeBase.php`
- Create: `backend/tests/Unit/Services/RAG/RAGKnowledgeBaseTest.php`
- Modify: `backend/app/Services/RAGService.php` (methods at original lines 300-309, 316-328, 334-389, 577-581, 629-704, 1033-1098 and call sites in `generateResponse` / `testRAG`)

**Interfaces:**
- Produces: `App\Services\RAG\RAGKnowledgeBase` with `__construct(HybridSearchService $hybridSearchService, FlowCacheService $flowCacheService, ?CRAGService $cragService = null)` and public methods `shouldUseKnowledgeBase(Bot $bot): bool`, `getKnowledgeBaseContext(Bot $bot, string $query, array &$metadata): string`, `getFlowKnowledgeBaseContext(Flow $flow, string $query, array &$metadata): string`, `flowHasKnowledgeBases(Flow $flow): bool`, `formatKnowledgeBaseContext($results): string`, `getApiKeyForBot(Bot $bot): ?string`; protected `formatThaiContext`, `formatEnglishContext`, `applyCRAG`.
- `RAGService` gains `private RAGKnowledgeBase $knowledgeBase`; wrappers: public `formatKnowledgeBaseContext`, `getFlowKnowledgeBaseContext`, `flowHasKnowledgeBases`; protected `getApiKeyForBot`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Unit/Services/RAG/RAGKnowledgeBaseTest.php`:

```php
<?php

namespace Tests\Unit\Services\RAG;

use App\Models\Bot;
use App\Services\FlowCacheService;
use App\Services\HybridSearchService;
use App\Services\RAG\RAGKnowledgeBase;
use Tests\TestCase;

class RAGKnowledgeBaseTest extends TestCase
{
    private RAGKnowledgeBase $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kb = new RAGKnowledgeBase(
            $this->createMock(HybridSearchService::class),
            $this->createMock(FlowCacheService::class),
            null,
        );
    }

    public function test_empty_results_format_to_empty_string(): void
    {
        $this->assertSame('', $this->kb->formatKnowledgeBaseContext(collect()));
    }

    public function test_thai_template_lists_each_chunk_with_relevance(): void
    {
        config(['rag.context_template' => 'thai']);
        $results = collect([
            ['similarity' => 0.876, 'document_name' => 'price.pdf', 'content' => 'ราคา 100 บาท'],
        ]);

        $context = $this->kb->formatKnowledgeBaseContext($results);

        $this->assertStringContainsString('ความเกี่ยวข้อง 88%', $context);
        $this->assertStringContainsString('📄 price.pdf', $context);
        $this->assertStringContainsString('ราคา 100 บาท', $context);
    }

    public function test_api_key_falls_back_to_config_when_bot_has_no_user_settings(): void
    {
        config(['services.openrouter.api_key' => 'cfg-key']);

        $this->assertSame('cfg-key', $this->kb->getApiKeyForBot(new Bot));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Unit/Services/RAG/RAGKnowledgeBaseTest.php --compact`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the class by moving the nine methods**

Create `backend/app/Services/RAG/RAGKnowledgeBase.php`:

```php
<?php

namespace App\Services\RAG;

use App\Models\Bot;
use App\Models\Flow;
use App\Services\CRAGService;
use App\Services\FlowCacheService;
use App\Services\HybridSearchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Knowledge-base retrieval, formatting and CRAG split out of RAGService
 * (Track 3). Bodies are moved verbatim.
 */
class RAGKnowledgeBase
{
    public function __construct(
        private readonly HybridSearchService $hybridSearchService,
        private readonly FlowCacheService $flowCacheService,
        private readonly ?CRAGService $cragService = null,
    ) {}

    // ── paste, in this order, exactly as they appear in RAGService.php (docblocks included): ──
    // shouldUseKnowledgeBase       — `protected` → `public`
    // getKnowledgeBaseContext      — `protected` → `public`
    // formatKnowledgeBaseContext   — keep `public`
    // formatThaiContext            — stays `protected`
    // formatEnglishContext         — stays `protected`
    // getApiKeyForBot              — `protected` → `public`
    // getFlowKnowledgeBaseContext  — keep `public`
    // flowHasKnowledgeBases        — keep `public`
    // applyCRAG                    — stays `protected`
}
```

Inside the moved bodies: `$this->flowCacheService`, `$this->hybridSearchService`, `$this->cragService` keep their names; `$this->getFlowKnowledgeBaseContext(`, `$this->getApiKeyForBot(`, `$this->applyCRAG(`, `$this->formatKnowledgeBaseContext(`, `$this->formatThaiContext(`, `$this->formatEnglishContext(` are all same-class — unchanged. Remove `use Illuminate\Support\Collection;` from `RAGService.php` if now unused there.

- [ ] **Step 4: Wire the knowledge base into RAGService**

1. Import `use App\Services\RAG\RAGKnowledgeBase;`.
2. Property `private RAGKnowledgeBase $knowledgeBase;` and in the constructor body: `$this->knowledgeBase = new RAGKnowledgeBase($this->hybridSearchService, $this->flowCacheService, $this->cragService);`
3. Wrappers:

```php
    /** @see RAGKnowledgeBase::formatKnowledgeBaseContext() */
    public function formatKnowledgeBaseContext($results): string
    {
        return $this->knowledgeBase->formatKnowledgeBaseContext($results);
    }

    /** @see RAGKnowledgeBase::getFlowKnowledgeBaseContext() */
    public function getFlowKnowledgeBaseContext(Flow $flow, string $query, array &$metadata): string
    {
        return $this->knowledgeBase->getFlowKnowledgeBaseContext($flow, $query, $metadata);
    }

    /** @see RAGKnowledgeBase::flowHasKnowledgeBases() */
    public function flowHasKnowledgeBases(Flow $flow): bool
    {
        return $this->knowledgeBase->flowHasKnowledgeBases($flow);
    }

    /** @see RAGKnowledgeBase::getApiKeyForBot() */
    protected function getApiKeyForBot(Bot $bot): ?string
    {
        return $this->knowledgeBase->getApiKeyForBot($bot);
    }
```

4. In `generateResponse` and `testRAG`, rewrite: `$this->shouldUseKnowledgeBase(` → `$this->knowledgeBase->shouldUseKnowledgeBase(` (2 sites), `$this->getKnowledgeBaseContext(` → `$this->knowledgeBase->getKnowledgeBaseContext(` (2 sites). Leave `$this->getApiKeyForBot(` calls (wrapper).

- [ ] **Step 5: Run the RAG suites**

Run: `cd backend && php artisan test tests/Unit/Services/RAG tests/Unit/Services/RAGServiceTest.php tests/Unit/Services/CRAGServiceTest.php tests/Feature/RAG --compact`
Expected: PASS.

- [ ] **Step 6: Full suite + Pint**

Run: `cd backend && php artisan test --parallel --compact && vendor/bin/pint --test`
Expected: 0 failed; Pint passed.

- [ ] **Step 7: Report**: files changed, test summary, `wc -l app/Services/RAG/*.php app/Services/RAGService.php`.

---

### Task 4: Orchestrator docblock + dead-import sweep

**Files:**
- Modify: `backend/app/Services/RAGService.php` (class docblock lines 12-25, `use` block lines 5-11)

- [ ] **Step 1: Update the class docblock**

Replace the existing `RAG (Retrieval Augmented Generation) Service` docblock body with:

```php
/**
 * RAG (Retrieval Augmented Generation) orchestrator.
 *
 * Owns generateResponse()/testRAG() and the model/effort/cache decisions;
 * delegates to App\Services\RAG\RAGIntentDetector (message classification),
 * RAGKnowledgeBase (KB retrieval, formatting, CRAG) and RAGPromptBuilder
 * (system-prompt assembly). Public wrappers below keep the pre-split call
 * surface for AIService, StreamingResponseOrchestrator, FlowController and
 * PromptEvalRunner.
 */
```

- [ ] **Step 2: Remove imports that Tasks 1-3 left unused**

Run: `cd backend && for c in Order Collection Flow Conversation Throwable Log; do printf '%s: ' "$c"; grep -c "\b$c\b" app/Services/RAGService.php; done`
Any class whose count is 1 (only the `use` line) → delete its `use` line. Do not touch imports that are still referenced.

- [ ] **Step 3: Full suite + Pint**

Run: `cd backend && php artisan test --parallel --compact && vendor/bin/pint --test`
Expected: 0 failed; Pint passed.

- [ ] **Step 4: Report**: the final `use` block and `wc -l app/Services/RAGService.php`.

---

### Task 5: Verbatim + size verification (controller runs this; worker may pre-run)

**Files:** none modified.

- [ ] **Step 1: Size gate**

Run: `cd backend && wc -l app/Services/RAG/*.php`
Expected: each ≤ 350.

- [ ] **Step 2: Verbatim gate**

Save as `/private/tmp/claude-501/-Users-jaochai-Code-bot-fb/94b37e4b-8cb3-4a06-b14d-1564935faa54/scratchpad/verbatim.py` and run `python3 verbatim.py` from `backend/`:

```python
import re, subprocess, sys
old = subprocess.check_output(['git', 'show', 'main:backend/app/Services/RAGService.php'], text=True)
new = {}
for f in ['app/Services/RAG/RAGIntentDetector.php', 'app/Services/RAG/RAGPromptBuilder.php', 'app/Services/RAG/RAGKnowledgeBase.php']:
    new[f] = open(f, encoding='utf-8').read()
moved = ['isSimpleMessage','detectComplexity','detectToolIntent','detectLanguage',
         'buildEnhancedPrompt','buildPurchaseHistoryBlock','formatThaiDate','injectStockStatus',
         'buildChainOfThoughtInstruction','getSystemPromptForBot','getDefaultSystemPrompt',
         'shouldUseKnowledgeBase','getKnowledgeBaseContext','getFlowKnowledgeBaseContext',
         'flowHasKnowledgeBases','formatKnowledgeBaseContext','formatThaiContext',
         'formatEnglishContext','applyCRAG','getApiKeyForBot']
def body(src, name):
    m = re.search(r'function ' + name + r'\(.*?\n    \{(.*?)\n    \}\n', src, re.S)
    return re.sub(r'\s+', '', m.group(1)) if m else None
bad = 0
for name in moved:
    o = body(old, name)
    n = next((body(s, name) for s in new.values() if body(s, name)), None)
    if o is None or n is None:
        print(f'MISSING {name}: old={o is not None} new={n is not None}'); bad += 1
    elif o != n:
        print(f'DIFF    {name}'); bad += 1
    else:
        print(f'ok      {name}')
sys.exit(1 if bad else 0)
```

Expected: every line `ok`, exit 0. A `DIFF` is acceptable only where the body legitimately gained a `->promptBuilder->` / `->knowledgeBase->` / `->intentDetector->` hop; inspect that diff by hand and record it in the ledger.

- [ ] **Step 3: Full suite one last time**

Run: `cd backend && php artisan test --parallel --compact && vendor/bin/pint --test`
Expected: passed count ≥ baseline + 12 new tests, 0 failed.

- [ ] **Step 4: PR** — `gh pr create --base main --head refactor/rag-split` with a body that lists the four new files, the wrapper table from Global Constraints, and this checklist for the owner:

```
- [ ] `php artisan prompt:eval` on Railway shell BEFORE merge: <paste summary line>
- [ ] `php artisan prompt:eval` on Railway shell AFTER deploy: <paste summary line> (must be identical)
```

## Self-Review

- **Spec coverage:** §7 table — every method assigned to a task (1: intent ×4, 2: prompt ×7, 3: KB ×8 + `getApiKeyForBot`, orchestrator keeps the rest). Constructor injection ✓. Wrappers for the seven public externally-used methods ✓ plus the two protected ones tests reflect. ≤350 LOC gate ✓ (Task 5). `prompt:eval` identical-result requirement → owner checklist in the PR (cannot run locally).
- **Placeholder scan:** no TBD/TODO. Line numbers are marked as original/approximate where later tasks shift them; workers locate by method name.
- **Type consistency:** `RAGIntentDetector` has no ctor; `RAGPromptBuilder(StockInjectionService)`; `RAGKnowledgeBase(HybridSearchService, FlowCacheService, ?CRAGService)`; property names `intentDetector` / `promptBuilder` / `knowledgeBase` used identically in Tasks 1-4 and the verbatim gate.
