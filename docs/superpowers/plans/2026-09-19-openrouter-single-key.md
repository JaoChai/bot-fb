# OpenRouter Single Key Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The OpenRouter API key lives only in the `OPENROUTER_API_KEY` environment variable and is read by exactly one class; the per-user key, its Settings UI, and the key threading through ~25 method signatures are removed.

**Architecture:** A new stateless `App\Services\OpenRouterCredentials` is the only reader of `config('services.openrouter.api_key')`. The five classes that make HTTP calls to OpenRouter (`OpenRouterService`, `EmbeddingService`, `ModelCapabilityService`, `PromptEvalRunner`, `StreamingResponseOrchestrator`) ask it for the key. Every intermediate layer stops accepting or forwarding a key. Work proceeds signature by signature, each task changing one class's signatures together with all of its callers and tests so the suite is green after every commit.

**Tech Stack:** PHP / Laravel (backend, PHPUnit via `php artisan test`), React + TypeScript (frontend, `npm run build`, `npm run lint`, `npm run test`), Railway (hosting), PostgreSQL on Neon.

**Spec:** `docs/superpowers/specs/2026-09-19-openrouter-single-key-design.md`

## Global Constraints

- **The key must exist in Railway before the new code deploys.** Reversed, every bot stops answering. Task 0 precedes any merge.
- Every production-touching step (setting a Railway variable, merging to `main`, running a migration on prod) needs the owner's explicit approval **in the turn it happens**. Approval does not carry over.
- PR 1 contains **no migration**. The columns `user_settings.openrouter_api_key` and `user_settings.openrouter_model` stay in the database, unread, until PR 2 (at least 3 days after PR 1 is stable).
- Not touched: `JinaRerankerService` (Jina's own key), LINE and EasySlip credentials in `user_settings`, `StoreBotRequest` (`api_keys` there are LINE tokens), historical migrations, `.env.example`.
- Out of scope: `require_parameters`, adding models to `config/llm-models.php`, Thai chunking, any Jev integration.
- Surgical changes only (repo `CLAUDE.md` §3): touch only lines that carry the key. Do not reformat, rename, or "improve" neighbouring code.
- Missing key in a customer-facing path must surface as `App\Exceptions\OpenRouterException` so existing handlers send the standard friendly error instead of failing the job into retries.
- Payment-path behaviour is preserved: `OrderReconstructor` and `LLMOrderItemExtractor` still **skip gracefully and return `[]`** when no key is configured.
- Never print, log, or commit a key value.
- Branch: `refactor/openrouter-single-key` (already created from `origin/main`). Commit after every task. Do not push or open a PR until Task 10.
- All backend commands run from `backend/`; all frontend commands from `frontend/`.

## File Structure

| File | Responsibility after this plan |
|---|---|
| `backend/app/Services/OpenRouterCredentials.php` (new) | The only reader of the key. `isConfigured(): bool`, `key(): string` (throws). |
| `backend/app/Services/OpenRouterService.php` | Chat / tools / vision HTTP calls; key from credentials; no `apiKeyOverride`. |
| `backend/app/Services/EmbeddingService.php` | Embedding HTTP calls; key from credentials; no ctor key, no `withApiKey()`. |
| `backend/app/Services/ModelCapabilityService.php` | Model catalogue; key from credentials; logs **error** when missing. |
| `backend/app/Services/PromptEval/PromptEvalRunner.php` | Raw eval HTTP call; key from credentials. |
| `backend/app/Services/Streaming/StreamingResponseOrchestrator.php` | Streaming HTTP call; key from credentials; `run()` has no `apiKey`. |
| Search / cache / RAG layer (8 files) | No key parameters at all. |
| `backend/app/Http/Controllers/Api/UserSettingController.php`, `UserSetting`, `User`, `routes/api.php` | Per-user OpenRouter key feature removed. |
| `frontend/src/pages/SettingsPage.tsx`, `hooks/useUserSettings.ts`, `types/api.ts`, `components/connections/sections/AIModelsSection.tsx` | OpenRouter section removed. |
| `backend/tests/Unit/Services/OpenRouterCredentialsTest.php` (new) | Unit test for the accessor. |
| `backend/tests/Unit/OpenRouterKeySingleSourceTest.php` (new) | Guard: scans `app/` so the duplication cannot return. |

---

### Task 0: Production prerequisite — set the key in Railway (owner-gated, no code)

**Files:** none.

This task is operational. **Stop and ask the owner before each production action.**

- [ ] **Step 1: Ask the owner for the key**

Ask the owner to create a new dedicated key at https://openrouter.ai/keys (suggested name "bot-fb production", with a spend limit) and to provide it for this step. Do **not** decrypt any key out of `user_settings`.

- [ ] **Step 2: Baseline eval BEFORE setting the variable**

Run from the repo root (`railway run` injects prod env; the key is supplied for this process only and is not stored):

Put the key in a shell variable first with `read -rs OR_KEY` (paste, Enter) so it never lands in shell history, then:

```bash
railway run --service backend -- \
  env CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array TELESCOPE_ENABLED=false \
  OPENROUTER_API_KEY="$OR_KEY" \
  php backend/artisan prompt:eval --bot=26 --runs=2 --json=/tmp/eval-before.json < /dev/null
```

Expected: a pass/fail table. Record the failing case ids. Three BM cases (`bm_with_unit`, `order_payload_total`, `phantom_page_line`) fail while "Nolimit Level Up+ BM" is out of stock — that is not a regression. Cost ≈ $0.31 per run.

Note: with `CACHE_STORE=array` the capability cache is empty, so this local run already resolves luna from the OpenRouter API. It therefore measures the **post-Step-3** behaviour. The true "before" is production as it runs today; treat this run as the reference the later runs must match.

- [ ] **Step 3: Set the variable (requires owner approval in this turn)**

```bash
printf '%s' "$OR_KEY" | railway variable set --service backend --stdin OPENROUTER_API_KEY
```

(Value over stdin, so the key is not visible in the process list or shell history. If this CLI version rejects `--stdin`, the legacy form is `printf '%s' "$OR_KEY" | railway variables --service backend --set-from-stdin OPENROUTER_API_KEY`.)

This triggers a Railway redeploy of the **current** code, which still prefers the per-user key. Within ~30 minutes (unknown-model cache TTL is 1800 s) `ModelCapabilityService` resolves `openai/gpt-5.6-luna` from the API: bot 26 gains JSON mode, `reasoning.effort = low`, and the enhanced intent prompt whose `search_query` feeds KB retrieval. **This changes live retrieval behaviour.**

- [ ] **Step 4: Verify on production**

Wait until the new deployment is serving (the old instance keeps serving for several minutes). Then:

```bash
railway ssh --service backend -- sh -c 'cd /var/www/html && php artisan model:warm-cache --model=openai/gpt-5.6-luna'
```

Expected: the table shows `Reasoning | Yes`, `Structured Output | Yes`, `Context Length | 1,050,000`, and a `Source` other than `default+heuristic`.

- [ ] **Step 5: Eval AFTER, on production**

```bash
railway ssh --service backend -- sh -c 'cd /var/www/html && php artisan prompt:eval --bot=26 --runs=2 --json=/tmp/eval-after.json'
```

Expected: no case fails that passed in Step 2, other than the three BM cases. If a new case fails, re-run it with `--runs=2 --filter=<id>` (single runs flip 1–3 cases from randomness). If it still fails, **stop and report to the owner**; rollback is `railway variable delete --service backend OPENROUTER_API_KEY` (owner-approved).

---

### Task 1: `OpenRouterCredentials`

**Files:**
- Create: `backend/app/Services/OpenRouterCredentials.php`
- Test: `backend/tests/Unit/Services/OpenRouterCredentialsTest.php`

**Interfaces:**
- Consumes: `App\Exceptions\OpenRouterException::__construct(string $message, int $httpStatus = 500, ?\Throwable $previous = null)` (exists).
- Produces: `App\Services\OpenRouterCredentials` with `isConfigured(): bool` and `key(): string`. `key()` throws `OpenRouterException` (status 500) whose message contains `OPENROUTER_API_KEY` when the key is null or empty. No constructor arguments; resolved by the container without registration.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Services/OpenRouterCredentialsTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Exceptions\OpenRouterException;
use App\Services\OpenRouterCredentials;
use Tests\TestCase;

class OpenRouterCredentialsTest extends TestCase
{
    public function test_key_returns_the_configured_value(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);

        $credentials = new OpenRouterCredentials;

        $this->assertTrue($credentials->isConfigured());
        $this->assertSame('synthetic-not-a-key', $credentials->key());
    }

    public function test_missing_key_is_reported_and_throws(): void
    {
        foreach ([null, ''] as $missing) {
            config(['services.openrouter.api_key' => $missing]);
            $credentials = new OpenRouterCredentials;

            $this->assertFalse($credentials->isConfigured());

            try {
                $credentials->key();
                $this->fail('Expected OpenRouterException for '.var_export($missing, true));
            } catch (OpenRouterException $e) {
                $this->assertStringContainsString('OPENROUTER_API_KEY', $e->getMessage());
                $this->assertSame(500, $e->getHttpStatus());
            }
        }
    }

    public function test_config_is_read_on_every_call_not_frozen_at_construction(): void
    {
        config(['services.openrouter.api_key' => null]);
        $credentials = new OpenRouterCredentials;
        $this->assertFalse($credentials->isConfigured());

        config(['services.openrouter.api_key' => 'synthetic-late-key']);
        $this->assertSame('synthetic-late-key', $credentials->key());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=OpenRouterCredentialsTest`
Expected: FAIL — `Class "App\Services\OpenRouterCredentials" not found`.

- [ ] **Step 3: Write the implementation**

Create `backend/app/Services/OpenRouterCredentials.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\OpenRouterException;

/**
 * The only reader of the OpenRouter API key. The key lives in one place, the
 * OPENROUTER_API_KEY environment variable, and every class that makes an HTTP call
 * to OpenRouter asks here instead of reading config itself.
 *
 * Reads config on every call rather than caching in a property: several consumers
 * are container singletons, and tests change the config after construction.
 */
final class OpenRouterCredentials
{
    public function isConfigured(): bool
    {
        return config_string('services.openrouter.api_key') !== '';
    }

    /**
     * @throws OpenRouterException when the key is not set. OpenRouterException, not a
     *                             RuntimeException, so customer-facing paths reply with
     *                             the standard error message instead of failing into retries.
     */
    public function key(): string
    {
        $key = config_string('services.openrouter.api_key');

        if ($key === '') {
            throw new OpenRouterException('OPENROUTER_API_KEY is not set', 500);
        }

        return $key;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=OpenRouterCredentialsTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/OpenRouterCredentials.php backend/tests/Unit/Services/OpenRouterCredentialsTest.php
git commit -m "feat(openrouter): add OpenRouterCredentials, the single reader of the API key"
```

---

### Task 2: `OpenRouterService` and all of its direct callers

**Files:**
- Modify: `backend/app/Services/OpenRouterService.php` (lines 14, 26–36, 45, 57, 72, 122, 159, 199, 211, 226, 263, 302, 314, 342, 405, 416, 427, 466, 600–603, 722–731)
- Modify (delete `apiKeyOverride:` and the local key resolution): `FlowController.php:572-577,618`, `BotController.php:392-403`, `StickerReplyService.php:85-87,96`, `EntityExtractionService.php:125-126,168`, `FlowPluginService.php:177-183,199`, `VisionHandler.php:139-141,150`, `LineWebhookResponseService.php:441-443,452,818-819,888`, `OrderReconstructor.php:112-113,135`, `LLMOrderItemExtractor.php:45-50,62`
- Modify (delete `apiKeyOverride:` only; their own key parameters are removed in Task 3): `RAGService.php:265`, `ContextualRetrievalService.php:83,205,311`, `CRAGService.php:142`, `IntentAnalysisService.php:76`
- Test: `backend/tests/Unit/Services/OpenRouterServiceTest.php` and every test found by Step 1

**Interfaces:**
- Consumes: `OpenRouterCredentials::key(): string`, `OpenRouterCredentials::isConfigured(): bool` (Task 1).
- Produces — new signatures (every other parameter keeps its name, type, default and order):
  - `chat(array $messages, ?string $model = null, ?float $temperature = null, ?int $maxTokens = null, bool $useFallback = true, ?string $fallbackModelOverride = null, ?int $timeout = null, ?array $reasoning = null, ?array $responseFormat = null): array`
  - `chatWithTools(array $messages, array $tools, ?string $model = null, ?float $temperature = null, ?int $maxTokens = null, string $toolChoice = 'auto', bool $useFallback = true, ?string $fallbackModelOverride = null, ?int $timeout = null): array`
  - `generateBotResponse(string $userMessage, ?string $systemPrompt = null, array $conversationHistory = [], ?string $model = null, ?string $fallbackModel = null, ?float $temperature = null, ?int $maxTokens = null, ?array $reasoning = null, ?int $timeout = null): array`
  - `chatWithVision(array $messages, array $imageUrls, ?string $model = null, ?float $temperature = null, ?int $maxTokens = null, bool $useFallback = true, ?string $fallbackModelOverride = null, ?array $responseFormat = null): array`
  - `client(?int $timeout = null): PendingRequest`
  - `isConfigured(): bool` — unchanged signature, now delegates to credentials.

- [ ] **Step 1: List every caller, including tests**

Run:

```bash
grep -rn -E "apiKeyOverride|->client\(" --include="*.php" app/ tests/
```

Every hit outside `OpenRouterService.php` must be handled in this task. **Pay attention to positional calls**: any test or caller that passes the key positionally (e.g. `chat($m, 'model', 0.7, 100, true, 'key', 'fallback')`) must have that argument deleted so the following arguments shift into the right slot.

- [ ] **Step 2: Update the existing empty-key test so it fails first**

In `backend/tests/Unit/Services/OpenRouterServiceTest.php`, find the test that sets `config(['services.openrouter.api_key' => ''])` (line ~40). Replace its body's assertion block with:

```php
        config(['services.openrouter.api_key' => '']);
        $service = app(\App\Services\OpenRouterService::class);

        $this->assertFalse($service->isConfigured());

        $this->expectException(\App\Exceptions\OpenRouterException::class);
        $this->expectExceptionMessage('OPENROUTER_API_KEY');

        $service->chat(
            messages: [['role' => 'user', 'content' => 'hi']],
            model: 'openai/gpt-4o-mini',
            useFallback: false,
        );
```

Keep the test's method name. If the file constructs the service with `new OpenRouterService(...)`, leave that construction style for the other tests and only use `app()` here.

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter=OpenRouterServiceTest`
Expected: that test FAILS (no exception is thrown today: an empty key is sent as `Bearer `).

- [ ] **Step 4: Change `OpenRouterService`**

Delete line 14 (`protected string $apiKey;`). Replace the constructor (lines 26–36) with:

```php
    public function __construct(
        private ModelCapabilityService $modelCapability,
        private OpenRouterCredentials $credentials,
    ) {
        $this->baseUrl = config_string('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        $this->siteUrl = config_string('services.openrouter.site_url', config_string('app.url'));
        $this->siteName = config_string('services.openrouter.site_name', config_string('app.name', 'BotFacebook'));
        $this->timeout = config_int('services.openrouter.timeout', 60);
        $this->maxTokens = config_int('services.openrouter.max_tokens', 4096);
    }
```

In each of `chat`, `chatWithTools`, `generateBotResponse`, `chatWithVision`:
1. delete the `?string $apiKeyOverride = null,` parameter;
2. delete its `@param  string|null  $apiKeyOverride ...` docblock line;
3. delete the line `$apiKey = $apiKeyOverride ?? $this->apiKey;` (lines 72, 226, 427; `generateBotResponse` has none).

Replace the three `client(...)` calls:

```php
// line 122 and line 263
$response = $this->client($requestTimeout)->post('/chat/completions', $payload);
// line 466
$response = $this->client()->post('/chat/completions', $payload);
```

In the fallback recursion (lines 153–164) delete the single line `apiKeyOverride: $apiKey,`.

Replace line 342 with:

```php
        return $this->chat($messages, $model, $temperature, $maxTokens, true, $fallbackModel, $timeout, $reasoning);
```

Replace `isConfigured()` (lines 600–603) with:

```php
    public function isConfigured(): bool
    {
        return $this->credentials->isConfigured();
    }
```

Replace the head of `client()` (lines 722–731) with:

```php
    /**
     * Get configured HTTP client.
     *
     * @param  int|null  $timeout  Request timeout in seconds (null uses default)
     */
    protected function client(?int $timeout = null): PendingRequest
    {
        $key = $this->credentials->key();
        $requestTimeout = $timeout ?? $this->timeout;
```

Leave the rest of `client()` untouched.

- [ ] **Step 5: Fix callers that only forward the key**

In `RAGService.php:265`, `ContextualRetrievalService.php:83`, `:205`, `:311`, `CRAGService.php:142` delete the one line `apiKeyOverride: $apiKey,` (or `apiKeyOverride: $apiKey` when it is the last argument — then also remove the trailing comma the previous line needs none of). In `IntentAnalysisService.php:76` delete the array entry `'apiKeyOverride' => $apiKey,`. Their `$apiKey` variables become unused until Task 3; that is expected.

- [ ] **Step 6: Fix callers that resolve the key themselves**

`FlowController.php` — replace lines 572–577:

```php
        if (! app(\App\Services\OpenRouterCredentials::class)->isConfigured()) {
            return $this->validationError('ระบบยังไม่ได้ตั้งค่า OpenRouter API Key กรุณาติดต่อผู้ดูแลระบบ', ['error_code' => 'NO_API_KEY']);
        }
```

and delete `apiKeyOverride: $apiKey,` at line 618.

`BotController.php` — replace lines 392–403 (the comment, the `$apiKey` assignment and the `if (empty($apiKey))` block) with:

```php
        if (! app(\App\Services\OpenRouterCredentials::class)->isConfigured()) {
            return $this->success([
                'input' => $userMessage,
                'response' => 'ระบบยังไม่ได้ตั้งค่า OpenRouter API Key กรุณาติดต่อผู้ดูแลระบบ',
                'bot_id' => $bot->id,
            ], 'Test message received');
        }
```

`FlowPluginService.php` — replace lines 177–183 with:

```php
        if (! app(OpenRouterCredentials::class)->isConfigured()) {
            Log::warning('No API key for plugin evaluation', ['plugin_id' => $plugin->id]);

            return false;
        }
```

add `use App\Services\OpenRouterCredentials;` only if the file is outside the `App\Services` namespace (it is inside, so no import is needed), and delete `apiKeyOverride: $apiKey,` at line 199.

`OrderReconstructor.php` — replace lines 112–113:

```php
        if ($model === null || ! app(\App\Services\OpenRouterCredentials::class)->isConfigured()) {
```

and delete `apiKeyOverride: $apiKey,` at line 135. The `Log::debug` and `return [];` that follow stay as they are.

`LLMOrderItemExtractor.php` — replace lines 45–46:

```php
        if (! app(\App\Services\OpenRouterCredentials::class)->isConfigured()) {
```

(the `Log::debug(...)` and `return [];` below stay), and delete `apiKeyOverride: $apiKey,` at line 62.

`StickerReplyService.php` — delete lines 85–87 (the `// 4. Call Vision API` comment stays; delete the two-line `$apiKey = ...` statement) and `apiKeyOverride: $apiKey,` at line 96.

`EntityExtractionService.php` — delete lines 125–126 (the `$apiKey = ...` statement) and the `apiKeyOverride: $apiKey` argument at line 168; remove the trailing comma on the argument before it if `apiKeyOverride` was last.

`VisionHandler.php` — delete lines 139–141 (`// Get API key` comment and the statement) and `apiKeyOverride: $apiKey,` at line 150.

`LineWebhookResponseService.php` — delete lines 441–443 (comment and statement) and `apiKeyOverride: $apiKey,` at line 452; delete lines 818–819 (statement) and `apiKeyOverride: $apiKey,` at line 888.

`app()` is used at these sites instead of constructor injection so no constructor, service-provider binding, or test double changes — the smallest diff.

- [ ] **Step 7: Fix tests**

For each test file from Step 1: delete `apiKeyOverride:` named arguments and positional key arguments. For the six tests that seed a per-user key — `tests/Feature/OrderReconstructorTest.php`, `tests/Feature/Payment/ConfirmMessageFallbackTest.php`, `tests/Feature/Payment/LLMOrderItemFallbackTest.php`, `tests/Feature/Payment/OrderChecksumGuardTest.php`, `tests/Feature/SlipVerificationServiceTest.php`, `tests/Unit/Services/Webhook/LINE/VisionHandlerTest.php` — replace each `UserSetting` creation/update that sets `openrouter_api_key` with:

```php
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
```

Where a test asserts the **"no key → skip"** branch by leaving the user without a key, make it explicit instead:

```php
        config(['services.openrouter.api_key' => null]);
```

Keep any other `UserSetting` fields those tests set (LINE, EasySlip).

- [ ] **Step 8: Verify**

Run: `php artisan test`
Expected: PASS; no test that passes on `main` fails.

Run: `grep -rn "apiKeyOverride" --include="*.php" app/ tests/`
Expected: no output.

- [ ] **Step 9: Commit**

```bash
git add -A backend/app backend/tests
git commit -m "refactor(openrouter): OpenRouterService reads the key from OpenRouterCredentials"
```

---

### Task 3: `EmbeddingService` and the search / cache / RAG layer

**Files:**
- Modify: `backend/app/Services/EmbeddingService.php` (16, 21–28, 34–36, 71–73, 127, 145–157, 163–166)
- Modify: `SemanticSearchService.php` (28, 35, 44–48, 101, 108, 113–116), `HybridSearchService.php` (64, 72, 82, 96, 138, 145, 153, 156–160, 170), `SemanticCacheService.php` (70, 73, 95, 130, 138, 146–148, 204, 207–209), `CRAGService.php` (114), `ContextualRetrievalService.php` (41, 47, 111, 118, 138, 173, 275, 282), `RAG/RAGKnowledgeBase.php` (124–133, 159–160, 167, 171, 231, 252, 258), `RAGService.php` (72, 74–75, 94, 152, 294, 367–371), `IntentAnalysisService.php` (35, 45, 404–411), `Jobs/ProcessDocument.php` (58–60, 98–125, 226–245), `Http/Controllers/Api/KnowledgeBaseController.php` (113–122)
- Test: every test found by Step 1

**Interfaces:**
- Consumes: `OpenRouterCredentials::key()`, `::isConfigured()` (Task 1).
- Produces — new signatures:
  - `EmbeddingService::__construct(private OpenRouterCredentials $credentials)`; `withApiKey()` deleted; `isConfigured(): bool` delegates.
  - `SemanticSearchService::search(int $knowledgeBaseId, string $query, int $limit = 5, ?float $threshold = null, ?array $precomputedEmbedding = null): Collection`
  - `SemanticSearchService::searchMultiple(array $kbConfigs, string $query, int $totalLimit = 10): Collection`
  - `HybridSearchService::search(...)` and `::searchMultiple(...)` — same two shapes as above.
  - `SemanticCacheService::get(Bot $bot, string $query): ?array`, `::put(Bot $bot, string $query, string $response, array $metadata = []): ?RagCache`, `::getSemanticMatch(Bot $bot, string $query): ?array`
  - `CRAGService::rewriteQuery(string $originalQuery, Collection $failedResults): string`
  - `ContextualRetrievalService::generateDocumentSummary(string $documentTitle, string $documentContent): array`, `::generateChunkContexts(string $documentTitle, string $documentSummary, array $chunks): array`, `::generateSingleChunkContext(string $documentTitle, string $documentSummary, string $chunkContent): array`; the private batch method loses its trailing `?string $apiKey`.
  - `RAGKnowledgeBase::applyCRAG(Collection $results, string $query, array $kbConfigs, array &$metadata): Collection`; `getApiKeyForBot()` deleted.
  - `RAGService::generateResponse(Bot $bot, string $userMessage, array $conversationHistory = [], ?Conversation $conversation = null, ?Flow $flow = null): array`; `getApiKeyForBot()` deleted.
  - `IntentAnalysisService::analyzeIntent()` — the `apiKey` option is no longer read; `getApiKeyForBot()` deleted.

- [ ] **Step 1: List every caller, including tests**

```bash
grep -rn -E "withApiKey|new EmbeddingService|getApiKeyForBot|'apiKey'|apiKey:|\\\$apiKey" --include="*.php" app/ tests/ \
  | grep -v -E "app/Services/(OpenRouterCredentials|JinaRerankerService|ModelCapabilityService)\.php|Streaming/StreamingResponseOrchestrator\.php|Api/StreamController\.php"
```

Every hit must be gone at the end of this task. (The orchestrator, `StreamController`, and `ModelCapabilityService` are Tasks 4 and 5.) **`search()` takes `$apiKey` positionally in the middle**: every caller and test that passes six positional arguments must drop the fifth so `$precomputedEmbedding` lands in slot five.

- [ ] **Step 2: Write the failing test**

Append to `backend/tests/Unit/Services/OpenRouterCredentialsTest.php`:

```php
    public function test_embedding_service_throws_when_the_key_is_missing(): void
    {
        config(['services.openrouter.api_key' => null]);

        $this->expectException(OpenRouterException::class);
        $this->expectExceptionMessage('OPENROUTER_API_KEY');

        app(\App\Services\EmbeddingService::class)->generate('hello');
    }
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter=test_embedding_service_throws_when_the_key_is_missing`
Expected: FAIL — today it throws `RuntimeException`, not `OpenRouterException`.

- [ ] **Step 4: Change `EmbeddingService`**

Delete line 16 (`protected string $apiKey;`). Replace the constructor and its docblock (lines 20–30) with:

```php
    public function __construct(private OpenRouterCredentials $credentials)
    {
        $this->model = config_string('services.embeddings.model', 'openai/text-embedding-3-small');
        $this->dimensions = config_int('services.embeddings.dimensions', 1536);
        $this->baseUrl = config_string('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
    }
```

Delete both guards (lines 34–36 and 71–73):

```php
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenRouter API key is not configured (OPENROUTER_API_KEY)');
        }
```

`credentials->key()` now throws instead. In `getHeaders()` (line 127) use:

```php
            'Authorization' => 'Bearer '.$this->credentials->key(),
```

Delete `withApiKey()` and its docblock (lines 145–157). Replace the body of `isConfigured()` (line 165) with `return $this->credentials->isConfigured();`. Remove the `use RuntimeException;` import only if nothing else in the file uses it.

- [ ] **Step 5: Remove the key from the search and cache layer**

In each signature listed under **Produces**, delete the `?string $apiKey` parameter and its `@param` docblock line. Then:

`SemanticSearchService::search` — replace lines 43–51:

```php
            // Generate embedding for the search query
            $queryEmbedding = $this->embeddingService->generate($query);
```

`SemanticSearchService::searchMultiple` — replace lines 112–118:

```php
        // Generate embedding once for all searches
        $queryEmbedding = $this->embeddingService->generate($query);
```

`HybridSearchService::search` — line 82 becomes `return $this->semanticSearch->search($knowledgeBaseId, $query, $limit, $threshold, $precomputedEmbedding);`; in the multi-line call (lines 91–98) delete the `$apiKey,` line.

`HybridSearchService::searchMultiple` — line 153 becomes `return $this->semanticSearch->searchMultiple($kbConfigs, $query, $totalLimit);`; replace lines 156–161 with `$precomputedEmbedding = app(EmbeddingService::class)->generate($query);` (keep the `// Generate embedding ONCE for all searches` comment above it); line 170 becomes `$results = $this->search($kbId, $query, $limit, $threshold, $precomputedEmbedding);`.

`SemanticCacheService` — line 95 becomes `$semanticMatch = $this->getSemanticMatch($bot, $query);`; replace both `$embeddingService = $apiKey ? ...->withApiKey($apiKey) : $this->embeddingService;` blocks (lines 146–148 and 207–209) and the `$embeddingService->generate($query)` that follows each with `$embedding = $this->embeddingService->generate($query);`.

`ContextualRetrievalService` — line 138 deletes the `$apiKey` argument forwarded to the private batch method.

- [ ] **Step 6: Remove the key from the RAG layer**

`RAGKnowledgeBase.php` — delete `getApiKeyForBot()` and its docblock (lines 119–133). Delete lines 159–160 (the `// Get API key` comment and the `$apiKey = ...` statement). In the `searchMultiple(...)` call (163–168) delete `apiKey: $apiKey` and the comma before it. Line 171 becomes `$results = $this->applyCRAG($results, $query, $kbConfigs, $metadata);`. Line 252 becomes `$rewrittenQuery = $this->cragService->rewriteQuery($query, $results);`. In the second `searchMultiple(...)` (254–259) delete `apiKey: $apiKey` and the comma before it.

`RAGService.php` — delete the `?string $apiKeyOverride = null` parameter (72) and the comma after `?Flow $flow = null`; delete lines 74–75; line 94 becomes `$cachedResponse = $this->semanticCache->get($bot, $userMessage);`; delete the `'apiKey' => $apiKey,` entry (152); delete the `$apiKey` argument at line 294 and the comma before it; delete `getApiKeyForBot()` and its `@see` docblock (366–371).

`IntentAnalysisService.php` — delete the `- apiKey:` docblock line (35), line 45 (`$apiKey = $options['apiKey'] ?? ...`), and `getApiKeyForBot()` with its docblock (404–411).

- [ ] **Step 7: Remove the key from `ProcessDocument` and `KnowledgeBaseController`**

`ProcessDocument.php` — replace lines 58–60 with:

```php
            $embedder = app(EmbeddingService::class);
```

Delete `getUserApiKey()` and its docblock (lines 226–245). In the `generateDocumentSummary(...)` and `generateChunkContexts(...)` calls (98–125) delete the `$apiKey` argument. Remove the `use App\Models\User;` import only if nothing else in the file uses `User`. **Do not remove the job's `$userId` constructor property** — queued payloads already serialized with it must still deserialize.

`KnowledgeBaseController.php` — delete lines 113–115 (comment and statement) and the `$apiKey` argument at line 121, with the comma before it.

- [ ] **Step 8: Fix tests**

For every test from Step 1: delete positional and named key arguments, replace `new EmbeddingService('...')` with `app(EmbeddingService::class)` preceded by `config(['services.openrouter.api_key' => 'synthetic-not-a-key']);`, and delete tests that exist solely to verify `withApiKey()`.

- [ ] **Step 9: Verify**

Run: `php artisan test`
Expected: PASS.

Re-run the Step 1 grep.
Expected: no output.

- [ ] **Step 10: Commit**

```bash
git add -A backend/app backend/tests
git commit -m "refactor(openrouter): stop threading the API key through search, cache and RAG"
```

---

### Task 4: `StreamingResponseOrchestrator` and `StreamController`

**Files:**
- Modify: `backend/app/Services/Streaming/StreamingResponseOrchestrator.php` (59, 92–93, 131, 137, 186, 215, 298–300, 315, 337, 392, 423, 435, 454, 462, 466)
- Modify: `backend/app/Http/Controllers/Api/StreamController.php` (75–79, 98, 116)
- Test: tests found by Step 1

**Interfaces:**
- Consumes: `OpenRouterCredentials`; `SemanticCacheService::get(Bot, string)`; `HybridSearchService::search/searchMultiple` without `apiKey` (Task 3).
- Produces: `StreamingResponseOrchestrator::run(Bot $bot, Flow $flow, string $message, array $conversationHistory, array $memoryNotes, callable $onSseEvent): void`. Private methods `runDecisionModel`, `runChatModel`, `streamFromOpenRouter` lose their `$apiKey` parameter.

- [ ] **Step 1: List callers and tests**

```bash
grep -rn -E "apiKey|->run\(" --include="*.php" app/Services/Streaming app/Http/Controllers/Api/StreamController.php tests/ | grep -i -E "stream|orchestrat"
```

- [ ] **Step 2: Inject credentials**

Add `private OpenRouterCredentials $credentials,` to the orchestrator's constructor parameter list (keep the existing promoted-property style; add `use App\Services\OpenRouterCredentials;`).

- [ ] **Step 3: Remove the key from the orchestrator**

Delete `string $apiKey,` from `run()` (59). Line 92 becomes `rescue(function () use ($bot, $message) {` and line 93 `return $this->semanticCache->get($bot, $message);`. Delete the `$apiKey` argument from the calls at 131 and 137 and from the signatures at 186 and 392. Delete the `'apiKey' => $apiKey,` entry (215). Delete lines 297–300 (comment and `$embeddingApiKey` statement) and the `apiKey: $embeddingApiKey` arguments at 315 and 337 with the comma before each. Delete the `$apiKey` argument from the calls at 423 and 435 and from the signature at 454. Delete line 462 (`$apiKey = $apiKey ?: config(...)`). Line 466 becomes:

```php
                'Authorization' => 'Bearer '.$this->credentials->key(),
```

- [ ] **Step 4: Update `StreamController`**

Replace lines 75–79 with:

```php
        // 4. The OpenRouter key is system-wide (OPENROUTER_API_KEY)
        if (! app(\App\Services\OpenRouterCredentials::class)->isConfigured()) {
            return $this->errorResponse('OpenRouter API key is not configured. Please contact the administrator.', 422);
        }
```

Remove `$apiKey` from the closure's `use (...)` list (98) and delete `apiKey: $apiKey,` (116).

- [ ] **Step 5: Fix tests, verify, commit**

Delete `apiKey:` arguments from any orchestrator test.

Run: `php artisan test`
Expected: PASS.

Run: `grep -rn "apiKey" backend/app/Services/Streaming backend/app/Http/Controllers/Api/StreamController.php`
Expected: no output.

```bash
git add -A backend/app backend/tests
git commit -m "refactor(openrouter): streaming path reads the key from OpenRouterCredentials"
```

---

### Task 5: `ModelCapabilityService` and `PromptEvalRunner`

**Files:**
- Modify: `backend/app/Services/ModelCapabilityService.php` (23, 364–367, 371)
- Modify: `backend/app/Services/PromptEval/PromptEvalRunner.php` (22–25, 57)
- Test: `backend/tests/Unit/Services/ModelCapabilityServiceTest.php`, `backend/tests/Unit/PromptEvalRunnerTest.php`

**Interfaces:**
- Consumes: `OpenRouterCredentials`.
- Produces: `ModelCapabilityService::__construct(CircuitBreakerService $circuitBreaker, OpenRouterCredentials $credentials)`; `PromptEvalRunner::__construct(AIService $ai, RAGService $rag, OpenRouterCredentials $credentials)`.

Circularity check: `OpenRouterService` depends on `ModelCapabilityService`; both now depend on `OpenRouterCredentials`, which depends on nothing. No cycle.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Unit/Services/ModelCapabilityServiceTest.php`:

```php
    public function test_missing_key_logs_an_error_and_falls_back_to_config(): void
    {
        config(['services.openrouter.api_key' => null]);
        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Log::spy();

        $capabilities = app(\App\Services\ModelCapabilityService::class)
            ->getCapabilities('google/gemini-3-flash-preview');

        $this->assertSame('config', $capabilities['source']);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'OPENROUTER_API_KEY'))
            ->atLeast()->once();
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_missing_key_logs_an_error_and_falls_back_to_config`
Expected: FAIL — no `error` log is written today; the method returns `[]` silently.

- [ ] **Step 3: Change `ModelCapabilityService`**

Constructor (line 23):

```php
    public function __construct(
        CircuitBreakerService $circuitBreaker,
        private OpenRouterCredentials $credentials,
    ) {
```

Keep the constructor body as it is. Replace lines 364–367 with:

```php
        if (! $this->credentials->isConfigured()) {
            // Never silent again: a missing key left API-based resolution dead for months.
            Log::error('ModelCapabilityService: OPENROUTER_API_KEY is not set; using the config table only');

            return [];
        }

        $apiKey = $this->credentials->key();
```

Line 371 stays `'Authorization' => 'Bearer '.$apiKey,`.

- [ ] **Step 4: Change `PromptEvalRunner`**

Constructor (22–25):

```php
    public function __construct(
        private readonly AIService $ai,
        private readonly RAGService $rag,
        private readonly OpenRouterCredentials $credentials,
    ) {}
```

Add `use App\Services\OpenRouterCredentials;`. Line 57 becomes:

```php
            ->withToken($this->credentials->key())
```

- [ ] **Step 5: Fix tests**

In both test files, replace any `new ModelCapabilityService($breaker)` with `new ModelCapabilityService($breaker, new OpenRouterCredentials)` and any `new PromptEvalRunner($ai, $rag)` with `new PromptEvalRunner($ai, $rag, new OpenRouterCredentials)`. The existing `config([...api_key...])` lines keep working.

- [ ] **Step 6: Verify and commit**

Run: `php artisan test`
Expected: PASS.

```bash
git add -A backend/app backend/tests
git commit -m "fix(openrouter): model catalogue and prompt eval get the key, and a missing key is logged"
```

---

### Task 6: Remove the per-user key feature (backend, no migration)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/UserSettingController.php` (16–40 `show()`, delete 42–71 `updateOpenRouter`, 106–155 `testOpenRouter`, 206–222 `clearOpenRouter`)
- Modify: `backend/routes/api.php` (105, 107, 109)
- Modify: `backend/app/Models/UserSetting.php` (`$fillable`, `$casts`, `$hidden`, 74–97, 133–144)
- Modify: `backend/app/Models/User.php` (61–63)
- Test: any test found by Step 1

**Interfaces:**
- Consumes: nothing.
- Produces: `GET /settings` no longer returns `openrouter_configured`, `openrouter_api_key_masked`, `openrouter_model`. Routes `PUT /settings/openrouter`, `DELETE /settings/openrouter`, `POST /settings/test-openrouter` no longer exist.

- [ ] **Step 1: Confirm nothing else uses what is being removed**

```bash
grep -rn -E "getOpenRouterApiKey|hasOpenRouterKey|masked_openrouter_key|openrouter_api_key|openrouter_model|updateOpenRouter|testOpenRouter|clearOpenRouter" --include="*.php" app/ routes/ tests/ database/factories database/seeders
```

Expected before the change: hits only in the four files above (plus historical migrations, which are not searched). Any other hit must be resolved first.

- [ ] **Step 2: Write the failing test**

Create `backend/tests/Feature/UserSettingsOpenRouterRemovedTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSettingsOpenRouterRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_payload_no_longer_mentions_openrouter(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/settings');

        $response->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('openrouter', $response->getContent());
    }

    public function test_openrouter_setting_routes_are_gone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/settings/openrouter', ['api_key' => 'x', 'model' => 'y'])->assertStatus(404);
        $this->actingAs($user)->postJson('/api/settings/test-openrouter')->assertStatus(404);
        $this->actingAs($user)->deleteJson('/api/settings/openrouter')->assertStatus(404);
    }
}
```

If the API guard in this project is Sanctum and other feature tests use `Sanctum::actingAs($user)`, use that form instead — match the neighbouring tests in `tests/Feature/`.

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --filter=UserSettingsOpenRouterRemovedTest`
Expected: both tests FAIL (payload contains `openrouter_configured`; the routes respond 200/422, not 404).

- [ ] **Step 4: Remove the feature**

`UserSettingController.php` — delete the methods `updateOpenRouter`, `testOpenRouter`, `clearOpenRouter` with their docblocks. In `show()` delete the three lines that emit `openrouter_configured`, `openrouter_api_key_masked`, `openrouter_model`. Remove imports that become unused.

`routes/api.php` — delete lines 105, 107, 109.

`UserSetting.php` — delete `'openrouter_api_key'` and `'openrouter_model'` from `$fillable`; `'openrouter_api_key' => 'encrypted'` from `$casts`; `'openrouter_api_key'` from `$hidden`; the methods `getOpenRouterApiKey()`, `hasOpenRouterKey()`, `getMaskedOpenRouterKeyAttribute()` with their docblocks. Remove the `DecryptException` and `Log` imports only if nothing else in the file uses them (`getEasySlipApiToken()` likely does — check).

`User.php` — replace lines 61–63 with:

```php
        $settings = $this->settings ?? $this->settings()->create([]);
```

(`openrouter_model` has a database default, so an empty create works while the column still exists and after PR 2 drops it.)

- [ ] **Step 5: Verify and commit**

Run: `php artisan test`
Expected: PASS.

Re-run the Step 1 grep.
Expected: no output.

```bash
git add -A backend/app backend/routes backend/tests
git commit -m "refactor(settings): remove the per-user OpenRouter key and its endpoints"
```

---

### Task 7: Remove the OpenRouter section from the frontend

**Files:**
- Modify: `frontend/src/pages/SettingsPage.tsx` (imports 26–28; hooks 44–46; state 86–88 and the form state around 106; `isConfigured` 188; the `title="OpenRouter API Key"` section starting at 197 through its closing tag)
- Modify: `frontend/src/hooks/useUserSettings.ts` (17–55: the three OpenRouter hooks; import list on line 4)
- Modify: `frontend/src/types/api.ts` (450–452; `UpdateOpenRouterSettings` at 463)
- Modify: `frontend/src/components/connections/sections/AIModelsSection.tsx` (18–24)

**Interfaces:**
- Consumes: the `GET /settings` payload without the three OpenRouter fields (Task 6).
- Produces: a Settings page with LINE, EasySlip and quiet-hours sections only.

- [ ] **Step 1: Remove the types**

In `types/api.ts` delete `openrouter_configured`, `openrouter_api_key_masked`, `openrouter_model` from `UserSettings`, and delete the whole `UpdateOpenRouterSettings` interface.

- [ ] **Step 2: Let the compiler list what is left**

Run: `npm run build`
Expected: FAIL. Every TypeScript error is a line to delete in the next steps.

- [ ] **Step 3: Remove the hooks**

In `useUserSettings.ts` delete `useUpdateOpenRouterSettings`, `useTestOpenRouterConnection`, `useClearOpenRouterKey` and their comments; remove `UpdateOpenRouterSettings` from the import on line 4, and `TestConnectionResponse` too if no remaining hook uses it.

- [ ] **Step 4: Remove the section from `SettingsPage.tsx`**

Delete: the three hook imports (26–28) and their calls (44–46); the `prevOpenRouterConfigured` state block (86–88); every form-state field and handler that only serves the OpenRouter form (the `model:` default on line 106 and its siblings); `isConfigured` (188); and the entire section component whose prop is `title="OpenRouter API Key"` from its opening tag to its matching closing tag. Remove icon and component imports that become unused (the compiler and ESLint will name them).

- [ ] **Step 5: Fix the Connections hint**

In `AIModelsSection.tsx` replace the paragraph at line 22 (the sentence telling the user to set the key on the Settings page) with:

```tsx
          <p className="mb-2">OpenRouter API Key ตั้งค่าที่ระดับระบบโดยผู้ดูแล ไม่ต้องตั้งค่าที่นี่</p>
```

- [ ] **Step 6: Verify and commit**

Run: `npm run build && npm run lint && npm run test`
Expected: all PASS.

Run: `grep -rn -i "openrouter_configured\|openrouter_api_key\|openrouter_model\|UpdateOpenRouterSettings\|settings/openrouter\|test-openrouter" frontend/src`
Expected: no output.

```bash
git add -A frontend/src
git commit -m "refactor(settings-ui): remove the OpenRouter API key section"
```

---

### Task 8: Guard test and spec corrections

**Files:**
- Create: `backend/tests/Unit/OpenRouterKeySingleSourceTest.php`
- Modify: `docs/superpowers/specs/2026-09-19-openrouter-single-key-design.md`

**Interfaces:**
- Consumes: the finished state of Tasks 1–6.
- Produces: a test that fails if the duplication returns.

- [ ] **Step 1: Write the guard test**

Create `backend/tests/Unit/OpenRouterKeySingleSourceTest.php`:

```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The OpenRouter API key has one source (OPENROUTER_API_KEY) and one reader
 * (App\Services\OpenRouterCredentials). This test keeps it that way.
 */
class OpenRouterKeySingleSourceTest extends TestCase
{
    /** @return array<string, string> relative path => contents */
    private function appFiles(): array
    {
        $root = dirname(__DIR__, 2).'/app';
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    public function test_only_openrouter_credentials_reads_the_config_key(): void
    {
        $readers = array_keys(array_filter(
            $this->appFiles(),
            fn (string $code) => str_contains($code, 'services.openrouter.api_key'),
        ));

        // ConfigHelper.php only mentions the key inside a usage docblock.
        $readers = array_values(array_diff($readers, ['Helpers/ConfigHelper.php']));

        $this->assertSame(['Services/OpenRouterCredentials.php'], $readers);
    }

    public function test_no_per_user_or_threaded_key_remains(): void
    {
        $offenders = [];
        foreach ($this->appFiles() as $path => $code) {
            foreach (['getOpenRouterApiKey', 'apiKeyOverride', 'withApiKey(', 'getApiKeyForBot'] as $needle) {
                if (str_contains($code, $needle)) {
                    $offenders[] = "{$path}: {$needle}";
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test --filter=OpenRouterKeySingleSourceTest`
Expected: PASS. If it fails, the named file still carries a key — fix that file (do not loosen the test), then re-run.

- [ ] **Step 3: Prove the guard bites**

Temporarily add `config('services.openrouter.api_key');` to the top of any method in `backend/app/Services/RAGService.php`, run the test, and confirm it FAILS naming `Services/RAGService.php`. Revert with `git checkout backend/app/Services/RAGService.php` and confirm it PASSES again.

- [ ] **Step 4: Correct the spec**

In `docs/superpowers/specs/2026-09-19-openrouter-single-key-design.md`:
- In **Architecture**, change "Its consumers are exactly the four classes that make HTTP calls to OpenRouter: `OpenRouterService`, `EmbeddingService`, `ModelCapabilityService`, `PromptEvalRunner`." to name **five** classes, adding `StreamingResponseOrchestrator` (it makes its own streaming HTTP call).
- In **Architecture**, change the class description to: "`isConfigured(): bool` and `key(): string`. `key()` throws `App\Exceptions\OpenRouterException` whose message names `OPENROUTER_API_KEY`." Add one sentence: "`isConfigured()` exists so the payment path (`OrderReconstructor`, `LLMOrderItemExtractor`) and the test/preview endpoints keep skipping gracefully when no key is configured."
- In **Problem** item 2 and in **Removal inventory** A, change `isAvailable()` to `isConfigured()` (that is the method's real name on `OpenRouterService`).

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Unit/OpenRouterKeySingleSourceTest.php docs/superpowers/specs/2026-09-19-openrouter-single-key-design.md
git commit -m "test(openrouter): guard the single key source; align the spec with the code"
```

---

### Task 9: Full gate before the PR

**Files:** none (verification only).

- [ ] **Step 1: Backend suite against `main`**

```bash
cd backend && php artisan test 2>&1 | tail -15
```

Expected: PASS. Compare the totals with a run on `origin/main`: no test that passes there fails here. A suite with skipped tests is not fully verified — report the skip count.

- [ ] **Step 2: Frontend**

```bash
cd frontend && npm run build && npm run lint && npm run test
```

Expected: all PASS.

- [ ] **Step 3: Final greps**

```bash
cd backend && grep -rn -E "getOpenRouterApiKey|apiKeyOverride|withApiKey\(|getApiKeyForBot" --include="*.php" app/ tests/
cd backend && grep -rln "services.openrouter.api_key" app/
```

Expected: the first prints nothing; the second prints `app/Services/OpenRouterCredentials.php` and `app/Helpers/ConfigHelper.php` only.

- [ ] **Step 4: Eval with the branch code**

From the repo root (needs the key from Task 0 Step 1 for this process, or — once Task 0 Step 3 is done — nothing extra, since `railway run` injects it):

```bash
railway run --service backend -- env CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array TELESCOPE_ENABLED=false \
  php backend/artisan prompt:eval --bot=26 --runs=2 --json=/tmp/eval-branch.json < /dev/null
```

Expected: no worse than Task 0 Step 5 (BM cases excepted). This exercises the refactored code against production data; it writes nothing.

---

### Task 10: PR 1 — open, merge, verify (owner-gated)

**Files:** none.

- [ ] **Step 1: Confirm the prerequisite**

```bash
railway variables --service backend --kv | cut -d= -f1 | grep -x OPENROUTER_API_KEY
```

Expected: prints `OPENROUTER_API_KEY`. **If it prints nothing, stop: merging would take every bot offline.**

- [ ] **Step 2: Push and open the PR**

```bash
git push -u origin refactor/openrouter-single-key
gh pr create --base main --title "refactor: one source and one reader for the OpenRouter API key" --body "$(cat <<'BODY'
## What
The OpenRouter API key now has one source (the OPENROUTER_API_KEY environment variable) and one reader (App\Services\OpenRouterCredentials).

- The five classes that call OpenRouter over HTTP ask OpenRouterCredentials for the key.
- The key is no longer threaded through the search, cache, RAG and streaming layers.
- The per-user key, its three endpoints and the Settings page section are removed.
- ModelCapabilityService logs an error instead of silently returning nothing when the key is missing.
- A guard test fails if any other file in app/ reads the key.

## Rollout
OPENROUTER_API_KEY is already set in Railway (prerequisite; verified before opening this PR).
This PR contains NO migration: user_settings.openrouter_api_key and openrouter_model stay in place, unread, so a revert restores the previous behaviour. A follow-up PR drops them after at least 3 stable days.

Spec: docs/superpowers/specs/2026-09-19-openrouter-single-key-design.md
Plan: docs/superpowers/plans/2026-09-19-openrouter-single-key.md

🤖 Generated with [Claude Code](https://claude.com/claude-code)
BODY
)"
```

- [ ] **Step 3: Merge (requires owner approval in this turn)**

Wait for Backend Tests and Frontend Checks to be green, then `gh pr merge --merge`. Never use `--admin`.

- [ ] **Step 4: Verify the deployment**

Railway stays BUILDING for several minutes while the old instance serves. Poll until the new code answers, then:
- send one real message to bot 26 and confirm a normal reply;
- `railway logs --service backend | grep -c "OPENROUTER_API_KEY is not set"` → expected `0`;
- `railway ssh --service backend -- sh -c 'cd /var/www/html && php artisan model:warm-cache --model=openai/gpt-5.6-luna'` → source is not `default+heuristic`.

Rollback: revert the PR. The old code finds the per-user keys again because the columns are intact.

---

### Task 11: PR 2 — drop the columns (owner-gated, at least 3 days after Task 10)

**Files:**
- Create: `backend/database/migrations/2026_09_22_000000_drop_openrouter_columns_from_user_settings_table.php` (use the real date of creation in the filename)

**Interfaces:**
- Consumes: PR 1 deployed and stable for ≥ 3 days.
- Produces: `user_settings` without `openrouter_api_key` and `openrouter_model`.

- [ ] **Step 1: Write the migration**

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
            $table->dropColumn(['openrouter_api_key', 'openrouter_model']);
        });
    }

    /**
     * Restores the columns, not their contents: the encrypted keys are gone for good.
     * They were unused once OPENROUTER_API_KEY became the single source.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->text('openrouter_api_key')->nullable();
            $table->string('openrouter_model')->default('openai/gpt-4o-mini');
        });
    }
};
```

- [ ] **Step 2: Verify locally**

Run: `php artisan test`
Expected: PASS (the suite migrates from scratch, so this proves nothing reads the columns).

- [ ] **Step 3: Test on a Neon branch before production**

Create a Neon branch of the production database, run `php artisan migrate` against it, and confirm `\d user_settings` no longer lists the two columns and that `GET /api/settings` still answers.

- [ ] **Step 4: PR, merge, verify (requires owner approval in this turn)**

Same procedure as Task 10 Steps 2–4. This is the **only irreversible step**: the three stored keys are destroyed. After deploy, confirm bot 26 still replies.
