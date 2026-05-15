# Performance Audit Design — 2026-05-15

## Problem Statement

ระบบ bot-fb มี backend 43 services, frontend ~25 hooks/12 pages, PostgreSQL+pgvector + Redis + Reverb WebSocket รันบน Railway มี performance work ทำมาเป็นระยะ (DB cost reduction, backend resilience, native chat phases) แต่ยังไม่เคยมีการสำรวจ end-to-end ที่วัดทั้ง 6 dimensions พร้อมกัน

เป้าหมาย: ทำ standard audit 2-3 วัน เก็บข้อมูล 7 วันย้อนหลังจากทุกแหล่ง วิเคราะห์ cross-dimension แล้วออกเป็น ranked priority list พร้อม Phase 1 spec ที่ใช้ implement ได้ทันที

## Dimensions Audited

1. **Latency** — backend p50/p95/p99, frontend Web Vitals (LCP/INP/CLS)
2. **Cost** — Neon compute, OpenRouter token, Railway compute, Redis memory
3. **Throughput** — webhook rate, queue depth, job latency
4. **Reliability** — error rate, failed jobs, timeout/retry, circuit breaker events
5. **UX** — perceived smoothness on chat/dashboard/knowledge pages
6. **Code Quality** — files > 300 LOC, complexity, dead code, duplicate patterns

## Architecture

Audit แบ่งเป็น 7 isolated units ที่รัน independent ได้ แต่ละ unit produce ไฟล์ markdown 1 ไฟล์ใน `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/`

```
Audit Orchestrator (Opus, this session)
├── Unit 1 — Backend Latency       → 1-detail/1-latency-backend.md
├── Unit 2 — Database Profiler     → 1-detail/2-database.md
├── Unit 3 — Cost Analyzer         → 1-detail/3-cost.md
├── Unit 4 — Frontend Profiler     → 1-detail/4-frontend.md
├── Unit 5 — Reliability Scanner   → 1-detail/5-reliability.md
├── Unit 6 — Throughput Inspector  → 1-detail/6-throughput.md
└── Unit 7 — Code Quality Scanner  → 1-detail/7-code-quality.md

Cross-correlation analysis (Opus, Day 2)
├── 0-summary.md            (health snapshot + top 10)
└── 2-priority-list.md      (ranked actions with scores)

Phase 1 Spec (Opus + writing-plans skill, Day 3)
└── docs/superpowers/specs/2026-05-15-perf-phase1-design.md
```

**Operating mode:** Read-only ตลอด audit. ไม่แตะ production code/config จนกว่าจะ approve Phase 1 spec

## Data Sources Per Unit

### Unit 1: Backend Latency
- Source: Sentry Performance project `bot-fb-backend`
- Method: `mcp__sentry__search_events` top 50 slowest endpoints (p95) 7d; top 20 by hits×duration; `php artisan route:list --json` for endpoint mapping
- Output: `1-detail/1-latency-backend.md` — table(endpoint, p50, p95, hits/day, total time)
- Owner: Opus

### Unit 2: Database Profiler
- Source: Neon project `solitary-math-34010034`
- Queries via `mcp__neon__run_sql`:
  - `pg_stat_statements` top 20 by `total_exec_time`
  - `pg_stat_user_tables` — seq_scan vs idx_scan, n_dead_tup, n_live_tup
  - `pg_stat_user_indexes` — unused indexes where `idx_scan = 0`
  - Cache hit ratio per table (`heap_blks_hit / (heap_blks_hit + heap_blks_read)`)
- Output: `1-detail/2-database.md` — slow queries, missing indexes, dead tuples, unused indexes
- Owner: Local-worker

### Unit 3: Cost Analyzer
- Source: Neon billing API, internal `CostTrackingService`, Railway billing screenshots (user provides if needed)
- Method:
  - Neon: compute hours, data transfer, storage
  - OpenRouter: `SELECT model, SUM(cost), SUM(tokens) FROM agent_cost_usage WHERE created_at > NOW() - INTERVAL '7 days' GROUP BY model` (via `mcp__neon__run_sql`)
  - Railway: compute time per service (user-provided screenshot if API unavailable)
- Output: `1-detail/3-cost.md` — cost breakdown, $/conversation, 7d trend, top 5 drivers
- Owner: Opus

### Unit 4: Frontend Profiler
- Source: `frontend/` build artifacts, knip, Lighthouse
- Commands (run from `frontend/`):
  - `npm run build` → analyze `dist/` chunk sizes
  - `npx knip --reporter compact` → dead exports
  - `npx tsc --noEmit` → TypeScript errors count
  - `npx lighthouse https://<prod-url>/chat --output=json --quiet`
  - Same for `/dashboard` and `/knowledge`
- Output: `1-detail/4-frontend.md` — bundle breakdown, dead code, TS health, Web Vitals per page
- Owner: Local-worker

### Unit 5: Reliability Scanner
- Source: Sentry Issues, `failed_jobs` table, Railway logs
- Method:
  - `mcp__sentry__search_issues` — unresolved 7d, sorted by events
  - `mcp__sentry__search_events` — error rate per endpoint
  - `mcp__neon__run_sql`: `SELECT * FROM failed_jobs ORDER BY failed_at DESC LIMIT 100`
  - Grep `CircuitBreakerService` open events in Sentry breadcrumbs
- Output: `1-detail/5-reliability.md` — top errors, failed jobs pattern, timeout/retry stats
- Owner: Local-worker

### Unit 6: Throughput Inspector
- Source: Redis queue, Sentry traces, Railway worker metrics
- Method:
  - Webhook ingestion rate from `LineWebhookController`, `TelegramWebhookController` traces — req/min p95
  - Job latency: `created_at` → completion via Sentry traces
  - Worker CPU time per service (Railway metrics)
