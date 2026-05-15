# Unit 7: Code Quality Scanner

> Data sources: `find` + `wc -l` (file sizes), `grep` method counts, `npm run lint` (ESLint), `vendor/bin/pint --test` (BLOCKED — PHP 8.5.2 / composer conflict). Snapshot: 2026-05-15.

## Backend — Largest Files (top 35)

| LOC | File | Methods (top-10 only) |
|-----|------|-----------------------|
| 1432 | backend/app/Jobs/ProcessLINEWebhook.php | 19 |
| 1125 | backend/app/Services/PaymentFlexService.php | 17 |
| 1076 | backend/app/Services/RAGService.php | 27 |
| 805 | backend/app/Http/Controllers/Api/StreamController.php | 15 |
| 794 | backend/app/Http/Controllers/Api/FlowController.php | 17 |
| 721 | backend/app/Jobs/ProcessFacebookWebhook.php | 16 |
| 698 | backend/app/Services/OpenRouterService.php | 20 |
| 662 | backend/app/Http/Controllers/Api/BotController.php | 15 |
| 657 | backend/app/Services/FacebookService.php | 32 |
| 648 | backend/app/Services/TelegramService.php | 22 |
| 625 | backend/app/Services/ModelCapabilityService.php | — |
| 560 | backend/app/Services/LINEService.php | — |
| 554 | backend/app/Jobs/ProcessTelegramWebhook.php | — |
| 493 | backend/app/Services/IntentAnalysisService.php | — |
| 467 | backend/app/Services/LeadRecoveryService.php | — |
| 459 | backend/app/Jobs/ProcessAggregatedMessages.php | — |
| 419 | backend/app/Services/FlowPluginService.php | — |
| 390 | backend/app/Services/StockGuardService.php | — |
| 383 | backend/app/Services/HybridSearchService.php | — |
| 378 | backend/app/Console/Commands/BackfillOrdersFromMessages.php | — |
| 377 | backend/app/Http/Controllers/Api/OrderController.php | — |
| 376 | backend/app/Services/CircuitBreakerService.php | — |
| 348 | backend/app/Services/CostTrackingService.php | — |
| 344 | backend/app/Services/ContextualRetrievalService.php | — |
| 342 | backend/app/Services/SemanticCacheService.php | — |
| 304 | backend/app/Http/Controllers/Api/LeadRecoveryController.php | — |
| 302 | backend/app/Http/Controllers/Api/AnalyticsController.php | — |
| 290 | backend/app/Services/MessageAggregationService.php | — |
| 262 | backend/app/Services/QueryEnhancementService.php | — |
| 261 | backend/app/Jobs/ProcessDocument.php | — |
| 258 | backend/app/Services/AIService.php | — |
| 252 | backend/app/Http/Controllers/Api/HealthController.php | — |
| 252 | backend/app/Http/Controllers/Api/BotSettingController.php | — |
| 242 | backend/app/Console/Commands/RefreshLineProfilePictures.php | — |
| 241 | backend/app/Http/Controllers/Api/DashboardController.php | — |

Files > 500 LOC in `app/Services/`: **7** (PaymentFlexService, RAGService, OpenRouterService, FacebookService, TelegramService, ModelCapabilityService, LINEService)

## Frontend — Largest Files (top 35)

