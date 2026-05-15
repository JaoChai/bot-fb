# Performance Audit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run a 7-unit performance audit on bot-fb (latency, database, cost, frontend, reliability, throughput, code quality), produce a ranked priority list, and draft a Phase 1 implementation spec.

**Architecture:** Seven isolated units write markdown reports into `docs/superpowers/audits/2026-05-15-perf-audit/`. Units 2, 4, 5, 7 are delegated to local-worker (mechanical data collection). Units 1, 3, 6 retained by Opus (require correlation/judgment). Day 2 cross-correlation produces summary + priority list. Day 3 drafts Phase 1 spec.

**Tech Stack:** Sentry MCP, Neon MCP (`mcp__neon__run_sql`), Laravel Boost MCP (`mcp__laravel-boost__*`), shell commands (`find`, `wc`, `npm run`, `npx`), Lighthouse CLI.

**Reference spec:** `docs/superpowers/specs/2026-05-15-perf-audit-design.md`

---

## Task 0: Pre-flight — Resolve Blockers and Create Directories

**Files:**
- Create: `docs/superpowers/audits/2026-05-15-perf-audit/`
- Create: `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/`

- [ ] **Step 1: Confirm Sentry organization slug**

Run:
```bash
# via Sentry MCP — list organizations
```
Tool: `mcp__sentry__find_organizations` with `query=""`
Expected: receive list, pick the org owning project `bot-fb-backend`. Default guess: `aijaochai`.

Record the resolved slug in a scratch note for use in later tasks.

- [ ] **Step 2: Confirm production frontend URL**

Ask user (or read from `.env` / Railway config):
> "Production frontend URL สำหรับ Lighthouse คืออะไรครับ?"

Record URL for Unit 4.

- [ ] **Step 3: Confirm Railway billing access**

Try `mcp__railway__list-projects`. If billing data not available via API, ask user:
> "Railway billing dashboard ตอนนี้คนละ service ค่าใช้จ่ายเท่าไหร่บ้างครับ? ส่ง screenshot มาให้ก็ได้"

Record per-service compute cost for Unit 3.

- [ ] **Step 4: Create audit directory structure**

Run:
```bash
mkdir -p docs/superpowers/audits/2026-05-15-perf-audit/1-detail
```

Verify:
```bash
ls -la docs/superpowers/audits/2026-05-15-perf-audit/
```
Expected: directory exists with `1-detail/` subdirectory.

- [ ] **Step 5: Commit scaffolding**

```bash
git add docs/superpowers/audits/2026-05-15-perf-audit/
git commit --allow-empty -m "chore(audit): scaffold perf audit 2026-05-15"
```

---

## Task 1: Unit 1 — Backend Latency (Opus)

**Files:**
- Create: `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/1-latency-backend.md`

- [ ] **Step 1: Query Sentry for top slow endpoints (p95) 7 days**

Tool: `mcp__sentry__search_events`
Parameters:
```
organizationSlug: <resolved_in_task_0>
naturalLanguageQuery: "transactions with highest p95 duration in last 7 days, top 50, include hit count"
limit: 50
```

Save raw output for evidence.

- [ ] **Step 2: Query Sentry for endpoints by total time (hits × duration)**

Tool: `mcp__sentry__search_events`
Parameters:
```
naturalLanguageQuery: "transactions with highest total time spent (sum of duration × count) in last 7 days, top 20"
limit: 20
```

- [ ] **Step 3: Map endpoints to Laravel routes**

Run from `backend/`:
```bash
cd backend && php artisan route:list --json > /tmp/routes.json
```

Match transaction names from Sentry to route handlers (controller@method).

- [ ] **Step 4: Write Unit 1 report**

Write file `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/1-latency-backend.md` with sections:

