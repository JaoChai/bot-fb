# Refactor Sprint 5 — Service Splits Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Decompose 4 oversized service/component files (2,751 LOC total) into focused units. Each split preserves behavior, improves testability, and keeps consumers unchanged via backward-compatible facades.

**Architecture:** Pattern proven by Sprint 3: split by responsibility, add a thin facade that re-exports the public API, keep complex logic verbatim, write missing tests BEFORE refactor when coverage = 0. Order chosen by safety: PaymentFlex first (has full tests), then OpenRouter/StreamController (tests-first per spec R2), FlowEditorPage last (UI smoke-testable).

**Tech Stack:** Laravel 12 + PHPUnit 11 (backend), React 19 + Vite + Vitest (frontend).

**Spec reference:** `docs/superpowers/specs/2026-05-25-refactor-initiative-roadmap.md` §4 Sprint 5.

---

## 1. Scope (this PR series)

| # | Task | Target | LOC | Has tests? | Effort |
|---|------|--------|-----|------------|--------|
| 13 | PaymentFlex split | `PaymentFlexService.php` | 1,125 | ✅ 1,073 LOC test file | ~3-4h |
| 14 | OpenRouter split | `OpenRouterService.php` | 707 | ❌ 0 tests | ~1.5d (incl. tests) |
| 15 | StreamController split | `StreamController.php` | 800 | ❌ 0 tests | ~1.5d (incl. tests) |
| 9 | FlowEditorPage split | `FlowEditorPage.tsx` | 569 | ❌ 0 tests | ~1-2d |

**Total: ~4-5 working days**

## 2. Out of scope (deferred or separate)

- ❌ Sprint 5 #10 (PluginSection → React Query) — user did not request
- ❌ Sprint 5 #16 (forms → React Hook Form) — user did not request
- ❌ Sprint 5 #17 (useEcho backoff) — user did not request
- ❌ Sprint 3 follow-ups (narrow invalidation, shared types, contract test gaps) — separate "polish" PR after Sprint 5

## 3. Execution order (safety-first)

```
Day 1   AM:   Task A — PaymentFlex extraction (existing test guard)
Day 1   PM:   Task B prep — write OpenRouter tests (gate)
Day 2:        Task B — OpenRouter split + delegate capability detection
Day 3   AM:   Task C prep — write StreamController tests (gate)
Day 3-4:      Task C — StreamController::streamTest extraction
Day 4-5:      Task D — FlowEditorPage component decomposition
```

Each task is an **independent PR**. No task depends on another. If any task surfaces unexpected complexity, the others can still ship.

## 4. Risk register (Sprint 5 specific)

| # | Risk | Severity | Mitigation |
|---|------|----------|------------|
| S1 | OpenRouter capability methods diverge from `ModelCapabilityService` (e.g. older heuristics) | 🟠 High | Tests-first writes a behavioral diff harness; spot any difference before delegating |
| S2 | `streamTest` 350-LOC method has hidden integration concerns (Sentry, SSE flush timing) | 🟠 High | Feature test that drives the endpoint end-to-end; assert SSE event count/order, not internal calls |
| S3 | `FlowEditorPage` split breaks deeply-coupled state between tabs | 🟡 Medium | Lift state to parent; pass via props; manual smoke test all 3 tabs |
| S4 | PaymentFlex test suite (1073 LOC) is brittle around extracted methods | 🟢 Low | Existing tests run BEFORE + AFTER each extraction; identical pass count required |

## 5. Pre-flight verification (already done 2026-05-26)

All 4 target file sizes confirmed match audit:
- `PaymentFlexService.php` = 1125 LOC ✅
- `OpenRouterService.php` = 707 LOC ✅
- `StreamController.php` = 800 LOC ✅
- `FlowEditorPage.tsx` = 569 LOC ✅

No drift this sprint (unlike Sprints 1-3).

---

## PART A — Task #13: PaymentFlex split (full detailed plan)