| LOC | File |
|-----|------|
| 861 | frontend/src/hooks/useConversations.ts |
| 721 | frontend/src/types/api.ts |
| 619 | frontend/src/components/analytics/OrdersAnalytics.tsx |
| 569 | frontend/src/pages/FlowEditorPage.tsx |
| 516 | frontend/src/components/flow/PluginSection.tsx |
| 513 | frontend/src/pages/settings/QuickRepliesPage.tsx |
| 438 | frontend/src/components/ProcessDisplay.tsx |
| 409 | frontend/src/pages/BotSettingsPage.tsx |
| 403 | frontend/src/hooks/useStreamingChat.ts |
| 374 | frontend/src/hooks/useFlows.ts |
| 368 | frontend/src/pages/EditConnectionPage.tsx |
| 364 | frontend/src/components/telegram/TelegramMessageBubble.tsx |
| 357 | frontend/src/pages/KnowledgeBasePage.tsx |
| 353 | frontend/src/pages/TeamPage.tsx |
| 351 | frontend/src/pages/VipManagementPage.tsx |
| 311 | frontend/src/pages/BotsPage.tsx |
| 309 | frontend/src/hooks/chat/useRealtime.ts |
| 308 | frontend/src/components/flow-editor/tabs/PromptTab.tsx |
| 305 | frontend/src/hooks/chat/useConversationDetails.ts |
| 294 | frontend/src/lib/stream.ts |
| 290 | frontend/src/components/knowledge-base/SemanticSearch.tsx |
| 285 | frontend/src/components/line/LINEMessageBubble.tsx |
| 273 | frontend/src/hooks/useKnowledgeBase.ts |
| 271 | frontend/src/pages/ChatPage.tsx |
| 255 | frontend/src/components/ui/dropdown-menu.tsx |
| 255 | frontend/src/components/chat/MessageBubble.tsx |
| 248 | frontend/src/components/layout/Sidebar.tsx |
| 241 | frontend/src/components/flows/KnowledgeBaseSelector.tsx |
| 238 | frontend/src/hooks/useEcho.ts |
| 236 | frontend/src/components/bot-settings/BehaviorTab.tsx |
| 235 | frontend/src/components/conversation/NotesPanel.tsx |
| 235 | frontend/src/components/chat/adapters/TelegramMessageRenderers.tsx |
| 235 | frontend/src/components/chat/adapters/FacebookAdapter.tsx |
| 233 | frontend/src/pages/SettingsPage.tsx |
| 228 | frontend/src/hooks/chat/useNotes.ts |

## Backend Method Counts (top 10 biggest only)

| LOC | Methods | File |
|-----|---------|------|
| 1432 | 19 | backend/app/Jobs/ProcessLINEWebhook.php |
| 1125 | 17 | backend/app/Services/PaymentFlexService.php |
| 1076 | 27 | backend/app/Services/RAGService.php |
| 805 | 15 | backend/app/Http/Controllers/Api/StreamController.php |
| 794 | 17 | backend/app/Http/Controllers/Api/FlowController.php |
| 721 | 16 | backend/app/Jobs/ProcessFacebookWebhook.php |
| 698 | 20 | backend/app/Services/OpenRouterService.php |
| 662 | 15 | backend/app/Http/Controllers/Api/BotController.php |
| 657 | 32 | backend/app/Services/FacebookService.php |
| 648 | 22 | backend/app/Services/TelegramService.php |

Note: `FacebookService` has 32 methods at 657 LOC (densest); `RAGService` has 27 methods at 1076 LOC (heaviest service class).

## Pint Style Check

- **Status: BLOCKED** — `composer install` failed due to `brianium/paratest v7.8.4` requiring PHP ~8.2–8.4; worktree has PHP 8.5.2. `vendor/bin/pint` not available.
- **Workaround:** CI (GitHub Actions) runs pint on PHP 8.3; last known CI run passed (see branch protection on `main`). No invented numbers.
- Files with confirmed issues: **N/A (blocked)**
- Top patterns: **N/A (blocked)**

## ESLint Results

- **Errors: 0**
- **Warnings: 33** (across 13 files)
- **Summary:** `✖ 33 problems (0 errors, 33 warnings)`

### Warning Breakdown by Rule

| Rule | Count |
|------|-------|
| react-hooks/refs | 10 |
| react-hooks/set-state-in-effect | 9 |
| react-hooks/preserve-manual-memoization | 8 |
| react-hooks/exhaustive-deps | 5 |
| react-hooks/incompatible-library | 1 |

### Affected Files (13 files)

| File |
|------|
| frontend/src/components/chat/MessageList.tsx |
| frontend/src/components/chat/QuickReplyAutocomplete.tsx |
| frontend/src/hooks/useChannelInfo.ts |
| frontend/src/hooks/useConnectionForm.ts |
| frontend/src/hooks/useCountdown.ts |
| frontend/src/hooks/useEcho.ts |
| frontend/src/pages/BotSettingsPage.tsx |
| frontend/src/pages/BotsPage.tsx |
| frontend/src/pages/ChatPage.tsx |
| frontend/src/pages/FlowEditorPage.tsx |
| frontend/src/pages/settings/QuickRepliesPage.tsx |
| frontend/src/pages/SettingsPage.tsx |
| frontend/src/pages/VipManagementPage.tsx |