```markdown
# Unit 1: Backend Latency

## Data Collected

### Top 50 Endpoints by p95 (7d)
| Rank | Endpoint | Controller@method | p50 (ms) | p95 (ms) | p99 (ms) | hits/day | Sentry link |
|------|----------|-------------------|----------|----------|----------|----------|-------------|
| 1 | ... | ... | ... | ... | ... | ... | https://... |

### Top 20 Endpoints by Total Time (7d)
| Rank | Endpoint | Total time (s) | hits | avg (ms) | Sentry link |
|------|----------|----------------|------|----------|-------------|
| 1 | ... | ... | ... | ... | ... |

## Findings

### Finding 1: <title>
- **Evidence:** <Sentry URL or row from table above>
- **Impact:** <p95 latency Xms on Y hits/day = Z total user-time/day>
- **Root cause hypothesis:** <what + why, e.g., "no eager loading on relation Z">
- **Fix candidates:**
  1. <option 1 with effort estimate>
  2. <option 2>

### Finding 2: ...

## Status: 🟢 healthy / 🟡 watch / 🔴 critical
Threshold: p95 < 500ms = 🟢, < 1000ms = 🟡, ≥ 1000ms = 🔴
Current: p95 = <X>ms — status <emoji>
```

Fill in all `<placeholders>` with actual data from Steps 1-3.

- [ ] **Step 5: Verify file exists and has data**

Run:
```bash
ls -la docs/superpowers/audits/2026-05-15-perf-audit/1-detail/1-latency-backend.md
wc -l docs/superpowers/audits/2026-05-15-perf-audit/1-detail/1-latency-backend.md
grep -c "^|" docs/superpowers/audits/2026-05-15-perf-audit/1-detail/1-latency-backend.md
```

Expected: file exists, ≥ 50 lines, ≥ 30 table rows (50 endpoints + 20 endpoints rows + header).

- [ ] **Step 6: Commit**

```bash
git add docs/superpowers/audits/2026-05-15-perf-audit/1-detail/1-latency-backend.md
git commit -m "audit(unit-1): backend latency report"
```

---

## Task 2: Unit 2 — Database Profiler (Local-worker)

**Files:**
- Create: `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/2-database.md`

**Delegation:** Dispatch as subagent (general-purpose) with this complete brief. Subagent runs all steps, writes the file, reports back.

- [ ] **Step 1: Subagent dispatched with brief**

Brief to subagent:
> Run 4 SQL queries on Neon project `solitary-math-34010034` using `mcp__neon__run_sql` and write findings to `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/2-database.md`. Output structure shown below. No code changes, queries only.

- [ ] **Step 2: Query — top 20 slow queries**

Run via `mcp__neon__run_sql`:
```sql
SELECT
  substring(query, 1, 200) AS query_snippet,
  calls,
  ROUND(total_exec_time::numeric, 2) AS total_ms,
  ROUND(mean_exec_time::numeric, 2) AS mean_ms,
  ROUND(stddev_exec_time::numeric, 2) AS stddev_ms,
  rows
FROM pg_stat_statements
ORDER BY total_exec_time DESC
LIMIT 20;
```

- [ ] **Step 3: Query — table scan health**

```sql
SELECT
  relname,
  seq_scan,
  seq_tup_read,
  idx_scan,
  idx_tup_fetch,
  n_live_tup,
  n_dead_tup,
  ROUND(100.0 * n_dead_tup / NULLIF(n_live_tup + n_dead_tup, 0), 1) AS dead_pct
FROM pg_stat_user_tables
ORDER BY seq_tup_read DESC
LIMIT 30;
```

- [ ] **Step 4: Query — unused indexes**

```sql
SELECT
  schemaname,
  relname AS table_name,
  indexrelname AS index_name,
  idx_scan,
  pg_size_pretty(pg_relation_size(indexrelid)) AS size
FROM pg_stat_user_indexes
WHERE idx_scan = 0
ORDER BY pg_relation_size(indexrelid) DESC;
```

- [ ] **Step 5: Query — cache hit ratio**

```sql
SELECT
  relname,
  heap_blks_read,
  heap_blks_hit,
  ROUND(100.0 * heap_blks_hit / NULLIF(heap_blks_hit + heap_blks_read, 0), 2) AS hit_ratio_pct
FROM pg_statio_user_tables
WHERE heap_blks_read + heap_blks_hit > 1000
ORDER BY (heap_blks_hit + heap_blks_read) DESC
LIMIT 20;
```

- [ ] **Step 6: Write Unit 2 report**

Write file `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/2-database.md`:

```markdown
# Unit 2: Database Profiler

## Data Collected

### Top 20 Slow Queries (by total_exec_time)
| Rank | Query | Calls | Total (ms) | Mean (ms) | Stddev | Rows |
|------|-------|-------|------------|-----------|--------|------|
| 1 | ... | ... | ... | ... | ... | ... |

### Table Scan Health (top 30 by seq_tup_read)
| Table | seq_scan | seq_tup_read | idx_scan | n_dead | dead % |
|-------|----------|--------------|----------|--------|--------|
| ... |

### Unused Indexes (idx_scan = 0)
| Table | Index | Size |
|-------|-------|------|

### Cache Hit Ratio (top 20 active tables)
| Table | Hit ratio % |
|-------|-------------|

## Findings

### Finding 1: <title>
- **Evidence:** <SQL result row>
- **Impact:** <e.g., "query Y called 1.2M times = 8% of DB time">
- **Root cause hypothesis:** <e.g., "missing index on column Z">
- **Fix candidates:** <list>

## Status: 🟢/🟡/🔴
Threshold: dead_pct < 10% all critical tables = 🟢, < 25% = 🟡, ≥ 25% = 🔴
```

Fill all sections with actual data.

- [ ] **Step 7: Verify**

```bash
test -f docs/superpowers/audits/2026-05-15-perf-audit/1-detail/2-database.md && \
  grep -c "^|" docs/superpowers/audits/2026-05-15-perf-audit/1-detail/2-database.md
```
Expected: ≥ 60 table rows total across 4 sections.

- [ ] **Step 8: Commit**

```bash
git add docs/superpowers/audits/2026-05-15-perf-audit/1-detail/2-database.md
git commit -m "audit(unit-2): database profiler report"
```

---

## Task 3: Unit 3 — Cost Analyzer (Opus)

**Files:**
- Create: `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/3-cost.md`

- [ ] **Step 1: Query internal cost tracking (OpenRouter)**

Via `mcp__neon__run_sql` on project `solitary-math-34010034`:
```sql
SELECT
  model,
  COUNT(*) AS calls,
  SUM(cost) AS total_cost_usd,
  SUM(input_tokens) AS in_tokens,
  SUM(output_tokens) AS out_tokens,
  ROUND(AVG(cost)::numeric, 4) AS avg_cost_per_call
FROM agent_cost_usage
WHERE created_at > NOW() - INTERVAL '7 days'
GROUP BY model
ORDER BY total_cost_usd DESC;
```

If `agent_cost_usage` table doesn't exist, query `model_usage` or grep schema with `mcp__neon__get_database_tables`.

- [ ] **Step 2: Query cost per conversation**

```sql
SELECT
  DATE_TRUNC('day', acu.created_at) AS day,
  SUM(acu.cost) AS total_cost,
  COUNT(DISTINCT c.id) AS unique_conversations,
  ROUND(SUM(acu.cost)::numeric / NULLIF(COUNT(DISTINCT c.id), 0), 4) AS cost_per_conv
FROM agent_cost_usage acu
LEFT JOIN conversations c ON c.id = acu.conversation_id
WHERE acu.created_at > NOW() - INTERVAL '7 days'
GROUP BY day
ORDER BY day;
```

- [ ] **Step 3: Read Neon billing**

If Neon billing API available, query usage for past 7 days. Otherwise: ask user for screenshot of Neon billing dashboard.

- [ ] **Step 4: Read Railway billing**

Record from Task 0 Step 3 user input.

- [ ] **Step 5: Write Unit 3 report**

Write `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/3-cost.md`:

```markdown
# Unit 3: Cost Analyzer

## 7-Day Cost Breakdown

### Total
| Source | $/7d | $/mo projected | % of total |
|--------|------|----------------|------------|
| OpenRouter (AI) | ... | ... | ...% |
| Neon (DB) | ... | ... | ...% |
| Railway (compute) | ... | ... | ...% |
| Redis | ... | ... | ...% |
| **Total** | **...** | **...** | 100% |

### OpenRouter by Model (7d)
| Model | Calls | Total $ | In tokens | Out tokens | Avg $/call |
|-------|-------|---------|-----------|------------|------------|

### Cost per Conversation Trend (7d)
| Day | Total cost | Conversations | $/conv |
|-----|-----------|---------------|--------|

## Findings

### Finding 1: Top cost driver
- **Evidence:** <table row>
- **Impact:** <X% of monthly bill>
- **Root cause hypothesis:** <why this model/service used so much>
- **Fix candidates:** <e.g., switch to cheaper model, cache results, batch>

## Status: 🟢/🟡/🔴
Threshold: $/conv stable or declining = 🟢, rising < 20% week-over-week = 🟡, ≥ 20% = 🔴
```