- Output: `1-detail/6-throughput.md` — webhook rate, job latency, worker utilization
- Owner: Opus

### Unit 7: Code Quality Scanner
- Source: Source tree
- Commands:
  - `find backend/app -name "*.php" -exec wc -l {} + | sort -rn | head -30`
  - `find frontend/src -name "*.tsx" -o -name "*.ts" -exec wc -l {} + | sort -rn | head -30`
  - `npm run lint` (frontend), `vendor/bin/pint --test` (backend)
- Output: `1-detail/7-code-quality.md` — refactor candidates ranked by LOC + complexity
- Owner: Local-worker

## Cross-Correlation (Day 2, Opus)

หลัง 7 units เสร็จ Opus วิเคราะห์ความสัมพันธ์:

- Latency × Database → endpoint ช้าเพราะ query ไหน
- Cost × Code → service ไหน burn budget
- Reliability × Throughput → ระบบพังตอน load สูงไหม
- Frontend × Latency → API ช้าทำให้ INP แย่ไหม
- Code Quality × ทุก dimension → big file ที่ correlate กับ slow/buggy/expensive

ผลคือ `0-summary.md` + `2-priority-list.md`

## Priority Scoring

ทุก finding ใน priority list ใช้ formula:

```
score = (impact × 3) + (confidence × 2) - effort - risk
```

แต่ละ axis ใช้ scale 1-5:

| Axis | 1 | 3 | 5 |
|------|---|---|---|
| Impact | save < $10/mo or < 5% latency | $50/mo or 20% latency | > $200/mo or > 50% latency / user-facing major |
| Confidence | hypothesis, ต้อง verify | มี evidence บางส่วน | data ชัด, root cause ตรง |
| Effort | < 2 hr, 1 file | 1-2 days, < 10 files | > 1 week, refactor หลาย service |
| Risk | rollback ง่าย, isolated | ต้อง test, อาจกระทบ 1 feature | กระทบ user flow, hard rollback |

**Phase mapping:** score ≥ 16 = Phase 1, 10-15 = Phase 2, < 10 = Phase 3+/skip

## Phase 1 Selection Rules

**Must:**
- Score ≥ 16
- Combined effort ≤ 5 days
- มี monitoring + rollback plan

**Should:**
- Issues เลือกควร complementary (เช่น cache + index → DB)
- มี baseline metric ที่วัด improvement ได้
- ครอบคลุม ≥ 2 dimension

**Must not:**
- กระทบ user flow โดยไม่มี feature flag
- Destructive schema change โดยไม่ใช้ `safe-migration` skill
- กระทบ authentication/billing logic

## Output Files

| File | Purpose |
|------|---------|
| `docs/superpowers/audits/2026-05-15-perf-audit/0-summary.md` | Health snapshot + top 10 |
| `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/1-latency-backend.md` | Unit 1 data + findings |
| `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/2-database.md` | Unit 2 |
| `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/3-cost.md` | Unit 3 |
| `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/4-frontend.md` | Unit 4 |
| `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/5-reliability.md` | Unit 5 |
| `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/6-throughput.md` | Unit 6 |
| `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/7-code-quality.md` | Unit 7 |
| `docs/superpowers/audits/2026-05-15-perf-audit/2-priority-list.md` | Ranked actions with scores |
| `docs/superpowers/specs/2026-05-15-perf-phase1-design.md` | Phase 1 implementation spec |

## Timeline

**Day 1 — Data Collection (parallel):**
- Spawn 4 local-worker agents concurrently for Units 2, 4, 5, 7
- Opus runs Units 1, 3, 6 in parallel with agent dispatch
- All 7 unit files written by end of day

**Day 2 — Analysis:**
- Opus reads all 7 unit files
- Cross-correlation analysis
- Write `0-summary.md` and `2-priority-list.md`

**Day 3 — Phase 1 Spec:**
- Opus selects top 1-3 from priority list per selection rules
- Invoke `writing-plans` skill to draft Phase 1 plan
- User reviews + approves

## Delegation Discipline

- **Default delegate** สำหรับ Units 2, 4, 5, 7 (mechanical data collection)
- **Opus retains** Units 1, 3, 6 (need correlation/judgment)
- **3-strike rule** — local-worker ผิด 3 ครั้งใน unit เดียว → Opus take over
- **Falsifiable Done criteria** per unit — verifiable by file existence + row count
- **Token ratio reported** at end of audit

## Exit Criteria

**Hard (all required):**
- [ ] 9 files exist (`0-summary.md`, 7 unit files, `2-priority-list.md`)
- [ ] Priority list ≥ 10 issues
- [ ] ≥ 3 issues with score ≥ 16
- [ ] Phase 1 spec written at `docs/superpowers/specs/2026-05-15-perf-phase1-design.md`
- [ ] Every finding has evidence link (Sentry URL / SQL result / `file:line`)
- [ ] Zero unresolved `BLOCKED` items

**Soft (target):**
- [ ] ≥ 3 cross-correlation patterns found
- [ ] Every dimension scored 🟢/🟡/🔴 with quantitative threshold
- [ ] Cost breakdown identifies top 5 drivers
- [ ] Web Vitals measured on ≥ 3 pages

## Phase 1 Success Definition

After Phase 1 deploy + 1 week post-deploy verification:
- [ ] Target baseline metric improved as predicted
- [ ] No regression in other dimensions
- [ ] No cost increase (unless offset by larger saving)

Pass → proceed to Phase 2 batch from priority list
Fail → root cause analysis via `superpowers:systematic-debugging`

## Blockers To Resolve Before Start

- Sentry org slug confirmation (default attempt: `aijaochai`)
- Production URL for Lighthouse runs
- Railway billing access (API or screenshot from user)