**Goal:** Split `PaymentFlexService` (1,125 LOC, 15 public methods) into 3 collaborating units: `PaymentMessageDetector` (regex + parsing), `FlexMessageBuilder` (LINE Flex JSON construction), `PaymentFlexService` (orchestrator + VIP logic).

**Why first:** Has full test coverage (`PaymentFlexServiceTest` 1,073 LOC). Existing tests are the regression guard — if any change breaks them, the diff is wrong, period.

### File Structure

| Action | File | Responsibility | LOC target |
|--------|------|----------------|-----------|
| Create | `backend/app/Services/Payment/PaymentMessageDetector.php` | 5× isXMessage(), 4× parseXData() — text/regex only | ≤400 |
| Create | `backend/app/Services/Payment/FlexMessageBuilder.php` | 5× buildXFlexMessage() — JSON construction only | ≤500 |
| Modify | `backend/app/Services/PaymentFlexService.php` | Orchestrator: tryConvertToFlex(), isVipConversation(), delegates to the two new services | ≤250 |

**Public API of `PaymentFlexService` is UNCHANGED.** All 15 methods remain callable; the body just delegates.

### Task A1: Identify the 15 method bodies + their call patterns

**Files:**
- Read: `backend/app/Services/PaymentFlexService.php`

- [ ] **Step 1: Map each method to its target file**

Run:
```bash
cd /Users/jaochai/Code/bot-fb
grep -n "public function" backend/app/Services/PaymentFlexService.php
```

Group the 15 public methods:
- **→ PaymentMessageDetector** (text parsing, no side-effects):
  - `isPaymentMessage()`, `parsePaymentData()`
  - `isSupportDelayMessage()`
  - `isConfirmMessage()`, `parseConfirmData()`
  - `isTermsMessage()`
  - `isVerifySuccessMessage()`, `parseVerifyData()`
- **→ FlexMessageBuilder** (LINE Flex JSON):
  - `buildFlexMessage()`
  - `buildSupportDelayFlexMessage()`
  - `buildConfirmFlexMessage()`
  - `buildTermsFlexMessage()`
  - `buildVerifyFlexMessage()`
- **→ Stay in PaymentFlexService** (orchestration + VIP):
  - `tryConvertToFlex()` — main entry point, calls detectors + builders
  - `isVipConversation()` — depends on `Conversation` model, not pure text

- [ ] **Step 2: Run the existing tests to capture baseline**

```bash
cd /Users/jaochai/Code/bot-fb/backend && vendor/bin/phpunit tests/Unit/Services/PaymentFlexServiceTest.php
```

Record EXACT pass count (e.g. "47 tests, 152 assertions"). This is the regression baseline.

- [ ] **Step 3: Commit nothing yet — this task is purely investigation**

No commit at this step. Task A2 begins the actual extraction.

### Task A2: Extract `PaymentMessageDetector`

**Files:**
- Create: `backend/app/Services/Payment/PaymentMessageDetector.php`
- Modify: `backend/app/Services/PaymentFlexService.php`

- [ ] **Step 1: Create the directory + new service file**

```bash
cd /Users/jaochai/Code/bot-fb && mkdir -p backend/app/Services/Payment
```

Create `backend/app/Services/Payment/PaymentMessageDetector.php`:
```php
<?php

namespace App\Services\Payment;

/**
 * Detects payment-related message types and parses their data.
 * Pure text/regex logic — no side effects, no model dependencies.
 *
 * Extracted from PaymentFlexService (2026-05-26 Sprint 5).
 */
class PaymentMessageDetector
{
    // 8 methods pasted verbatim from PaymentFlexService:
    // isPaymentMessage, parsePaymentData,
    // isSupportDelayMessage,
    // isConfirmMessage, parseConfirmData,
    // isTermsMessage,
    // isVerifySuccessMessage, parseVerifyData
    //
    // Copy each method body BYTE-IDENTICAL from PaymentFlexService.
    // Preserve all regex patterns, all constants, all helper logic.
}
```