- [ ] **Step 6: Verify and commit**

```bash
test -f docs/superpowers/audits/2026-05-15-perf-audit/1-detail/3-cost.md && \
  grep -c "^|" docs/superpowers/audits/2026-05-15-perf-audit/1-detail/3-cost.md
git add docs/superpowers/audits/2026-05-15-perf-audit/1-detail/3-cost.md
git commit -m "audit(unit-3): cost analyzer report"
```

---

## Task 4: Unit 4 — Frontend Profiler (Local-worker)

**Files:**
- Create: `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/4-frontend.md`

**Delegation:** Subagent (general-purpose).

- [ ] **Step 1: Build frontend and capture chunk sizes**

```bash
cd frontend
npm run build 2>&1 | tee /tmp/frontend-build.log
ls -lah dist/assets/*.js dist/assets/*.css | sort -k5 -h -r | head -30
```

Record top 30 largest chunks.

- [ ] **Step 2: Scan dead code**

```bash
cd frontend
npx knip --reporter compact > /tmp/knip-report.txt 2>&1 || true
wc -l /tmp/knip-report.txt
```

- [ ] **Step 3: TypeScript check**

```bash
cd frontend
npx tsc --noEmit 2>&1 | tee /tmp/tsc-report.txt
grep -c "error TS" /tmp/tsc-report.txt || echo "0"
```

- [ ] **Step 4: Run Lighthouse on 3 pages**

Replace `<PROD_URL>` with URL from Task 0 Step 2:
```bash
cd frontend
for page in /chat /dashboard /knowledge; do
  npx lighthouse "<PROD_URL>${page}" \
    --output=json --quiet \
    --output-path="/tmp/lh-${page//\//-}.json" \
    --chrome-flags="--headless --no-sandbox" || echo "lighthouse failed for $page"
done
```

Extract LCP, INP, CLS, TBT, performance score from each JSON.

- [ ] **Step 5: Write Unit 4 report**

Write `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/4-frontend.md`:

```markdown
# Unit 4: Frontend Profiler

## Bundle Analysis

### Top 30 Chunks by Size
| File | Size | gzipped (if available) |
|------|------|------------------------|

## Dead Code (knip)
| Type | Count |
|------|-------|
| Unused files | ... |
| Unused exports | ... |
| Unused types | ... |

Sample top 20 unused exports:
- `src/...` — `<exportName>`

## TypeScript Health
- Errors: <count>
- Top 5 error patterns: <list>

## Web Vitals per Page

| Page | LCP (ms) | INP (ms) | CLS | TBT (ms) | Perf score |
|------|----------|----------|-----|----------|-----------|
| /chat | ... |
| /dashboard | ... |
| /knowledge | ... |

## Findings

### Finding 1: <title>
- **Evidence:** <chunk size / Lighthouse score>
- **Impact:** <user-perceived effect>
- **Root cause hypothesis:** <why>
- **Fix candidates:** <list>

## Status: 🟢/🟡/🔴
Threshold: all 3 pages LCP < 2.5s, INP < 200ms, CLS < 0.1 = 🟢; 1 page miss = 🟡; ≥ 2 miss = 🔴
```

- [ ] **Step 6: Verify and commit**

```bash
test -f docs/superpowers/audits/2026-05-15-perf-audit/1-detail/4-frontend.md && \
  grep -c "^|" docs/superpowers/audits/2026-05-15-perf-audit/1-detail/4-frontend.md
git add docs/superpowers/audits/2026-05-15-perf-audit/1-detail/4-frontend.md
git commit -m "audit(unit-4): frontend profiler report"
```

---

## Task 5: Unit 5 — Reliability Scanner (Local-worker)

**Files:**
- Create: `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/5-reliability.md`

**Delegation:** Subagent (general-purpose).

- [ ] **Step 1: Query Sentry for unresolved issues 7d**

Tool: `mcp__sentry__search_issues`
```
organizationSlug: <resolved>
naturalLanguageQuery: "unresolved issues from last 7 days sorted by event count"
limit: 50
```

- [ ] **Step 2: Query Sentry for error rate per endpoint**