Root cause: React Compiler (enabled in this project) infers `setState` setters as reactive dependencies but manual `useCallback([])` empty-dep arrays do not list them — compiler skips optimizing those components.

## High-Coupling Services (top 15 by `use App\` + `use Illuminate\` imports)

| Imports | File |
|---------|------|
| 7 | backend/app/Services/VipDetectionService.php |
| 7 | backend/app/Services/MessageAggregationService.php |
| 7 | backend/app/Services/FlowPluginService.php |
| 6 | backend/app/Services/TelegramService.php |
| 6 | backend/app/Services/OrderService.php |
| 6 | backend/app/Services/LINEService.php |
| 6 | backend/app/Services/FacebookService.php |
| 6 | backend/app/Services/AIService.php |
| 5 | backend/app/Services/LeadRecoveryService.php |
| 5 | backend/app/Services/AutoAssignmentService.php |
| 4 | backend/app/Services/SemanticCacheService.php |
| 4 | backend/app/Services/RateLimitService.php |
| 4 | backend/app/Services/RAGService.php |
| 4 | backend/app/Services/OpenRouterService.php |
| 4 | backend/app/Services/MultipleBubblesService.php |

### Job Service Coupling (App\Services imports in Jobs)

| App\Services imports | Job |
|---------------------|-----|
| 16 | backend/app/Jobs/ProcessLINEWebhook.php |
| 5 | backend/app/Jobs/ProcessTelegramWebhook.php |
| 5 | backend/app/Jobs/ProcessFacebookWebhook.php |
| 5 | backend/app/Jobs/ProcessAggregatedMessages.php |
| 4 | backend/app/Jobs/ProcessDocument.php |
| 1 | backend/app/Jobs/SendDelayedBubbleJob.php |
| 1 | backend/app/Jobs/ProcessLeadRecovery.php |
| 1 | backend/app/Jobs/ExtractEntitiesJob.php |
| 1 | backend/app/Jobs/EvaluateVipStatusJob.php |

## Findings

### Finding 1: ProcessLINEWebhook is a 1432-LOC god-job with 16 service dependencies
- **Evidence:** `backend/app/Jobs/ProcessLINEWebhook.php` — 1432 LOC, 19 methods, imports 16 `App\Services` classes
- **Impact:** Single file owns the entire LINE message-processing pipeline. Any change risks cascading breakage; test surface is enormous; P95 latency for LINE webhooks (Unit 1) maps directly to this class. Impossible to unit-test individual steps in isolation.
- **Root cause hypothesis:** Incremental feature addition (RAG, VIP detection, stock guard, lead recovery, payment flex) was bolted onto a single job handler rather than extracted into a pipeline/chain pattern.
- **Fix candidates:**
  1. Extract pipeline steps into `ProcessLINEWebhook::handle()` delegating to a `WebhookPipeline` class with discrete stages — effort: 2d, risk: medium (requires integration tests)
  2. Split into chained jobs: `ParseLINEMessage → EnrichWithRAG → RunPlugins → SendReply` — effort: 3d, risk: medium-high (queue ordering, retry semantics)
  3. Extract only the largest sub-methods (payment detection, stock guard) into dedicated handlers first — effort: 0.5d, risk: low (surgical)

### Finding 2: RAGService at 1076 LOC / 27 methods — highest method density in Services
- **Evidence:** `backend/app/Services/RAGService.php` — 1076 LOC, 27 methods
- **Impact:** RAGService is the core AI orchestration class. At 27 methods it covers retrieval, reranking, context assembly, cache interaction, fallback logic, and streaming. This is the hardest class to test and the most likely to introduce regressions on prompt/model changes. Correlates with Unit 1 latency: any slow path in RAGService is a slow endpoint.
- **Root cause hypothesis:** RAG pipeline grew from simple embedding lookup to multi-stage hybrid search (HybridSearchService, ContextualRetrievalService, SemanticCacheService exist but RAGService still owns orchestration glue).
- **Fix candidates:**
  1. Extract `RetrievalPipeline` class handling stages 1–3 (embed → hybrid search → rerank) — effort: 1.5d, risk: low-medium
  2. Move streaming/SSE logic to `StreamController` (already 805 LOC but owns that concern) — effort: 0.5d, risk: low
  3. Extract `ContextAssembler` class (context window building, token counting) — effort: 1d, risk: low