Open `backend/app/Services/PaymentFlexService.php` and copy each of the 8 method bodies verbatim into the new file. Include any private helpers they call (e.g. regex constants) — also move those.

- [ ] **Step 2: Inject the detector into `PaymentFlexService`**

Edit `PaymentFlexService.php`. Update the constructor to receive the detector:
```php
public function __construct(
    // ...existing parameters...
    private PaymentMessageDetector $detector,
) {}
```

Replace each of the 8 method bodies with a delegation:
```php
public function isPaymentMessage(string $text): bool
{
    return $this->detector->isPaymentMessage($text);
}

public function parsePaymentData(string $text): ?array
{
    return $this->detector->parsePaymentData($text);
}
// ... and so on for the other 6 methods
```

- [ ] **Step 3: Run the test suite — must show identical pass count**

```bash
cd /Users/jaochai/Code/bot-fb/backend && vendor/bin/phpunit tests/Unit/Services/PaymentFlexServiceTest.php
```

Expected: matches the baseline from A1 Step 2. If any test fails, the verbatim copy was wrong (probably a missed constant or helper) — revert and investigate.

- [ ] **Step 4: Run Pint**

```bash
cd /Users/jaochai/Code/bot-fb/backend && vendor/bin/pint app/Services/Payment/PaymentMessageDetector.php app/Services/PaymentFlexService.php
```

- [ ] **Step 5: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add backend/app/Services/Payment/PaymentMessageDetector.php backend/app/Services/PaymentFlexService.php
git commit -m "refactor(payment-flex): extract PaymentMessageDetector

Moves 8 text-parsing methods (isXMessage, parseXData) out of the 1125-LOC
PaymentFlexService into a focused detector. Public API of PaymentFlexService
unchanged — methods now delegate. Existing PaymentFlexServiceTest still green
(identical pass count required).

Sprint 5 #13 Task A2."
```

### Task A3: Extract `FlexMessageBuilder`

**Files:**
- Create: `backend/app/Services/Payment/FlexMessageBuilder.php`
- Modify: `backend/app/Services/PaymentFlexService.php`

- [ ] **Step 1: Create the builder service**

Create `backend/app/Services/Payment/FlexMessageBuilder.php`:
```php
<?php

namespace App\Services\Payment;

/**
 * Builds LINE Flex Message JSON for payment-related responses.
 * VIP styling parameter is passed by callers — the builder itself does not
 * decide VIP status (that lives in PaymentFlexService::isVipConversation).
 *
 * Extracted from PaymentFlexService (2026-05-26 Sprint 5).
 */
class FlexMessageBuilder
{
    // 5 methods pasted verbatim from PaymentFlexService:
    // buildFlexMessage($data, $isVip)
    // buildSupportDelayFlexMessage($isVip)
    // buildConfirmFlexMessage($data, $isVip)
    // buildTermsFlexMessage()
    // buildVerifyFlexMessage($data, $isVip)
    //
    // Copy each method body BYTE-IDENTICAL. Include any VIP color/style
    // constants — also move those.
}
```

Copy the 5 builder method bodies verbatim from `PaymentFlexService.php`, including any VIP-styling constants or helper functions used.

- [ ] **Step 2: Inject builder into `PaymentFlexService`**

Update constructor:
```php
public function __construct(
    // ...existing parameters...
    private PaymentMessageDetector $detector,
    private FlexMessageBuilder $builder,
) {}
```

Replace each of the 5 build*FlexMessage method bodies with delegation:
```php
public function buildFlexMessage(array $data, bool $isVip = false): array
{
    return $this->builder->buildFlexMessage($data, $isVip);
}
// ... and so on for the other 4 builders
```

- [ ] **Step 3: Run tests — identical pass count**

```bash
cd /Users/jaochai/Code/bot-fb/backend && vendor/bin/phpunit tests/Unit/Services/PaymentFlexServiceTest.php
```

Expected: matches baseline. If any test fails, the builder copy missed a constant.

- [ ] **Step 4: Pint**

```bash
cd /Users/jaochai/Code/bot-fb/backend && vendor/bin/pint app/Services/Payment/FlexMessageBuilder.php app/Services/PaymentFlexService.php
```

- [ ] **Step 5: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add backend/app/Services/Payment/FlexMessageBuilder.php backend/app/Services/PaymentFlexService.php
git commit -m "refactor(payment-flex): extract FlexMessageBuilder

Moves 5 build*FlexMessage methods out into a focused builder. Same delegation
pattern as Task A2 — public API unchanged, existing tests still green.

Sprint 5 #13 Task A3."
```