Tool: `mcp__sentry__search_events`
```
naturalLanguageQuery: "transactions with highest error rate in last 7 days, top 20"
limit: 20
```

- [ ] **Step 3: Query failed_jobs table**

```sql
SELECT
  payload::jsonb->>'displayName' AS job_class,
  COUNT(*) AS failure_count,
  MAX(failed_at) AS last_failure,
  substring(exception, 1, 200) AS exception_snippet
FROM failed_jobs
WHERE failed_at > NOW() - INTERVAL '7 days'
GROUP BY job_class, exception_snippet
ORDER BY failure_count DESC
LIMIT 30;
```

- [ ] **Step 4: Search CircuitBreakerService events in Sentry**

Tool: `mcp__sentry__search_events`
```
naturalLanguageQuery: "logs or breadcrumbs containing 'CircuitBreaker' from last 7 days"
limit: 50
```

- [ ] **Step 5: Write Unit 5 report**

Write `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/5-reliability.md`:

```markdown
# Unit 5: Reliability Scanner

## Top 50 Unresolved Errors (7d)
| Rank | Issue | Events | Users | Last seen | Sentry link |
|------|-------|--------|-------|-----------|-------------|

## Error Rate by Endpoint (top 20)
| Endpoint | Total req | Errors | Error % |
|----------|-----------|--------|---------|

## Failed Jobs (7d)
| Job class | Count | Last failure | Exception |
|-----------|-------|--------------|-----------|

## Circuit Breaker Events (7d)
| Service | Open events | Last opened | Recovery time avg |
|---------|-------------|-------------|--------------------|

## Findings

### Finding 1: <title>
- **Evidence:** <Sentry URL or query row>
- **Impact:** <X users affected, Y events/day>
- **Root cause hypothesis:** <why>
- **Fix candidates:** <list>

## Status: 🟢/🟡/🔴
Threshold: error rate < 0.5% globally = 🟢, < 2% = 🟡, ≥ 2% = 🔴
```

- [ ] **Step 6: Verify and commit**

```bash
test -f docs/superpowers/audits/2026-05-15-perf-audit/1-detail/5-reliability.md && \
  grep -c "^|" docs/superpowers/audits/2026-05-15-perf-audit/1-detail/5-reliability.md
git add docs/superpowers/audits/2026-05-15-perf-audit/1-detail/5-reliability.md
git commit -m "audit(unit-5): reliability scanner report"
```

---

## Task 6: Unit 6 — Throughput Inspector (Opus)

**Files:**
- Create: `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/6-throughput.md`

- [ ] **Step 1: Webhook ingestion rate**

Tool: `mcp__sentry__search_events`
```
naturalLanguageQuery: "request count per minute for transactions matching LineWebhook or TelegramWebhook in last 7 days, show p50 p95 p99 rate"
limit: 50
```

- [ ] **Step 2: Job processing latency**

Tool: `mcp__sentry__search_events`
```
naturalLanguageQuery: "spans tagged with queue.process for last 7 days, sorted by duration, top 30"
limit: 30
```

If queue tracing not enabled, query Redis directly for current queue depth:
```bash
railway run --service Redis redis-cli LLEN queues:default 2>/dev/null || echo "N/A"
```

- [ ] **Step 3: Worker utilization (Railway metrics)**

Try `mcp__railway__list-services` then per-service metrics. If not in API, record from user-provided info in Task 0.

- [ ] **Step 4: Write Unit 6 report**

Write `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/6-throughput.md`:

```markdown
# Unit 6: Throughput Inspector

## Webhook Ingestion Rate (7d)
| Endpoint | Req/min p50 | p95 | p99 | Max burst |
|----------|-------------|-----|-----|-----------|
| LineWebhook | ... |
| TelegramWebhook | ... |
| FacebookWebhook | ... |

## Job Latency (top 30 slowest)
| Job class | p50 (ms) | p95 (ms) | Calls/day |
|-----------|----------|----------|-----------|

## Current Queue Depth
| Queue | Depth | Avg/peak last 7d |
|-------|-------|-------------------|

## Worker Utilization
| Service | CPU % avg | CPU % peak | Memory MB |
|---------|-----------|------------|-----------|

## Findings

### Finding 1: <title>
- **Evidence:** <metric row>
- **Impact:** <e.g., "webhook bursts of N req/min saturate worker">
- **Root cause hypothesis:** <why>
- **Fix candidates:** <list>

## Status: 🟢/🟡/🔴
Threshold: queue depth < 100, worker CPU < 70% peak = 🟢; depth < 500 or CPU < 90% = 🟡; otherwise 🔴
```

