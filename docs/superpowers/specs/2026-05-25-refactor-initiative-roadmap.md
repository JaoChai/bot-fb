# Refactor Initiative Roadmap (Quick Wins First)

**Date:** 2026-05-25
**Owner:** Claude + jaochai (pair on critical gates)
**Status:** Spec — pending user review
**Approach:** A (Quick Wins First) — selected after agent-team analysis

---

## 1. Executive Summary

Multi-sprint refactor initiative targeting **18 candidates** ranked by impact ÷ effort, decomposed into **5 sprints (~3-4 weeks total)**. Driven by 4 parallel agent audits (backend, frontend, cross-cutting, DB) executed on 2026-05-25.

**Strategy:** start with zero-risk DB and perf quick wins (~1 day), then biggest structural win (`ProcessLINEWebhook` pipeline using existing PR #165 spec), then frontend, then channel consolidation (gated by writing tests for Facebook/Telegram first), then cleanup.

**Key outcomes targeted:**
- ProcessLINEWebhook: 1517 → ≤400 LOC (split into existing `LineWebhook/` services)
- Facebook/Telegram webhook deduplication: -600 LOC across 3 jobs
- RAGService: 1076 → 4 focused services
- Frontend `useConversations.ts`: 834 → domain-grouped hooks ≤200 LOC each
- LCP improvement target: -800ms on top 3 routes (Phase 2 #3 carried forward)
- Test coverage: Facebook/Telegram webhook 0% → ≥60% on happy path; OpenRouter 0% → covered
- Sentry latency p95 on webhook path: ≥5% reduction (Sprint 1 baseline)

---

## 2. Decision Log

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | Approach A (Quick Wins First) | User selected; high ROI, low risk early, keeps momentum |
| D2 | 3-channel webhook → CONSOLIDATE (override agent #3 recommendation) | `wc -l` verified FB=721 / TG=554 (not 180/210 as agent #3 claimed). Duplication is real |
| D3 | RAGService split into 4 services | Backend agent recommendation matches the 9 public methods grouping by concern |
| D4 | useConversations: domain-grouped hooks, **reuse existing `useMutationWithToast`** | Discovery: `frontend/src/hooks/useMutationWithToast.ts` already supports `invalidateKeys` + toast — Context7 confirms idiomatic React Query v5 pattern. No new abstraction needed |
| D5 | Phase 2 perf backlog (#11-15) NOT in scope | Has its own plan (`2026-05-15-perf-audit-design.md`); avoid scope tangle |
| D6 | `LineWebhookPipelineFlag` pattern → copy per channel | Proven in PR #163; do not reinvent |
| D7 | `types/api.ts` split → DROP from scope | Hand-written (no codegen marker) but 40+ importers; split is risky with low payoff |
| D8 | Master roadmap + 5 sprint specs (not 1 mega-spec) | 18 items too large for single spec; per-sprint specs are reviewable units |
| **D11** | **Sprint 2 CLOSED early (2026-05-25)** | User clarified only 1 active LINE bot (bot 26) serves customers; other bots not actively in use. Step 2 (bot 28 promotion) and Step 3 (full enable) have no business value with single-bot operation. Pipeline stays on bot 26 as-is. Runbook kept for future re-evaluation when a second bot becomes active. |
| **D10** | **Sprint 2 RE-SCOPED from "implement" to "operate"** | Pre-flight drift check (Explore agent, 2026-05-25) confirmed that the code refactor proposed in Sprint 2 was already shipped as PR #163 on 2026-05-16, and the pipeline has been dark-launched on bot 26 for 9 days. PR #165 was a *post-hoc* documentation plan, not pending work. Sprint 2 is now an operations sprint covering the promotion sequence (bot 26 → bot 28 → all). Runbook: `docs/superpowers/runbooks/2026-05-25-line-pipeline-promotion.md`. Lesson: when an existing plan exists in `docs/superpowers/plans/`, treat it as historical record until verified against production state. |
| **D9** | **Sprint 1 #1 (composite index) DROPPED** after Code Quality review caught duplicate of existing `messages_webhook_event_id_idx` (UNIQUE, same columns, same partial WHERE — created in migration `2026_01_15_210000_add_line_event_tracking_to_messages.php`) | DB audit agent missed the existing UNIQUE index when reading `pg_indexes`. Independent observation: lines 279 and 797 of `ProcessLINEWebhook.php` query `webhook_event_id` WITHOUT `conversation_id` — neither the existing index nor the proposed new one helps these sites optimally. **Follow-up needed (NOT in this sprint):** investigate adding `conversation_id` filter at those call sites OR add a single-column index on `webhook_event_id`. Reverted commit: `60a6aa7` (revert: `4b6e187`) |
| **D12** | **Sprint 5 Task A (PaymentFlex split) FlexMessageBuilder = 771 LOC, exceeds plan's ≤500 LOC target by 271** | Plan's verbatim-copy rule (Step 1 of A3: "BYTE-IDENTICAL" copy) takes precedence over LOC budget. The 5 `build*FlexMessage` methods total ~687 lines of pure body (LINE Flex JSON is verbose). Modifying bodies to compress is out-of-scope (would invalidate the regression-via-existing-tests safety net). Reviewer noted ~100 LOC of polish potential (header bubble + info box helpers) deferred to future sprint. **PaymentFlexService.php** went 1125 → 261 (target ≤250, over by 11 — overage is delegation docblocks). All 68 unit tests + 133 feature tests stay green. |
| **D13** | **Sprint 5 Task B premise "OpenRouterService has 0 tests" was WRONG — drift discovery #4** | Pre-flight (2026-05-26) found `OpenRouterServiceTest.php` already exists with 19 test methods / 47 assertions, all green. Covers: chat happy/fallback/failure paths, chatSimple, generateBotResponse, estimateCost, listModels, getModel, buildVisionMessages. **Missing coverage:** chatWithTools payload shape + 4 capability methods. Re-scope: ADD those 5 missing tests instead of writing full suite from scratch. |
| **D14** | **Sprint 5 Task B premise "capability methods need delegation" was WRONG — drift discovery #5** | The 4 capability methods (supportsVision/supportsReasoning/supportsStructuredOutput/isMandatoryReasoning) are ALREADY one-line delegations to `ModelCapabilityService` — using `app(ModelCapabilityService::class)->X($model)` service-locator pattern. The "delegate the duplicates" framing in the plan was outdated. Re-scope: B4 cleanup = convert service-locator to constructor injection (anti-pattern cleanup, ~4 lines). B2 (parser ~50 LOC) + B3 (transport ~75 LOC) extractions yield modest value and are deferred — splitting them won't bring OpenRouterService under the ≤300 LOC target (chat methods are the bulk; they're core orchestration logic, not extraction candidates). |

---

## 3. Architecture Sketches

### 3.1 Webhook Consolidation (`#4`) — copy LINE pattern to FB/TG

LINE already has the pattern. Extract minimum shared base, mirror per channel.

```
backend/app/Services/Webhook/                  ← NEW shared layer
  Contracts/
    WebhookContext.php                         ← value object (mirror LineWebhook/WebhookContext)
    GateDecision.php
    ResponseEnvelope.php
  Pipeline/
    WebhookProcessorBase.php                   ← validate → context → gate → respond → output
    CircuitBreakerStage.php
    PipelineFlag.php                           ← per-channel feature flag
  Stages/                                      ← interfaces only
    ContextResolver.php
    Gater.php
    Responder.php
    Outputter.php

backend/app/Services/LineWebhook/              ← EXISTING (adapt to implement interfaces)
backend/app/Services/FacebookWebhook/          ← NEW (mirror LINE structure)
backend/app/Services/TelegramWebhook/          ← NEW (mirror LINE structure)
```

**Job changes:**

| File | Before | After | Notes |
|------|--------|-------|-------|
| `ProcessLINEWebhook.php` | 1517 | ~400 | Sprint 2 (using PR #165 plan) |
| `ProcessFacebookWebhook.php` | 721 | ~250 | Sprint 4 — extends `WebhookProcessorBase` |
| `ProcessTelegramWebhook.php` | 554 | ~200 | Sprint 4 — extends `WebhookProcessorBase` |

**Critical rule:** LINE-specific logic (smart aggregation, `ORDER_CONTEXT_KEYWORDS`) stays in `LineWebhookContextService` only. Base must NOT leak channel-specific code.

### 3.2 RAGService Split (`#7`) — 9 methods → 4 services

```
backend/app/Services/RAG/                      ← NEW directory
  RAGService.php (orchestrator, ~150 LOC)
    └── generateResponse()                     ← entry point, backward-compatible
  RAGIntentAnalyzer.php (~200 LOC)
    ├── detectComplexity()
    └── detectToolIntent()
  RAGKnowledgeBaseService.php (~300 LOC)
    ├── getFlowKnowledgeBaseContext()
    ├── flowHasKnowledgeBases()
    └── searchKnowledgeBases()
  RAGResponseFormatter.php (~200 LOC)
    ├── formatKnowledgeBaseContext()
    └── injectStockStatus()                    ← STOCK MANAGEMENT — regression risk; needs test
  RAGTester.php (~80 LOC)
    └── testRAG()                               ← admin/dev-only path
```

**Backward compat:** `RAGService::generateResponse()` keeps its public signature. External callers do not change.

### 3.3 useConversations Split (`#8`) — 21 hooks → domain files, reuse `useMutationWithToast`

Existing `useMutationWithToast` already provides toast + `invalidateKeys`. Migrate inline mutations to use it.

```
frontend/src/hooks/conversations/              ← NEW directory
  index.ts                                     ← re-export everything; callers keep current imports
  useConversationQueries.ts (~200 LOC)
    ├── useConversations
    ├── useInfiniteConversations
    ├── useConversation
    ├── useConversationMessages
    └── useConversationStats
  useConversationLifecycle.ts (~150 LOC)
    ├── useUpdateConversation         } use useMutationWithToast
    ├── useCloseConversation          }   with invalidateKeys
    ├── useReopenConversation         }
    └── useToggleHandover             }
  useConversationRead.ts (~150 LOC)
    ├── useMarkAsRead                          ← keep manual onMutate (optimistic update needs custom rollback)
    ├── useClearContext
    └── useClearContextAll
  useConversationNotes.ts (~100 LOC)
  useConversationTags.ts (~120 LOC)
  useSendAgentMessage.ts (~50 LOC)
```

Old `frontend/src/hooks/useConversations.ts` becomes a re-export shim from `./conversations/index.ts`. Callers do not need import path changes in Sprint 3 (deprecate path next sprint).

---

## 4. Sprint Sequencing

### Sprint 1 — Foundation Quick Wins (~1 day, risk: 🟢 low)
Scope: 4 zero-risk DB/perf items.

| # | Task | Effort | Source |
|---|------|--------|--------|
| 1 | Add composite index `messages(conversation_id, webhook_event_id)` via `CREATE INDEX CONCURRENTLY` | 10m | DB agent |
| 2 | Coalesce duplicate `Message::where(conversation_id, webhook_event_id)` queries in `ProcessLINEWebhook` (lines 367, 373, 397, 402) | 30m | DB agent |
| 11 | Add `->with('bot')` eager load in `LeadRecoveryService::findEligibleConversations()` (line 43) | 5m | DB agent |
| 12 | `VACUUM FULL` on `bots` table (52% dead tuples) | 15m | Perf agent |

**Acceptance:** ProcessLINEWebhook dedup query plan shifts Seq Scan → Index Scan (verified via `EXPLAIN`); Sentry p95 latency on LINE webhook handler drops ≥5% within 24h; `bots` table dead tuple ratio <10%; zero new error classes in 24h Sentry watch.

**Rollback:** `DROP INDEX CONCURRENTLY` for index; revert eager-load and dedup PRs (all non-destructive).

**Tooling:** `safe-migration` skill, `database-migration` agent.

### Sprint 2 — LINE Webhook Pipeline Promotion (operations sprint, ~3 days elapsed time)

**🟡 Re-scoped on 2026-05-25 after pre-flight drift check (decision D10 below).** Code refactor for this sprint was already merged as PR #163 on 2026-05-16; the pipeline has been dark-launched on bot 26 since then. This sprint operates the rollout sequence, not the refactor.

### ✅ Sprint 2 CLOSED (2026-05-25)

User confirmed only 1 active LINE bot (bot 26). Steps 2 and 3 have no business value. Pipeline remains on bot 26 as the steady operational state.

| Step | Task | Status |
|---|------|--------|
| 1 | Bot 26 dark-launch | ✅ DONE — live since 2026-05-16, 9+ days clean |
| 2 | Promote bot 28 | ⏸ DEFERRED — re-evaluate when a second bot becomes active |
| 3 | Full enable | ⏸ DEFERRED — re-evaluate when ≥2 active LINE bots exist |

**Re-open trigger:** A second LINE bot starts serving customers → run the promotion runbook at `docs/superpowers/runbooks/2026-05-25-line-pipeline-promotion.md` to add it to `PROCESS_LINE_PIPELINE_BOT_IDS`.

**Operational state recorded:**
- `PROCESS_LINE_PIPELINE_ENABLED=true`
- `PROCESS_LINE_PIPELINE_BOT_IDS=26`
- Railway log scan 24h (2026-05-25): no 5xx, no PHP stderr errors, bot 26 replying normally
- Sentry-side verification deferred (no Sentry MCP) — operator can confirm later if needed

**Explicitly out of scope (deferred future work):**
- Non-text event coverage in pipeline (sticker/image still on legacy path)
- Removing legacy `processEvent()`
- Removing the feature flag

### Sprint 3 — Frontend Quick Wins (~2-3 days, risk: 🟡 medium)
Scope: bundle/LCP win and largest hook split.

| # | Task | Effort | Notes |
|---|------|--------|-------|
| 5 | Frontend code splitting for top 3 routes (Phase 2 #3 from perf audit) | 1d | `lazyWithRetryNamed` + `ChunkErrorBoundary` already implemented |
| 8 | Split `useConversations.ts` (834 LOC) into `hooks/conversations/*` domain files; migrate to `useMutationWithToast` where applicable | 1-2d | See §3.3 |

**Before split:** write 5-7 contract tests covering optimistic update path (`useMarkAsRead`), cache invalidation chain (`useCloseConversation`, `useToggleHandover`), and infinite-query pagination.

**Acceptance:** Lighthouse LCP -≥800ms on ChatPage, FlowEditorPage, BotsPage (measured via Chrome DevTools MCP); main bundle chunk -≥15%; each new hook file ≤200 LOC; existing manual smoke flow (receive message, handover, mark read) passes.

**Rollback:** Revert PRs (no schema/state changes).

**Tooling:** `frontend-design` skill if component reshape needed, `qa-tester` agent for Vitest scaffolding.

### Sprint 4 — Channel Consolidation + RAG Split (~5-7 days, risk: 🔴 high)
**Test scaffolding is a blocker.** No refactor begins until tests are green.

| # | Task | Effort | Order |
|---|------|--------|-------|
| 6a | Write `ProcessFacebookWebhookTest` (copy `ProcessLINEWebhookPipelineTest` structure, adapt) | 1d | **First** |
| 6b | Write `ProcessTelegramWebhookTest` | 1d | **Second** |
| 4 | Extract `WebhookProcessorBase` + Contracts/Stages; mirror `FacebookWebhook/` and `TelegramWebhook/` directories; port jobs to extend base | 2-3d | **After 6a+6b green** |
| 7 | Split `RAGService` into 4 services per §3.2 | 2d | Parallel to #4 |

**Acceptance:** `ProcessFacebookWebhook.php` ≤300 LOC; `ProcessTelegramWebhook.php` ≤250 LOC; RAGService split files each ≤300 LOC; no LINE-specific code in `Webhook/Pipeline/`; stock management regression test passes (`RAGResponseFormatter::injectStockStatus()`); zero new error class on `Jobs/Process{FB,TG}Webhook*` over 48h Sentry watch.

**Rollback:** Per-channel feature flag (mirror `LineWebhookPipelineFlag`); RAGService split is commit-by-commit reversible.

**Tooling:** `qa-tester` agent for tests; `backend-dev` agent for refactor.

### Sprint 5 — Frontend Pages + Service Cleanup (~3-5 days, risk: 🟡 medium)
Scope: remaining P1/P2 cleanups.

| # | Task | Effort |
|---|------|--------|
| 9 | Split `FlowEditorPage.tsx` (569 LOC) into EditorForm + TabController + ChatPanel subcomponents | 1-2d |
| 10 | Migrate `PluginSection.tsx` from direct `apiGet/Post/Put/Delete` to React Query hooks | 1d |
| 13 | Split `PaymentFlexService` (1125 LOC) → `FlexMessageBuilder` + `PaymentMessageDetector` | 2-3h |
| 14 | Split `OpenRouterService` (707 LOC) → `OpenRouterTransport` + `OpenRouterResponseParser` (capability detection delegated to existing `ModelCapabilityService`) | 1d |
| 15 | Split `StreamController::streamTest()` (~350 LOC) into `StreamingResponseOrchestrator` | 1d |
| 16 | Standardize non-auth forms on React Hook Form (audit list compiled at sprint start) | 1-2d |
| 17 | Add reconnection backoff to `useEcho.ts` | 0.5d |

**Acceptance:** Each split file ≤40% of original; React Query consistency in `PluginSection`; OpenRouter `chat()` + retry covered by test; StreamController feature test 1-2 added; no regression in Sentry over 24h.

**Rollback:** Per-commit reversion (cleanup work is low-coupling).

**Tooling:** `refactor` skill, `code-review` agent for batch review.

---

## 5. Risk Register

| # | Risk | Severity | Likelihood | Sprint | Mitigation |
|---|------|----------|------------|--------|-----------|
| R1 | Webhook silent failure post-pipeline swap (customer messages dropped without alert) | 🔴 Critical | Medium | 2, 4 | Per-channel feature flag; Sentry alert at `Jobs/Process*Webhook` (error rate +10% pages on-call); dark-launch sequence bot 26 → bot 28 → FB → TG |
| R2 | Test coverage gap allows regression escape (FB/TG and OpenRouter have 0 tests) | 🔴 Critical | High | 4, 5 | Tests written BEFORE refactor in Sprint 4 (blocker); Sprint 5 writes OpenRouter tests before refactor |
| R3 | Cache invalidation regression in useConversations split (UI stops updating realtime) | 🟠 High | Medium | 3 | 5-7 contract tests before split; manual smoke of 3 critical flows |
| R4 | DB migration lock during deploy (index, VACUUM FULL) | 🟠 High | Low | 1 | `CREATE INDEX CONCURRENTLY`; VACUUM FULL inside maintenance window (02:00-08:00 +07) |
| R5 | Feature flag pollution (Sprint 2-4 add many flags, cleanup forgotten) | 🟡 Medium | High | 2, 4 | Decision log records expiry per flag; Sprint 5 includes flag-cleanup task |
| R6 | Scope creep — see other code worth fixing while refactoring | 🟡 Medium | High | All | CLAUDE.md "every changed line traces to current sprint goal"; `code-reviewer` agent gate before PR |
| R7 | RAGService split breaks Stock Management (`injectStockStatus` moves) | 🟡 Medium | Medium | 4 | Stock management already integration-tested; add 1 regression test before split |
| R8 | PR #165 plan stale (written 2026-05-16, may not match current LINE code) | 🟡 Medium | Medium | 2 | Pre-sprint plan re-validation step |
| R9 | Token budget overrun from agent team usage | 🟢 Low | Medium | All | Use Explore (read-only) agents for analysis; delegate execute to local-worker (Qwen3.6) per `feedback_hybrid_planner_executor` memory |
| R10 | Redis fallback misbehaves during refactor window | 🟡 Medium | Low | 2, 4 | Existing integration tests (commit `191946d`) + monitoring middleware cover this |

---

## 6. Verification & Monitoring Gates

### Per-sprint pre-deploy checks
- `composer test` passes
- `npm test` passes
- `composer pint --test` clean
- `npm run lint` clean
- `npm run build` succeeds
- Smoke test via Chrome DevTools MCP (open ChatPage, send test message)

### Post-deploy Sentry watch

| Sprint | Watch duration | Rollback trigger |
|--------|---------------|------------------|
| 1 | 24h | ≥2 new error issues on `Jobs/Process*` |
| 2 | 48h | ProcessLINEWebhook error rate +10% OR p95 latency +20% |
| 3 | 24h | Frontend error rate +5% OR LCP regression |
| 4 | 48h | Any new error class in `Process{LINE,FB,TG}Webhook` or RAG paths |
| 5 | 24h | New error class in OpenRouter / Stream / PaymentFlex |

### Coverage targets (path-focused, not %-focused)

| Area | Now | Target | Sprint |
|------|-----|--------|--------|
| ProcessLINEWebhook | 5 test files | maintained | 2 |
| ProcessFacebookWebhook | **0** | ≥60% happy path + edge | **4 (blocker)** |
| ProcessTelegramWebhook | **0** | ≥60% happy path + edge | **4 (blocker)** |
| RAGService (4 new services) | partial | ≥1 unit test each | 4 |
| OpenRouterService | **0** | `chat()` + retry covered | 5 |
| StreamController | **0** | 1-2 feature tests | 5 |
| Frontend `conversations/*` | partial | ≥1 test per file | 3 |
| Frontend Pages (new subcomponents) | 0 | smoke test each | 5 |

---

## 7. Out of Scope (Explicit)

- ❌ Runtime migration (Node → Bun)
- ❌ State library swap (React Query → SWR, Zustand → Redux/Jotai)
- ❌ LLM provider abstraction (OpenRouter → direct Anthropic/OpenAI)
- ❌ Database schema redesign (only additive indexes + cleanup)
- ❌ `ProcessAggregatedMessages.php` (460 LOC) — not in top 10
- ❌ `FlowController` eager-load standardization — defer to Phase 2 perf
- ❌ `HybridSearchService.py` refactor — defer (low impact)
- ❌ `BotController` / `FlowController` controller refactors — defer
- ❌ `types/api.ts` split — hand-written, 40+ importers, low payoff
- ❌ Phase 2 perf backlog (#11-15: covering indexes, LLM model swap, React-hooks warnings) — tracked in `2026-05-15-perf-audit-design.md`

---

## 8. Pre-Sprint Confirmations Needed from User

| # | Item | Why it matters | Default if unanswered |
|---|------|----------------|----------------------|
| C1 | Deploy maintenance window for Sprint 1 (02:00-08:00 +07 default) | VACUUM FULL + index migration timing | Use default |
| C2 | LOC budget per sprint cap (~1500 default) | Keeps PRs reviewable | Use default |
| C3 | Lighthouse measurement: manual via Chrome DevTools MCP (default) or set up Lighthouse CI? | Sprint 3 verification gate | Use manual default |
| C4 | Pair-programming touchpoints — Sprint 2 (LINE pipeline) and Sprint 4 (channel + RAG)? | Risk gates | Default to async review only |

---

## 9. Per-Sprint Spec Files (to be written during executing-plans)

After this roadmap is approved, individual sprint specs and plans get written immediately before that sprint starts (not all upfront — codebase will drift).

```
docs/superpowers/specs/2026-05-25-refactor-sprint-1-foundation-quick-wins.md
docs/superpowers/plans/2026-05-25-refactor-sprint-1-foundation-quick-wins.md
docs/superpowers/specs/<date>-refactor-sprint-2-line-pipeline.md       ← REUSE existing PR #165 spec
docs/superpowers/specs/<date>-refactor-sprint-3-frontend-quick-wins.md
docs/superpowers/specs/<date>-refactor-sprint-4-channel-consolidation.md
docs/superpowers/specs/<date>-refactor-sprint-5-cleanup.md
```

Each sprint spec follows the same template: Goal, Acceptance Criteria, Rollback, Architecture Reference (link back here), Test Plan, Verification.

---

### Sprint 3 Result (recorded 2026-05-25)

- Task #5 (route-level code splitting): ✅ already done in prior work (`lazyWithRetryNamed` in `router.tsx`). No action taken this sprint; flagged in pre-flight.
- Task #8 (useConversations split): ✅ COMPLETE
  - `useConversations.ts` 834 → 25 LOC (re-export shim, target ≤30)
  - 6 domain files in `frontend/src/hooks/conversations/`:
    - `useConversationQueries.ts` (143 LOC) — 5 read hooks
    - `useConversationLifecycle.ts` (113 LOC) — 4 lifecycle mutations (3 migrated to useMutationWithToast)
    - `useConversationRead.ts` (144 LOC) — markAsRead (verbatim manual) + 2 clear-context (migrated)
    - `useConversationNotes.ts` (109 LOC) — 4 notes hooks (verbatim, dynamic invalidation)
    - `useConversationTags.ts` (101 LOC) — 4 tag hooks (verbatim, dynamic invalidation)
    - `useSendAgentMessage.ts` (198 LOC) — verbatim WebSocket race-handling logic
  - All 6 domain files ≤200 LOC target met
  - 8 contract tests green throughout the entire split (Tasks 3-8)
  - Full Vitest suite: 71/71 tests passing after refactor
  - TypeScript clean (no errors)
  - 5 hooks migrated to useMutationWithToast; 16 kept manual (correct — pattern fits the use case)

#### ⚠️ User-visible behavior changes (transparency)

The migration to `useMutationWithToast` introduced 2 small behavior changes worth noting for staff users of the dashboard:

1. **Error toasts on 5 previously-silent mutations.** `useUpdateConversation`, `useCloseConversation`, `useReopenConversation`, `useClearContext`, `useClearContextAll` were plain `useMutation` before and did not surface errors to the user. After the migration they show a toast on failure (default behavior of `useMutationWithToast`). Likely an improvement — silent failures now become visible — but it is a UX change that staff should be aware of.

2. **Broader cache invalidation in 3 lifecycle hooks.** The originals invalidated the exact `['conversation', botId, conversationId]` key. The migrated versions invalidate the prefix `['conversation', botId]`, which matches ALL single-conversation detail queries for that bot. Same change applies to `useClearContext`. Correctness is preserved (the right data still refreshes), but if a user has multiple conversation detail panels open, one close/reopen action now triggers refetches for all of them. Low-impact perf regression; can be narrowed in Sprint 5 by adding `onSuccess` callbacks alongside `invalidateKeys`.

#### Follow-ups for Sprint 5+

- Narrow invalidation in `useUpdateConversation`, `useCloseConversation`, `useReopenConversation`, `useClearContext` back to per-conversation keys (add `onSuccess` callback alongside `invalidateKeys`)
- Add contract tests for the 5 migrated mutations (currently only `useCloseConversation` is tested) + a test asserting `toast.error` fires on failure
- Extract shared `ConversationResponse` / `ConversationsResponse` interfaces (declared in 4 files) to a `conversations/types.ts` to remove ~24 LOC of duplication
- Update the "during the Sprint 3 split" comment in `useConversations.ts` (the shim is permanent, not temporary)

- Decision: GO for Sprint 5 (Sprint 4 deferred per D11 reasoning — single-bot operation makes channel consolidation low-ROI).

---

## 10. References

- Source audit reports: 4 parallel agents on 2026-05-25 (backend, frontend, cross-cutting+perf, DB)
- Related specs:
  - `docs/superpowers/specs/2026-05-16-process-line-webhook-refactor-design.md` (Sprint 2 baseline)
  - `docs/superpowers/specs/2026-05-15-perf-audit-design.md` (Phase 2 backlog source)
  - `docs/superpowers/specs/2026-05-20-graceful-redis-fallback-design.md` (R10 mitigation)
- Library guidance verified via Context7: TanStack Query v5 `useMutation` + optimistic update patterns