### Task A4: Verify final shape + clean up unused imports

**Files:**
- Modify: `backend/app/Services/PaymentFlexService.php`

- [ ] **Step 1: Check the final LOC distribution**

```bash
cd /Users/jaochai/Code/bot-fb
wc -l backend/app/Services/PaymentFlexService.php backend/app/Services/Payment/*.php
```

Expected:
- `PaymentFlexService.php` ≤250 (was 1125)
- `PaymentMessageDetector.php` ≤400
- `FlexMessageBuilder.php` ≤500

- [ ] **Step 2: Clean up unused imports in `PaymentFlexService.php`**

After the 13 method bodies were replaced with delegations, many `use` imports at the top of the file may now be unused (e.g. regex constants, image manipulation helpers).

Open the file. Run `composer dump-autoload` and check Laravel Pint output — Pint flags unused imports:
```bash
cd /Users/jaochai/Code/bot-fb/backend && vendor/bin/pint --test app/Services/PaymentFlexService.php
```

If any unused imports are flagged, remove them manually.

- [ ] **Step 3: Final test suite run**

```bash
cd /Users/jaochai/Code/bot-fb/backend && vendor/bin/phpunit tests/Unit/Services/PaymentFlexServiceTest.php tests/Feature/  2>&1 | tail -10
```

Expected: PaymentFlexServiceTest matches baseline. Any related Feature tests still pass.

- [ ] **Step 4: Commit (if cleanup produced changes)**

```bash
cd /Users/jaochai/Code/bot-fb
git status --short
# If only formatting changes from Pint:
git add backend/app/Services/PaymentFlexService.php
git commit -m "style(payment-flex): drop unused imports after extraction"
```

If `git status` shows nothing, skip this commit.

### Task A5: PR + handoff to Task B

- [ ] **Step 1: Push branch + open PR**

```bash
cd /Users/jaochai/Code/bot-fb
# (Assumes branch refactor/sprint-5-service-splits — controller creates this before Task A1)
git push -u origin refactor/sprint-5-service-splits
gh pr create --base main --head refactor/sprint-5-service-splits \
  --title "refactor(sprint-5): PaymentFlex 1125→~200 LOC + 2 focused services" \
  --body "$(cat docs/superpowers/plans/2026-05-26-refactor-sprint-5-service-splits.md | head -80)"
```

- [ ] **Step 2: Wait for CI green, then merge**

After CI completes:
```bash
gh pr merge <number> --merge --delete-branch
```

- [ ] **Step 3: Sync local main**

```bash
cd /Users/jaochai/Code/bot-fb
git checkout main && git pull origin main
```

Task A complete. Proceed to Part B (OpenRouter).

---

## PART B — Task #14: OpenRouter split (strategy + task list)

**Goal:** Split `OpenRouterService` (707 LOC, 20 methods) into `OpenRouterTransport` (HTTP) + `OpenRouterResponseParser` (response shape handling). Delegate the 4 capability-detection methods to existing `ModelCapabilityService` (already has these methods).

**Why second:** No tests today (R2 risk). Tests must land before refactor. The capability-detection methods are an existing duplication that the split exposes — delegating fixes it.

### Pre-execution gate

**Write OpenRouter tests FIRST** (Task B-tests below). Refactor (Task B-split) only begins after tests are green and pass on the current monolithic service.

### Pre-flight discovery (already done 2026-05-26)