- [ ] **Step 5: Verify and commit**

```bash
test -f docs/superpowers/audits/2026-05-15-perf-audit/1-detail/6-throughput.md && \
  grep -c "^|" docs/superpowers/audits/2026-05-15-perf-audit/1-detail/6-throughput.md
git add docs/superpowers/audits/2026-05-15-perf-audit/1-detail/6-throughput.md
git commit -m "audit(unit-6): throughput inspector report"
```

---

## Task 7: Unit 7 — Code Quality Scanner (Local-worker)

**Files:**
- Create: `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/7-code-quality.md`

**Delegation:** Subagent (general-purpose).

- [ ] **Step 1: Find largest backend files**

```bash
find backend/app -name "*.php" -not -path "*/vendor/*" -exec wc -l {} + 2>/dev/null | \
  sort -rn | head -30 > /tmp/php-big.txt
cat /tmp/php-big.txt
```

- [ ] **Step 2: Find largest frontend files**

```bash
find frontend/src -name "*.tsx" -o -name "*.ts" -not -path "*/node_modules/*" -exec wc -l {} + 2>/dev/null | \
  sort -rn | head -30 > /tmp/ts-big.txt
cat /tmp/ts-big.txt
```

- [ ] **Step 3: Backend code style check**

```bash
cd backend
vendor/bin/pint --test 2>&1 | tee /tmp/pint.txt
grep -c "incorrect" /tmp/pint.txt || echo "0"
```

- [ ] **Step 4: Frontend lint**

```bash
cd frontend
npm run lint 2>&1 | tee /tmp/lint.txt
grep -cE "(error|warning)" /tmp/lint.txt || echo "0"
```

- [ ] **Step 5: Count methods in top 10 biggest PHP files**

For each file in `/tmp/php-big.txt` top 10:
```bash
grep -cE "^[[:space:]]+(public|private|protected) function" <file>
```
Record method count.

- [ ] **Step 6: Write Unit 7 report**

Write `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/7-code-quality.md`:

```markdown
# Unit 7: Code Quality Scanner

## Backend — Largest Files (top 30)
| LOC | File | Method count | Service category |
|-----|------|--------------|------------------|

## Frontend — Largest Files (top 30)
| LOC | File |
|-----|------|

## Pint Style Issues
- Files with issues: <N>
- Top patterns: <list>

## ESLint Issues
- Errors: <N>
- Warnings: <N>
- Top rules: <list>

## Findings

### Finding 1: <title>
- **Evidence:** <file:line>
- **Impact:** <e.g., "RAGService at 1200 LOC, 28 methods — hard to test/maintain">
- **Root cause hypothesis:** <e.g., "missed extraction during V12 prompt refactor">
- **Fix candidates:** <list>

## Status: 🟢/🟡/🔴
Threshold: zero files > 500 LOC in app/Services = 🟢; ≤ 3 files = 🟡; > 3 files = 🔴
```

- [ ] **Step 7: Verify and commit**

```bash
test -f docs/superpowers/audits/2026-05-15-perf-audit/1-detail/7-code-quality.md && \
  grep -c "^|" docs/superpowers/audits/2026-05-15-perf-audit/1-detail/7-code-quality.md
git add docs/superpowers/audits/2026-05-15-perf-audit/1-detail/7-code-quality.md
git commit -m "audit(unit-7): code quality scanner report"
```

---

## Task 8: Cross-Correlation Analysis (Opus)

**Files:**
- Read: all 7 files in `docs/superpowers/audits/2026-05-15-perf-audit/1-detail/`
- Create: `docs/superpowers/audits/2026-05-15-perf-audit/0-summary.md`
- Create: `docs/superpowers/audits/2026-05-15-perf-audit/2-priority-list.md`

- [ ] **Step 1: Read all 7 unit files into context**

```bash
for f in docs/superpowers/audits/2026-05-15-perf-audit/1-detail/*.md; do
  echo "=== $f ==="
  cat "$f"
done
```

- [ ] **Step 2: Identify cross-correlations**