### Finding 3: FacebookService has 32 methods at 657 LOC — highest method count in all Services
- **Evidence:** `backend/app/Services/FacebookService.php` — 657 LOC, 32 methods (avg 20 LOC/method)
- **Impact:** 32 methods in one class violates single-responsibility at the class level. Short methods suggest handler dispatch logic that could be a strategy/registry pattern. Also correlates with `ProcessFacebookWebhook.php` at 721 LOC / 5 service imports.
- **Root cause hypothesis:** Facebook message types (text, quick_reply, postback, attachment, location, sticker, …) each need their own send/parse logic; all landed in one service class.
- **Fix candidates:**
  1. Registry pattern: `FacebookMessageHandlers[]` keyed by message type, each a small class — effort: 1d, risk: low
  2. Split into `FacebookSenderService` (outbound) + `FacebookParserService` (inbound) — effort: 0.5d, risk: low

### Finding 4: 33 ESLint warnings from React Compiler incompatibility with manual useCallback deps
- **Evidence:** `npm run lint` — 33 warnings, 0 errors, 13 files affected. Top rules: `react-hooks/refs` (10), `react-hooks/set-state-in-effect` (9), `react-hooks/preserve-manual-memoization` (8).
- **Impact:** React Compiler skips optimization for all affected components — they run without compiler memoization, meaning re-renders are not eliminated. Affects high-traffic components: `ChatPage.tsx`, `FlowEditorPage.tsx`, `BotSettingsPage.tsx`, `MessageList.tsx`. Correlates with Unit 4 frontend INP regressions.
- **Root cause hypothesis:** Project upgraded to React 19 + React Compiler but legacy `useCallback(fn, [])` patterns with empty dep arrays conflict with compiler's inferred `setState` setter dependencies.
- **Fix candidates:**
  1. Remove `useCallback` wrappers where React Compiler can handle memoization automatically — effort: 0.5d, risk: low (Compiler handles it)
  2. Add `setState` setters to dep arrays where manual memoization is intentional — effort: 0.5d, risk: low
  3. Audit with `react-compiler-healthcheck` to prioritize highest-impact components first — effort: 0.5d, risk: none

### Finding 5: 7 Service files exceed 500 LOC — well above 🟢 threshold
- **Evidence:** PaymentFlexService (1125), RAGService (1076), OpenRouterService (698), FacebookService (657), TelegramService (648), ModelCapabilityService (625), LINEService (560)
- **Impact:** All 7 are in `app/Services/` — the threshold for 🟢 is 0 files > 500 LOC. This signals broad architectural debt across the AI, messaging, and payment layers simultaneously.
- **Root cause hypothesis:** Services grew organically as features were added; no enforced size budget in CI.
- **Fix candidates:**
  1. Add `find app/Services -name "*.php" | xargs wc -l | awk '$1>500'` as a CI warning step — effort: 0.25d, risk: none
  2. Prioritize extraction of RAGService + PaymentFlexService (highest LOC + highest business criticality) — effort: 3d combined, risk: medium
  3. ModelCapabilityService (625 LOC) is likely pure data — convert to config-driven approach (already have `config/llm-models.php`) — effort: 0.5d, risk: low

## Status: 🔴

Thresholds:
- 0 files > 500 LOC in `app/Services/` + < 10 lint errors + 0 pint issues = 🟢
- ≤ 3 big files OR < 50 lint warnings = 🟡
- > 3 big files OR ≥ 50 lint issues = 🔴

Current: **7 files > 500 LOC in `app/Services/`** (threshold: 0), **33 ESLint warnings** (0 errors), **Pint: BLOCKED**. Status **🔴** (Service file count alone triggers red; ESLint warnings at 33 are below 50 but the 13-file spread is notable).