`OpenRouterService` has 4 methods that duplicate `ModelCapabilityService`:
- `supportsVision(string $model): bool`
- `supportsReasoning(string $model): bool`
- `supportsStructuredOutput(string $model): bool`
- `isMandatoryReasoning(string $model): bool`

`ModelCapabilityService` already implements all 4 with identical signatures (verified via `grep`). The split will replace OpenRouter's duplicates with a delegation pattern.

### Task list (full per-step plan written immediately before this task begins)

Inside Task #14:
- **B1: Write OpenRouter test scaffold** (~3h)
  - Create `OpenRouterServiceTest.php`
  - Test: `chat()` happy path with mocked Http facade
  - Test: `chatWithTools()` payload shape
  - Test: `chatWithVision()` image_url message format
  - Test: capability methods (4 tests, one per method) — assert SAME result as ModelCapabilityService for representative models
  - Test: `estimateCost()` math
  - Test: `isConfigured()` true/false based on env
  - **Run all on current monolithic service — must be GREEN before any refactor**

- **B2: Extract `OpenRouterResponseParser`** (~30m)
  - New file `backend/app/Services/OpenRouter/OpenRouterResponseParser.php`
  - Move response-shape handling (tool_call extraction, finish_reason interpretation, error message extraction)
  - PaymentFlex pattern: pure functions, no side effects
  - Inject into `OpenRouterService`, replace inline parsing with delegation

- **B3: Extract `OpenRouterTransport`** (~1h)
  - New file `backend/app/Services/OpenRouter/OpenRouterTransport.php`
  - Move HTTP client setup, retry logic, timeout config, headers
  - Methods: `post()`, `get()` (or `chat()`/`stream()` at a higher abstraction)
  - `OpenRouterService` constructor takes Transport + ResponseParser

- **B4: Delegate capability detection to `ModelCapabilityService`** (~30m)
  - Remove 4 capability methods from `OpenRouterService` body
  - Inject `ModelCapabilityService` into constructor
  - Replace each method body with one-line delegation
  - **B1 tests must still pass** — they assert same results across both implementations, so delegation must be behaviorally identical

- **B5: Final shape check + Pint + commit + PR**
  - `OpenRouterService.php` ≤300 LOC
  - `OpenRouterTransport.php` ≤200 LOC
  - `OpenRouterResponseParser.php` ≤150 LOC
  - Spec acceptance: each split file ≤40% of original (707 × 0.4 = 282) — orchestrator hits this, transport/parser comfortably under

### Critical constraints for Task #14

- **Do NOT change any public method signature.** All callers (RAGService, LineWebhookResponseService, ProcessLINEWebhook) currently use `OpenRouterService::chat()` and `chatWithTools()` — those entry points stay.
- **Do NOT change retry/timeout behavior.** The transport extraction preserves config exactly. PR #158 (Phase 2 #10) tuned timeout to 45s — preserve it.
- **Do NOT touch Sentry breadcrumb adds.** Existing `OpenRouterException` handling stays exact.

---

## PART C — Task #15: StreamController split (strategy + task list)

**Goal:** Extract the 350-LOC `streamTest()` method into `StreamingResponseOrchestrator`, leaving the controller as a thin HTTP/SSE adapter.

**Why third:** Same R2 risk as OpenRouter — zero tests. Tests must come first. The method ties together auth, intent analysis, RAG search, model selection, streaming — that's why it's so long. A good orchestrator will make each stage independently testable.

### Pre-flight discovery (already done 2026-05-26)

`StreamController` has 16 methods total but only 1 public (`streamTest`). 14 of the 15 protected/private methods are helpers called only by `streamTest`:
- `runDecisionModel` — intent analysis
- `runKnowledgeBaseSearch` — RAG retrieval
- `runChatModel` — final LLM call
- `streamFromOpenRouter` — SSE wire format
- 11 small helpers (auth, message building, history truncation, error response)

The 4 "stage" methods (decision/KB/chat/stream) are natural extraction boundaries.