For each pair below, write 1-3 sentences about what is correlated:
- Latency × Database: slow endpoint → which slow query backs it
- Cost × Code: expensive service → which file owns it
- Reliability × Throughput: error spike → which load condition triggered it
- Frontend × Latency: slow API → impact on INP
- Code Quality × any: biggest file → does it appear in any other unit's findings

Output as a list of "Correlation N" entries.

- [ ] **Step 3: Aggregate findings into priority list with scores**

For every Finding across 7 unit files, score using formula `(impact × 3) + (confidence × 2) - effort - risk` on 1-5 scale per axis.

Write `docs/superpowers/audits/2026-05-15-perf-audit/2-priority-list.md`:

```markdown
# Priority List — 2026-05-15

Sorted by score descending.

| # | Title | Unit | Impact | Conf | Effort | Risk | Score | Phase |
|---|-------|------|--------|------|--------|------|-------|-------|
| 1 | <title> | 1,2 | 5 | 5 | 2 | 2 | 23 | 1 |
| 2 | <title> | ... |
| ... |

## Cross-Correlation Patterns Found
1. <pattern 1>
2. <pattern 2>
3. <pattern 3>

## Phase 1 Candidates (score ≥ 16)
- #1 <title>
- #2 <title>
- #3 <title>

## Phase 2 Backlog (score 10-15)
- ...

## Skipped (score < 10)
- ...
```

- [ ] **Step 4: Write executive summary**

Write `docs/superpowers/audits/2026-05-15-perf-audit/0-summary.md`:

```markdown
# Performance Audit Summary — 2026-05-15

## Health Snapshot
| Dimension | Status | Key Metric | Threshold |
|-----------|--------|------------|-----------|
| Latency | 🟢/🟡/🔴 | p95 = Xms | < 500ms |
| Cost | 🟢/🟡/🔴 | $X/mo, $Y/conv | trend |
| Throughput | 🟢/🟡/🔴 | Z jobs/min | depth < 100 |
| Reliability | 🟢/🟡/🔴 | error rate X% | < 0.5% |
| UX (Web Vitals) | 🟢/🟡/🔴 | LCP X, INP Y, CLS Z | LCP<2.5s INP<200 CLS<0.1 |
| Code Quality | 🟢/🟡/🔴 | N files > 500 LOC | 0 in Services |

## Top 10 Priorities (preview)
See `2-priority-list.md` for full table.

| # | Title | Score |
|---|-------|-------|
| 1 | ... | ... |
| ... | ... | ... |
| 10 | ... | ... |

## Recommended Phase 1
Items: #1, #2, #3 from priority list
Combined effort: <X days>
Expected impact: <summary>
Detailed spec: `docs/superpowers/specs/2026-05-15-perf-phase1-design.md` (Task 9)

## Cross-Correlation Highlights
1. <pattern>
2. <pattern>
3. <pattern>

## Token Ratio
- Opus tokens: <X>
- Local-worker tokens: <Y>
- Ratio (local/opus): <Z>
```

- [ ] **Step 5: Verify both files**

```bash
test -f docs/superpowers/audits/2026-05-15-perf-audit/0-summary.md
test -f docs/superpowers/audits/2026-05-15-perf-audit/2-priority-list.md
grep -c "^|" docs/superpowers/audits/2026-05-15-perf-audit/2-priority-list.md
```
Expected: priority list has ≥ 10 rows.

- [ ] **Step 6: Commit**

```bash
git add docs/superpowers/audits/2026-05-15-perf-audit/0-summary.md \
        docs/superpowers/audits/2026-05-15-perf-audit/2-priority-list.md
git commit -m "audit: summary + priority list with cross-correlation"
```

---

## Task 9: Draft Phase 1 Spec (Opus)

**Files:**
- Read: `docs/superpowers/audits/2026-05-15-perf-audit/2-priority-list.md`
- Create: `docs/superpowers/specs/2026-05-15-perf-phase1-design.md`

- [ ] **Step 1: Select Phase 1 items**

From priority list, pick 1-3 items meeting all Must rules:
- Score ≥ 16
- Combined effort ≤ 5 days
- Has monitoring + rollback plan

Prefer complementary items (e.g., index + cache for same hot path) and items covering ≥ 2 dimensions.

