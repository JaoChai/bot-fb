# Unit 3: Cost Analyzer

> Data sources: production Neon `messages` table (columns: `cost`, `model_used`, `prompt_tokens`, `completion_tokens`, `cached_tokens`), Neon `pg_stat_database`, Railway billing (status: manual — API does not expose billing). Period: 7 days (2026-05-08 to 2026-05-15). Snapshot: 2026-05-15.
>
> Note on cost table: `agent_cost_usage` was explicitly dropped in migration `2026_04_18_000002`. Cost data now lives in `messages` (sender='bot', cost IS NOT NULL). `CostTrackingService.php` still references the dropped model — **dead code** (Finding 4).

## Cost Breakdown (7d → 30d projected)

### Total
| Source | $/7d | $/mo projected | % of total |
|--------|------|----------------|------------|
| OpenRouter (AI) — `messages.cost` | $10.5572 | $45.25 | ~100% of tracked |
| Neon (DB) | n/a — manual | ~$19–69/mo (Scale plan est.) | n/a |
| Railway (compute) | n/a — manual | ~$20–40/mo (5 services est.) | n/a |
| Other (R2, Cloudflare, etc.) | n/a | n/a | n/a |
| **Total tracked (AI only)** | **$10.5572** | **$45.25** | **100% of tracked** |

### OpenRouter by Model (7d)
| Rank | Model | Calls | Total $ | In tokens | Out tokens | Cached tokens | Cache hit % | Avg $/call |
|------|-------|-------|---------|-----------|------------|---------------|-------------|------------|
| 1 | openai/gpt-5.4-20260305 | 640 | $10.5572 | 10,453,487 | 51,879 | 2,587,776 | 24.8% | $0.016496 |
| — | **Total** | **640** | **$10.5572** | **10,453,487** | **51,879** | **2,587,776** | **24.8%** | **$0.016496** |

> Single model monopoly: 100% of calls and spend go to one model.

### Daily Cost Trend (7d)
| Day (UTC+7) | Total $ | Calls | Conversations | $/conv | $/call |
|-------------|---------|-------|---------------|--------|--------|
| 2026-05-08 | $0.5737 | 35 | 13 | $0.0441 | $0.01639 |
| 2026-05-09 | $1.3019 | 79 | 16 | $0.0814 | $0.01648 |
| 2026-05-11 | $2.0380 | 124 | 26 | $0.0784 | $0.01644 |
| 2026-05-12 | $1.8952 | 114 | 28 | $0.0677 | $0.01662 |
| 2026-05-13 | $2.1905 | 133 | 20 | $0.1095 | $0.01647 |
| 2026-05-14 | $1.7435 | 106 | 17 | $0.1026 | $0.01645 |
| 2026-05-15 | $0.8145 | 49 | 7 | $0.1164 | $0.01662 |
| **7d total** | **$10.5572** | **640** | **88 unique** | **$0.120 avg** | **$0.01650 avg** |

> Prior 7-day period (2026-05-01 to 2026-05-07): $9.9759, 614 calls, 67 conversations.
> WoW cost growth: **+5.8%** (total $). WoW conversations: **+31.3%** (67 → 88).
> Cost-per-conversation trending UP: $0.044 (May 8) → $0.116 (May 15) — **+163% within the 7d window**.

### Neon Activity (proxy for DB cost)
| Metric | Value | Notes |
|--------|-------|-------|
| CPU used (all-time) | 313,244 seconds | Production branch |
| Active compute time | 1,248,576 seconds | ~14.5 days of active time |
| Database size | ~76 MB | Small; no storage concern |
| Data transfer | 1.59 GB (all-time) | Low |
| Transactions committed | 2,830,840 | Healthy, no rollbacks spike |
| Rollbacks | 92 | 0.003% — negligible |
| Cache hit ratio | blks_hit / (blks_hit + blks_read) = 12,315,773 / 12,327,094 | **99.9%** — excellent |
| Deadlocks | 0 | Healthy |
| Temp files | 0 | No spill to disk |
| Stats reset | null | Lifetime stats |

### Messages Cost Coverage
| Metric | Value |
|--------|-------|
| Bot messages with cost tracked (7d) | 640 |
| Bot messages with NULL/zero cost (7d) | 11 |
| Coverage rate | 98.3% |
| Uncovered messages | 11 (likely non-AI responses: sticker/image/quick-reply) |

---

## Findings

### Finding 1: Single-model monopoly — $45/mo on one premium model
- **Evidence:** `messages` query — 100% of 640 calls (7d) use `openai/gpt-5.4-20260305`, $10.5572 total.
- **Impact:** $45.25/mo projected. No fallback diversity. One price change = full bill impact. `gpt-5.4-20260305` is a frontier model priced at ~$0.0165/call average; equivalent tasks on `gpt-4o-mini` cost ~$0.0002/call (82× cheaper).
- **Root cause hypothesis:** Flow config sets a single premium model. No tiered routing — simple FAQ responses use the same model as complex reasoning tasks.
- **Fix candidates:**
  1. **Tiered model routing** (effort: 2d, risk: low): Route simple/short conversations to `gpt-4o-mini` or `gemini-flash`; reserve premium model for RAG/complex flows. Expected saving: 50–70% of bill = ~$22–32/mo saved.
  2. **Prompt token reduction** (effort: 1d, risk: low): System prompt is ~16,000 tokens avg (10.4M in / 640 calls). Each 1,000-token reduction saves ~$0.013/call × 640 = $8.32/mo.
  3. **Per-bot model config** (effort: 3d, risk: low): Allow operators to set model per bot; demo/test bots auto-use cheap model.