### Task list (full per-step plan written immediately before this task begins)

Inside Task #15:
- **C1: Write StreamController feature test** (~3h)
  - Create `tests/Feature/Api/StreamControllerTest.php`
  - Drive the `/api/bots/:id/flows/:id/stream-test` endpoint end-to-end
  - Assert: SSE event types in expected order (`init`, `decision`, `knowledge_base`, `chunk` * N, `done`)
  - Assert: Auth required (401 without token)
  - Assert: 404 for missing bot/flow
  - Use Http::fake() to stub the OpenRouter calls
  - **Run on current monolithic controller — must be GREEN before refactor**

- **C2: Extract `StreamingResponseOrchestrator`** (~3h)
  - New file `backend/app/Services/Streaming/StreamingResponseOrchestrator.php`
  - Public method `run(Bot $bot, Flow $flow, string $message, callable $onSseEvent): void`
  - Body = current `streamTest()` body minus the HTTP/StreamedResponse plumbing
  - Inject all collaborators (RAGService, OpenRouterService, IntentAnalysisService)
  - The `$onSseEvent` callback abstracts the SSE write (controller passes a closure that calls `$this->sendSSE(...)`)

- **C3: Reduce `StreamController::streamTest` to ~30 LOC**
  - Authenticate + validate input
  - Instantiate the orchestrator
  - Call `orchestrator->run(...)` passing the SSE callback
  - Return the StreamedResponse

- **C4: Verify behavior parity**
  - C1 feature tests must still pass — they are the regression guard
  - Hit the endpoint manually via Chrome DevTools MCP to confirm SSE events arrive correctly

- **C5: Final shape check + Pint + commit + PR**
  - `StreamController.php` ≤400 LOC (was 800; 14 helper methods stay)
  - `StreamingResponseOrchestrator.php` ≤350 LOC

### Critical constraints for Task #15

- **SSE event order, timing, and shape must be byte-identical.** Frontend `useStreamingChat` depends on the exact event sequence. Any deviation = production breakage in the dev test panel.
- **Auth via token still required.** The orchestrator does not authenticate — that stays in the controller. Auth is a transport concern.
- **Error responses preserve current shape** (`{ event: 'error', data: {...} }`).

---

## PART D — Task #9: FlowEditorPage split (strategy + task list)

**Goal:** Decompose `FlowEditorPage.tsx` (569 LOC) into 3 subcomponents: `EditorForm` (left panel — prompt + tab content), `TabController` (active tab state + tab buttons), `ChatPanel` (right panel — test chat).

**Why last:** UI work — visual smoke testing is the primary safety net. No test coverage gap because the original file has none either. The split itself is the win (smaller files = easier to navigate + reason about).

### Pre-flight discovery (already done 2026-05-26)

`FlowEditorPage.tsx` 569 LOC structure:
- Lines 1-110: imports, constants, helpers, `MobileBottomTabs` subcomponent already extracted in same file
- Lines 113-568: the `FlowEditorPage` main component
  - 5 useState hooks (formData, hasChanges, activeEditorTab, mobileActiveTab, chatOpen)
  - Multiple useEffect hooks for data sync
  - Mobile + desktop layouts inline

### Task list (full per-step plan written immediately before this task begins)

Inside Task #9:
- **D1: Write contract test for state behavior** (~1h)
  - `frontend/src/pages/FlowEditorPage.test.tsx`
  - Render with mocked `useFlow` data
  - Assert: form initial values come from flow data
  - Assert: editor tab change updates `activeEditorTab`
  - Assert: save button disabled when `hasChanges === false`
  - Assert: mobile tab switching works
  - **Run on current monolithic file — must be GREEN before split**

- **D2: Extract `FlowEditorForm` component** (~2h)
  - New file `frontend/src/components/flow-editor/FlowEditorForm.tsx`
  - Props: `formData`, `onFormChange`, `activeTab` (state stays in parent — pass via props)
  - Body: the tab content area (lines ~345-450 of original)
  - Use lift-state-up pattern — D2 should NOT introduce new state