Reject items that:
- Touch user flow without feature flag
- Require destructive schema change without `safe-migration` skill workflow
- Touch authentication or billing logic

- [ ] **Step 2: Record baseline metrics**

For each selected item, copy from unit reports:
- Current value (p95 latency, $/mo, error rate, etc.)
- Sentry/Neon link as evidence

- [ ] **Step 3: Write Phase 1 spec**

Write `docs/superpowers/specs/2026-05-15-perf-phase1-design.md`:

```markdown
# Performance Phase 1 Design — 2026-05-15

## Problem Statement
<2-3 sentences naming the issues from priority list>

## Selected Items
### Item 1: <title> (priority list #N, score X)
- **Current baseline:** <metric + evidence link>
- **Target:** <new metric value>
- **Dimensions affected:** <list>

### Item 2: ...

## Architecture
<diagram or 2-3 sentence description of changes>

## Implementation Steps (high-level)
1. <step 1 with file path anchor>
2. <step 2 with file path anchor>
...

## Monitoring Plan
- **Metric to watch:** <name>
- **Dashboard / query:** <link or SQL>
- **Alert threshold:** <value>
- **Rollback trigger:** <condition>

## Feature Flag Strategy
<if user-flow affecting>

## Verification (post-deploy + 1 week)
- [ ] Baseline metric measured before deploy
- [ ] Metric measured 1 week after deploy
- [ ] Improvement ≥ target
- [ ] No regression in <other metric>
- [ ] Cost neutral or saving

## Rollback Procedure
<exact commands or feature flag toggle>

## Out of Scope
- Items #4+ from priority list (Phase 2 backlog)
- <anything else relevant to avoid scope creep>
```

- [ ] **Step 4: Self-review Phase 1 spec**

Check:
1. Placeholders — any `<...>` not filled? Fix inline.
2. Each item has measurable baseline + target.
3. Rollback plan is executable, not vague.
4. Combined effort estimate ≤ 5 days.

- [ ] **Step 5: Commit Phase 1 spec**

```bash
git add docs/superpowers/specs/2026-05-15-perf-phase1-design.md
git commit -m "spec: perf phase 1 design (top N items from priority list)"
```

- [ ] **Step 6: Hand off to user for review**

Print to chat:
> "Audit เสร็จแล้วครับ
> - Summary: docs/superpowers/audits/2026-05-15-perf-audit/0-summary.md
> - Priority list: docs/superpowers/audits/2026-05-15-perf-audit/2-priority-list.md
> - Phase 1 spec: docs/superpowers/specs/2026-05-15-perf-phase1-design.md
>
> กรุณา review Phase 1 spec ครับ ถ้า approve ผมจะใช้ `writing-plans` skill เพื่อทำ implementation plan ต่อ"

---

## Task 10: Final Verification

- [ ] **Step 1: Hard exit criteria check**

```bash
ls -la docs/superpowers/audits/2026-05-15-perf-audit/0-summary.md
ls -la docs/superpowers/audits/2026-05-15-perf-audit/1-detail/{1,2,3,4,5,6,7}-*.md
ls -la docs/superpowers/audits/2026-05-15-perf-audit/2-priority-list.md
ls -la docs/superpowers/specs/2026-05-15-perf-phase1-design.md
```

Expected: all 10 files exist (1 summary + 7 unit detail + 1 priority list + 1 phase1 spec).

- [ ] **Step 2: Priority list row count check**

```bash
grep -c "^| [0-9]" docs/superpowers/audits/2026-05-15-perf-audit/2-priority-list.md
```
Expected: ≥ 10.

- [ ] **Step 3: Phase 1 candidate count check**

```bash
grep -E "^\| [0-9]+ \|.+\| (1[6-9]|2[0-9])" docs/superpowers/audits/2026-05-15-perf-audit/2-priority-list.md | wc -l
```
Expected: ≥ 3.

- [ ] **Step 4: BLOCKED scan**

```bash
grep -rin "BLOCKED" docs/superpowers/audits/2026-05-15-perf-audit/ || echo "no blockers"
```
Expected: `no blockers` or all BLOCKED items have resolution note next to them.

- [ ] **Step 5: Token ratio report**

Print final token usage from session telemetry:
> "Opus tokens: <X>, Local-worker tokens: <Y>, ratio: <Y/X>"
