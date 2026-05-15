# Priority List — Performance Audit 2026-05-15

Scoring formula: `(impact × 3) + (confidence × 2) - effort - risk`, all axes 1-5 scale.

Source: cross-correlation of unit reports `1-detail/1-*.md` through `1-detail/7-*.md`.

## Ranked Issues

| # | Title | Units | Impact | Conf | Effort | Risk | Score | Phase |
|---|-------|-------|--------|------|--------|------|-------|-------|
| 1 | ✅ Fixed analytics/dashboard error rates (PR 193162e + #153, pre-Phase 1) | 5 | 4 | 5 | 1 | 1 | 20 | 1 |
| 2 | ✅ Restored CircuitBreaker → Sentry visibility (PR #155, non-nullable DI) | 5 | 4 | 5 | 1 | 1 | 20 | 1 |
| 3 | ✅ Frontend code splitting already in place — `frontend/src/router.tsx` uses `lazyWithRetryNamed` for all 15 routes with `ChunkErrorBoundary` + `Suspense` (verified 2026-05-15) | 4 | 5 | 4 | 3 | 2 | 18 | 1 |
| 4 | ✅ Added static HTML skeleton to frontend/index.html (PR #155, FCP < JS parse) | 4 | 4 | 4 | 1 | 1 | 18 | 1 |
| 5 | ✅ Removed dead CostTrackingService.php (PR #155) | 3,7 | 3 | 5 | 1 | 1 | 17 | 1 |
| 6 | ✅ Split llm queue + 2nd worker process (PR #156, Railway flag ON 2026-05-15 13:35 UTC) | 1,6 | 5 | 4 | 3 | 3 | 17 | 1 |
| 7 | Drop 60 unused indexes (1.4MB + faster writes; via safe-migration skill) | 2 | 3 | 5 | 2 | 2 | 15 | 2 |
| 8 | ✅ Removed unused query-sync-storage-persister + dead exports (PR #157, -110 LOC) | 4 | 2 | 5 | 1 | 1 | 14 | 2 |
| 9 | Refactor ProcessLINEWebhook.php (1432 LOC, 19 methods, 16 imports → split into 3-4 services) | 1,5,6,7 | 4 | 5 | 5 | 4 | 13 | 2 |
| 10 | ✅ Reduced OPENROUTER_TIMEOUT 120→45s + fallback documented (PR #158, Railway env applied) | 1,6 | 3 | 4 | 2 | 2 | 13 | 2 |
| 11 | ✅ RESOLVED 2026-05-15 — stats were lifetime/pre-migration; truncated stale rows; Redis is the active store | 2 | 3 | 3 | 2 | 1 | 12 | 2 |
| 12 | VACUUM FULL on `bots` (52% dead), `personal_access_tokens` (36% dead), `rag_cache` (100% dead, 0 live) | 2 | 2 | 4 | 1 | 2 | 11 | 2 |
| 13 | Switch dominant LLM model to cheaper alt with quality tests + add per-call cost cap | 3 | 4 | 3 | 3 | 4 | 11 | 2 |
| 14 | Add covering indexes for hot queries (after EXPLAIN ANALYZE on top 5 from pg_stat_statements) | 2 | 3 | 3 | 2 | 2 | 11 | 2 |
| 15 | Fix 33 react-hooks ESLint warnings (React Compiler vs manual useCallback conflicts) | 7 | 2 | 3 | 2 | 2 | 8 | 3 |

## Cross-Correlation Patterns

### Pattern 1: ProcessLINEWebhook.php is the central hotspot
4 of 6 dimensions point to this file:
- **Latency (Unit 1):** p95 = 5,870ms (2nd slowest transaction overall)
- **Reliability (Unit 5):** top backend error source — 294 events of "Consecutive HTTP" failures on OpenRouter embeddings over 130 days, all from this file
- **Throughput (Unit 6):** dominates webhook traffic + worker time
- **Code Quality (Unit 7):** outlier at 1432 LOC, 19 methods, 16 `App\Services\` imports (next-highest Job has 5)

This single file is the system's structural weakest point. Issue #9 (refactor) is high-impact but high-effort/risk — gated to Phase 2 with proper safe-migration approach.

### Pattern 2: LLM/OpenRouter is THE bottleneck across cost + latency + reliability
4 of 6 dimensions converge on LLM I/O:
- **Latency (Unit 1):** `ProcessAggregatedMessages` p95 = 7,310ms — 100% LLM-bound
- **Cost (Unit 3):** 100% of $45/mo spend is OpenRouter on single model `openai/gpt-5.4-20260305`; cost-per-conversation rose 163% within 7d
- **Reliability (Unit 5):** chronic embedding API failures (294 events / 130d)
- **Throughput (Unit 6):** LLM jobs = 93.5% of worker time; `OPENROUTER_TIMEOUT=120s` on single worker = 2-min freeze bomb

Issues #6, #10, #13 all address this pattern. Phase 1 includes #6 (queue separation, the biggest structural fix).

### Pattern 3: AgentCostUsage drop (2026-04-18) left orphan references
Removed table cascade impact across 3 units:
- **Cost (Unit 3):** found cost data on `messages` table instead — works
- **Reliability (Unit 5):** `/api/analytics/costs` 50% error rate (2/4 requests) — direct fallout
- **Code Quality (Unit 7):** `CostTrackingService.php` is dead code, would throw at runtime

Issues #1 and #5 are quick wins (effort 1) that close out this cleanup. Both in Phase 1.

### Pattern 4: Silent monitoring failures undermine confidence
- **Reliability (Unit 5):** `ResilienceMetricsService` is nullable in constructor → circuit breaker open events never reach Sentry. Audit could not measure CB activity.
- **Code Quality (Unit 7):** Pint check BLOCKED due to PHP version mismatch in worktree (CI on PHP 8.3 is authoritative)

Issue #2 fixes the CB monitoring blind spot. Phase 1.

### Pattern 5: Frontend boot mirrors backend single-worker fragility
Same "lack of distribution" pattern:
- **Frontend (Unit 4):** `index.js` 329KB blocking entry, no code splitting, vendor-charts 363KB loaded on every page
- **Throughput (Unit 6):** 1 backend worker process, no replicas, no queue separation

Issues #3 and #4 address frontend. Phase 1.

## Phase 1 Candidates (score ≥ 16)

Six issues qualify. Combined Phase 1 selection rules:
- Combined effort ≤ 5 days
- Complementary
- Cover ≥ 2 dimensions

**Recommended Phase 1 mix (effort ~5 days total, 4 dimensions covered):**

| Item | Effort | Dimensions improved |
|------|--------|--------------------|
| #1 Fix analytics/dashboard error endpoints | 1d | Reliability, Cost |
| #2 Restore CB Sentry visibility | 1d | Reliability |
| #5 Remove dead CostTrackingService | 0.5d | Code Quality, Cost |
| #4 Frontend static HTML skeleton | 1d | UX (Frontend) |
| #6 Split llm queue + add 2nd worker | 2d | Throughput, Latency, Reliability |
| **Total** | **~5.5d** | **Reliability, Cost, Code Quality, UX, Throughput, Latency** |

**Deferred to Phase 2 (rationale):**
- #3 Frontend code splitting (3d) — biggest single LCP win but 3d alone uses up budget; Phase 1 #4 captures fast progressive win (FCP), Phase 2 doubles down on LCP.

## Phase 2 Backlog (score 10-15)

8 issues. Order suggested:
1. #9 ProcessLINEWebhook refactor (largest structural cleanup)
2. #3 Frontend code splitting (continue LCP campaign)
3. #10 OpenRouter timeout reduction + fallback
4. #11 Investigate cache table residual reads
5. #7 Drop unused indexes (safe migration)
6. #14 Covering indexes after EXPLAIN
7. #8 Frontend dead code cleanup
8. #12 VACUUM FULL bloated tables
9. #13 Cheaper LLM evaluation

## Phase 3 / Skip (score < 10)

- #15 React-hooks ESLint warnings — defer; not blocking, may resolve with React Compiler updates

## Phase 1 Spec
Detailed spec drafted at `docs/superpowers/specs/2026-05-15-perf-phase1-design.md` (Task 9).