- **D3: Extract `FlowEditorTabs` component** (~1h)
  - New file `frontend/src/components/flow-editor/FlowEditorTabs.tsx`
  - Props: `activeTab`, `onTabChange`, `tabs: typeof EDITOR_TABS`
  - Body: tab button row UI

- **D4: Extract `FlowEditorChatPanel` component** (~1h)
  - New file `frontend/src/components/flow-editor/FlowEditorChatPanel.tsx`
  - Props: `botId`, `flowId`, `open`, `onClose`
  - Body: the right-panel chat preview (lines ~450-545 of original)

- **D5: Reduce `FlowEditorPage.tsx` to orchestration**
  - All useState hooks stay in `FlowEditorPage` (parent)
  - JSX becomes 3 subcomponent calls plus layout shell
  - Target: ≤250 LOC

- **D6: Manual smoke test via Chrome DevTools MCP**
  - Open `/flows/:id/edit` for an existing flow
  - Switch editor tabs — verify content updates
  - Edit prompt — verify "save" enables
  - Open/close chat panel — verify state persists
  - Save — verify mutation fires and toast appears

- **D7: Final shape check + commit + PR**
  - `FlowEditorPage.tsx` ≤250 LOC
  - Each subcomponent ≤200 LOC
  - Bundle size check via `npm run build` — should not regress significantly

### Critical constraints for Task #9

- **Do NOT add new state.** All useState lifted from the original stays in `FlowEditorPage`. Subcomponents take props.
- **Do NOT change the public route signature** (`/flows/:id/edit`).
- **Do NOT change the form payload shape.** `useUpdateFlow` mutation receives the same `CreateFlowData` body.

---

## 6. Common gates (every task in this sprint)

Before merging any of Tasks A/B/C/D:

- [ ] All existing tests pass (PaymentFlex baseline / new OpenRouter tests / new Stream feature test / new FlowEditor contract test)
- [ ] CI green (Backend Tests + Frontend Checks)
- [ ] Pint + ESLint clean on touched files
- [ ] No public API signature changed (caller-side smoke test)
- [ ] Decision logged in master roadmap §2 if any deviation from this plan

## 7. Rollback strategy

Each task is a separate PR. Revert per-PR if regression appears:

| Task | Rollback impact |
|------|----------------|
| A (PaymentFlex) | Revert PR — extracted files removed, monolithic service restored. No DB / API changes to undo. |
| B (OpenRouter) | Revert PR — capability delegation reverts to internal methods. Transport/Parser files removed. |
| C (StreamController) | Revert PR — Orchestrator removed; streamTest() body restored. SSE behavior unchanged either way. |
| D (FlowEditorPage) | Revert PR — subcomponents removed; monolithic component restored. UI smoke-test only. |

## 8. Definition of Done (Sprint 5 closure)

- [ ] All 4 PRs (A/B/C/D) merged to main
- [ ] Production deploy succeeded (all 4 services + frontend bundle)
- [ ] 24h Sentry watch: zero new error classes on any of PaymentFlex / OpenRouter / Stream / FlowEditor paths
- [ ] Master roadmap §4 Sprint 5 section updated with result (per-task LOC delta, test count delta, any deviations)
- [ ] Sprint 5 GO decision logged for next sprint (or initiative closure)

---

## 9. Per-task plan elaboration

**Part A (Task #13)** is fully detailed above. Tasks A1-A5 are ready to execute.

**Parts B/C/D** have strategic outlines only. Full per-step plans will be written immediately before each task starts (just-in-time, same pattern as the master roadmap §9). This avoids:
- Plan staleness (codebase will drift slightly between tasks)
- Premature commitment to test designs that should reflect what we learn from Part A
- A 4000-line plan that's hard to navigate

When ready to execute Task #14 (B), the controller should write a `2026-XX-XX-refactor-sprint-5-task-14-openrouter.md` plan using this section as the strategic frame.
