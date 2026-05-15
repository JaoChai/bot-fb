# Performance Audit Summary — 2026-05-15

**Period audited:** 7 days (2026-05-08 → 2026-05-15)
**Data sources:** Sentry Discover API, Neon pg_stat_*, OpenRouter cost tracking on `messages` table, Lighthouse on https://www.botjao.com, build artifacts + knip + tsc, source-tree LOC analysis.

## Health Snapshot

| Dimension | Status | Key Metric | Threshold |
|-----------|--------|------------|-----------|
| Backend HTTP Latency | 🟢 | p95 max 247ms (`/api/analytics/costs`) | < 500ms |
| Queue Job Latency | 🔴 | `ProcessAggregatedMessages` p95 = 7.3s, `ProcessLINEWebhook` p95 = 5.9s | < 2s |
| Database | 🔴 | `cache` table 62.7M tuples read / 1.2M scans (47 rows) | seq_tup_read sane |
| Cost | 🟡 | $45/mo OpenRouter; $/conv +163% in 7d window | stable |
| Frontend UX (Web Vitals) | 🔴 | LCP 4.1-4.2s on 3/3 measured pages | < 2.5s |
| Reliability | 🟡 | Chronic embedding API failures (294/130d); CB events silent in Sentry | < 0.5% global |
| Throughput | 🟡 | 1 single worker + OPENROUTER_TIMEOUT=120s = 2-min freeze risk | isolation present |
| Code Quality | 🔴 | `ProcessLINEWebhook.php` 1432 LOC / 19 methods / 16 service imports — outlier | 0 files > 500 LOC in Services |

**Overall:** 🔴 3 / 🟡 3 / 🟢 1 — multiple structural issues requiring targeted fix, no immediate crisis.

## Top 10 Priorities (preview)

See `2-priority-list.md` for full 15-issue table with scoring detail.

| # | Title | Score | Phase |
|---|-------|-------|-------|
| 1 | Fix /api/analytics/costs + /api/dashboard/summary error rates | 20 | 1 |
| 2 | Restore CircuitBreaker → Sentry visibility | 20 | 1 |
| 3 | Frontend code splitting (React.lazy on routes) | 18 | 1 |
| 4 | Frontend static HTML skeleton | 18 | 1 |
| 5 | Remove dead CostTrackingService.php | 17 | 1 |
| 6 | Split llm queue + add 2nd worker | 17 | 1 |
| 7 | Drop 60 unused indexes | 15 | 2 |
| 8 | Remove unused frontend dep + 22 unused exports | 14 | 2 |
| 9 | Refactor ProcessLINEWebhook.php | 13 | 2 |
| 10 | Reduce OpenRouter timeout + fallback model | 13 | 2 |

## Recommended Phase 1

**Items:** #1, #2, #4, #5, #6 (selected from score ≥ 16 list for complementary coverage)
**Combined effort:** ~5.5 days
**Dimensions improved:** Reliability, Cost, Code Quality, UX (Frontend), Throughput, Latency (queue jobs)

**Why not #3 in Phase 1:** It would alone consume 3 days; deferred to Phase 2 with #4 as fast UX progressive win in Phase 1.

**Detailed spec:** `docs/superpowers/specs/2026-05-15-perf-phase1-design.md` (Task 9 deliverable)

## Cross-Correlation Highlights

1. **ProcessLINEWebhook.php is the central hotspot** — appears as a finding in 4 of 6 dimensions (Latency, Reliability, Throughput, Code Quality). Refactor is Phase 2 due to high effort/risk.

2. **LLM/OpenRouter is THE bottleneck** — converges 4 dimensions (Latency, Cost, Reliability, Throughput). Phase 1 #6 (queue separation) addresses the structural fragility; Phase 2 continues with timeout + model strategy.

3. **AgentCostUsage drop (2026-04-18) left orphan refs** — explains 3 unit findings (Cost, Reliability, Code Quality). Two Phase 1 items (#1, #5) finish the cleanup.

4. **Silent monitoring failures undermine confidence** — CB events not reaching Sentry due to nullable injection. Phase 1 #2 fixes this.

5. **Frontend boot mirrors backend single-worker fragility** — same "lack of distribution" pattern in two layers. Phase 1 #4 (frontend) + #6 (backend) attack this pattern from both sides.

## Token Usage (preliminary)

Subagent tokens used:
- Task 1 (Sonnet, redo with Sentry API): 106,782
- Task 2 (Sonnet, database): 133,188
- Task 3 (Sonnet, cost): 131,376
- Task 4 (Sonnet, frontend): 130,226
- Task 5 (Sonnet, reliability): 128,996
- Task 6 (Sonnet, throughput): 136,133
- Task 7 (Sonnet, code quality): 124,661
- **Local-worker (Sonnet) total: ~891,000**

Opus (this session) tokens: in-flight — measured at session end.

Per memory feedback `feedback_hybrid_planner_executor.md`: aim was delegate-default. Status: 100% of data collection delegated to Sonnet. Synthesis (this summary + priority list + Phase 1 spec next) retained in Opus.

## Where Next

Task 9 drafts Phase 1 implementation spec at `docs/superpowers/specs/2026-05-15-perf-phase1-design.md`.
Task 10 verifies exit criteria, then completes the development branch.