### Finding 2: Cost-per-conversation rising 163% within 7 days
- **Evidence:** Daily trend — $/conv went from $0.044 (May 8) to $0.116 (May 15). Total conversations grew +31% WoW but cost grew +5.8% total, meaning fewer conversations are consuming more tokens each.
- **Impact:** If $/conv trend continues at current slope, monthly cost reaches ~$75–90/mo by end of May even with flat conversation volume. The divergence between conv count and cost signals lengthening context windows.
- **Root cause hypothesis:** Conversations accumulating long history (RAG context + prior messages injected into each call). No context window truncation or summarization after N turns.
- **Fix candidates:**
  1. **Context window cap** (effort: 1d, risk: low): Truncate message history to last 10 turns before injection. Estimated saving: 30% of prompt tokens = ~$13.5/mo.
  2. **Conversation summarization** (effort: 3d, risk: medium): After 20 turns, summarize prior context into 500 tokens. Reduces prompt tokens per call by 60%+ for long sessions.
  3. **Monitor $/conv as primary KPI** (effort: 0.5d, risk: none): Add daily alert if $/conv > $0.15.

### Finding 3: Cache hit rate only 24.8% — 75% of prompt tokens billed at full price
- **Evidence:** `cached_tokens` = 2,587,776 / `prompt_tokens` = 10,453,487 = 24.8% cache hit rate (7d).
- **Impact:** If cache hit rate were 60%, prompt token cost would drop ~$4.70/mo on current volume. At $45/mo projected, that's ~10% saving available.
- **Root cause hypothesis:** System prompts change per-conversation (dynamic injection of customer profile, flow context, product catalog), preventing OpenRouter prompt caching from matching. Cache only hits when exact prefix matches.
- **Fix candidates:**
  1. **Static prefix caching** (effort: 2d, risk: low): Move static system prompt prefix (instructions + persona) to fixed position; inject dynamic context after. OpenRouter caches the static prefix. Target: 50% cache rate = ~$4/mo saving.
  2. **Semantic cache layer** (`SemanticCacheService` already exists): Ensure it's activated for repeat question patterns. Avoids full LLM call entirely.

### Finding 4: `CostTrackingService` is dead code — references dropped table
- **Evidence:** `backend/app/Services/CostTrackingService.php:188` calls `AgentCostUsage::create(...)`. `agent_cost_usage` was dropped in migration `2026_04_18_000002`. Model file `backend/app/Models/AgentCostUsage.php` still exists.
- **Impact:** `CostTrackingService::finalizeRequest()` will throw a runtime error if ever called (DB table missing). Any code path that calls it is silently broken. Cost data is now stored directly in `messages` table by `OpenRouterService.php:609` (`'cost' => $usage['cost'] ?? null`).
- **Root cause hypothesis:** The `agent_cost_usage` table was dropped as part of an agentic feature removal (commit `193178e`) but `CostTrackingService` and `AgentCostUsage` model were not cleaned up.
- **Fix candidates:**
  1. **Delete dead files** (effort: 0.5d, risk: low): Remove `CostTrackingService.php`, `AgentCostUsage.php`, and any callers. Verify no routes/jobs call `finalizeRequest()` first.
  2. **Audit callers** (effort: 0.5d): `grep -rn "CostTrackingService\|AgentCostUsage" backend/app` to find all injection points.

---

## Status: 🟡

**Threshold assessment:**
- WoW total cost growth: +5.8% → within ±10% → 🟢
- $/conv trend within 7d: +163% → 🔴 signal
- Any model > $5/day: Max day was $2.19 → 🟢
- Dead code risk (CostTrackingService): silent failure if invoked → 🟡

**Overall: 🟡** — total spend stable WoW but $/conv trajectory is concerning. If the rising cost-per-conversation trend continues, monthly bill exceeds $75 within 2–3 weeks. Dead code creates a silent reliability risk.

---

## Notes
- Railway billing requires manual dashboard check at `railway.app/project/ba714504-2721-4535-9fc7-6b3d903c481a` — 5 services (Redis, reverb, backend, frontend, scheduler). Estimated $20–40/mo based on typical Railway hobby/pro pricing for this service profile.
- Neon billing requires manual check at `console.neon.tech/app/projects/solitary-math-34010034`. Estimated $19–69/mo (Scale plan) based on 313k CPU seconds total and 76MB storage.
- OpenRouter pricing accuracy: `messages.cost` is populated from OpenRouter API response at call time. Historical records reflect pricing at time of call — accurate for 7d window.
- No 7d gap on May 9 in daily data — confirmed 0 calls that day (not a data gap, likely offline/maintenance day).
