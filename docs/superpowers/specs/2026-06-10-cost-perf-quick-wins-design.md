# Cost + Performance Quick-Wins — Design Spec

**Date:** 2026-06-10
**Status:** Approved (design) → next: writing-plans
**Scope:** Backend + Frontend + DevOps, low-risk quick wins only
**Owner goal (confirmed in brainstorming):** cost ↓ **and** performance ↑ matter equally; system is **small (~1 active bot), not rushing to grow**; appetite is **focused low-risk quick wins, ~1–2 weeks**, no deep refactors.

---

## 1. Context & framing

This is the **next wave** of an already-mature optimization effort, not a fresh start. Prior shipped work (verified live 2026-06-10): Redis migration for cache/session/queue, Neon right-sizing, the perf-audit Phase 1, and the 2026-05 refactor initiative (Sprints 1/3/5).

A live verification sweep (5 parallel agents against Railway MCP + Neon MCP + direct code reads, 2026-06-10) **corrected several stale assumptions** from the 2026-05-15 audit. Those corrections are the foundation of this plan — we explicitly do **not** redo already-done work.

### 1.1 Stale-audit corrections (already done — do NOT redo)

| 2026-05-15 audit said | Live reality 2026-06-10 |
|---|---|
| `web:` runs `php artisan serve` (dev server) | **False.** Prod runs **nginx + php-fpm via `backend/Dockerfile`** (supervisord). `Procfile` is vestigial/unused. |
| OpenRouter = single model `gpt-5.4`, ~$45/mo | **Outdated.** Per-bot model config is live. Bot 26 primary = `google/gemini-3.5-flash` (cheap). 30d spend **$43.50**; 7d → run-rate **~$32/mo**. |
| Add tiered model routing | Infra exists (`resolveSmartChatModel`) but disabled; low ROI (primary is already cheap). |
| Add context-window cap | Done (count-based, `context_window=10` on all bots). |
| Neon CU 2→1 | Now **0.25–0.5 CU** (smaller than audit claimed). |
| VACUUM FULL `bots`/`rag_cache`/`personal_access_tokens` | **Moot** — total bloat <1MB; not worth a maintenance window. |
| Frontend React.lazy / skeleton / manualChunks / drop sync-persister | All done. |
| Restore CircuitBreaker → Sentry | Done, not regressed. |

### 1.2 Honest cost reality

The system is already well-tuned. Total Railway footprint ≈ 0.39 GB RAM across 5 single-replica services; Neon is right-sized; OpenRouter run-rate ~$32/mo. **At ~1 bot, realistic absolute savings from this round are single-digit $/mo.** The genuine value is:

1. **Perceived performance / UX** — web LCP (audit was 4.1–4.2s), bot reply latency.
2. **Unit-economics hygiene** — prompt caching/ordering so cost stays flat as usage grows.
3. **Footgun removal** — duplicate scheduler service, dev-server `Procfile` that could boot on a redeploy.

We will not oversell cost savings.

> **Anchor caveat:** All `file:line` references below are as-of live verification 2026-06-10. Line numbers drift — the implementation phase (writing-plans → executing) must re-confirm each anchor with grep/read before editing. Memory lessons from the prior initiative: the spec lags the codebase fast; always pre-flight verify against production state.

---

## 2. Scope — the work items (12 code wins + 3 ops actions)

All items below were verified live (confidence `live`) unless marked `inferred`. Labels: `O*` = Railway ops (not git), `D*`/`F*`/`A*`/`B*` = code wins grouped into the 4 PRs in §3. (Earlier brainstorming labeled the duplicate-scheduler win `D1`; it is `O2` here. `F5` spans an ops half `O3` + a CI half.)

### DevOps / Railway ops (done via dashboard/MCP, not git)

- **O1 — Confirm backend builder = Dockerfile** *(gate)*
  `get_service_config(backend 36066744)` reports `Builder: RAILPACK` while build logs are clearly a Dockerfile build. **Must be resolved first** — it gates D2 and is itself a footgun (a Railpack redeploy would boot the dev-server stack). Action: confirm/correct in Railway UI before deleting `Procfile`/`nixpacks.toml`.

- **O2 — Delete the redundant standalone `scheduler` Railway service** (was item D1)
  Backend's supervisord already runs `schedule:run` every 60s. The separate `scheduler` service (`7454f43a`) shows 0 CPU / 0 RAM across 24h (not actually running), but is a latent **2×-cron hazard** if it ever restarts (double `db:ping`, double lead-recovery sending LINE messages, double cleanup DELETEs). Risk: low. Rollback: re-create from Dockerfile.

- **O3 — Remove `NIXPACKS_NO_CACHE=1` from frontend service** (part of F5)
  Forces a full `npm ci` + no-layer-cache rebuild every deploy. Confirm *why* it was set (likely a stale-cache workaround) before removing.

### PR-1 — DevOps / build hygiene *(depends on O1)*

- **D2** — Delete `backend/Procfile` + `backend/nixpacks.toml` (stale, unused; `Procfile` line 1 = `php artisan serve` dev server).
- **D3** — `backend/Dockerfile` `[program:queue-worker-db]`: `--sleep 3 → 60`. worker-db is the **Redis-outage fallback** consumer (`app/Support/QueueRouter.php:24-31` returns `database` only when Redis down) — **keep it**, just stop it polling Neon's empty `jobs` table ~1/sec. `inferred` on exact polling-cost magnitude.
- **F4** — Remove dead dep `@radix-ui/react-checkbox` + orphan `frontend/src/components/ui/checkbox.tsx` (knip-confirmed unused).
- **F5 (CI part)** — Move `prebuild` (`NODE_ENV=test vitest run`, `frontend/package.json:11`) out of the Railway build into CI (GitHub Actions), so deploy builds don't run the full test suite.

### PR-2 — Frontend perf *(measure LCP before/after)*

- **F1** — Defer `pusher-js` + `laravel-echo` off the eager first-load. They sit in the 101.7 KB-gzip eager `index` chunk loaded on **every** page (incl. `/login`), but realtime is only needed post-auth. Convert `stores/authStore.ts:4` static import of `@/lib/echo` to a dynamic `import()` inside login/logout handlers. **Risk: med** — touches realtime connect lifecycle; must verify chat realtime still connects after login.
- **F2** — Lazy-load recharts chart components (`vendor-charts` = 372 KB / 109.5 KB gzip, only used by dashboard/orders). `React.lazy` `DualAxisChart` / `ProductsSummaryCard` / `OrdersAnalytics` so metric cards paint first.
- **F3** — Trim / self-host web fonts. `frontend/index.html:14-16` render-blocking request for 9 font files (Inter 4 weights + Noto Sans Thai 5 weights) on the LCP critical path. Trim to 2–3 weights/family and/or self-host via `@fontsource` to kill googleapis+gstatic round trips. `inferred` on LCP delta (no Lighthouse run yet).

### PR-3 — AI prompt efficiency *(sanity-check bot output before/after)*

- **A1** — Reorder prompt so the large static persona is the cacheable prefix. Dynamic content is currently **prepended** ahead of the static persona (`StockInjectionService.php:99-105`; `StreamingResponseOrchestrator.php:401-403`; `RAGService::buildEnhancedPrompt` ~`355-376`), defeating OpenRouter/gemini prefix caching (gemini cache-hit only 2.5%). Move memory/stock injection **after** the static persona. **Risk: low** but behavior-sensitive — verify stock info still appears and output is materially unchanged.
- **A2** — Skip the decision-model round-trip for greeting/trivial turns. `StreamingResponseOrchestrator.php:188-210` runs `analyzeIntent` unconditionally when `decision_model` is set. Gate it behind the existing `isSimpleMessage` / `SIMPLE_MESSAGE_PATTERN` (`RAGService.php:25,116`). Saves one ~300–800 ms LLM hop on greetings (which never need the KB).

### PR-4 — Backend surgical

- **B1** — Remove the `DB::transaction` wrapping two read-only `->refresh()` calls in `ProcessAggregatedMessages::shouldGenerate()` (`~215-241`) — no writes/locks, so it only adds BEGIN/COMMIT round-trips. Replace with plain `refresh()`.
- **B2** — Fix N+1 in `VipController::index` (`~33-48`): one `Order` aggregate query per conversation in a loop → single `whereIn(...)->groupBy(...)` keyed lookup. (Admin endpoint, low traffic — clean safe win.)
- **B3** — Drop two `idx_scan=0` indexes on the hot `conversations` table: `idx_conversations_webhook_lookup` (152 KB) + `conversations_last_message_id_index` (128 KB). Use `DROP INDEX CONCURRENTLY` in a migration with `public $withinTransaction = false`. Reduces write amplification on the most-updated table.

### Explicitly out of scope (this round)

- ProcessLINEWebhook (1,525 LOC) rewrite — too risky, no quick win.
- Swapping the web server / framework — prod already nginx+php-fpm; non-issue.
- Merging `worker-llm` / `worker-fast` — the split is intentionally correct (prevents a 160s LLM job head-of-line-blocking fast webhook acks).
- Deleting `worker-db` outright — it is the Redis-outage fallback consumer; only tune it.

---

## 3. Sequencing & dependencies

1. **O1** (builder confirm) — first; gates D2.
2. **O2** (delete duplicate scheduler) — early; correctness win, independent.
3. **PR-1** (after O1) → **PR-4** can proceed in parallel (independent).
4. **PR-2** and **PR-3** are independent of each other and of PR-1/PR-4.
5. Each PR: build + tests → 2-stage review (subagent-driven, per prior initiative) → merge → **24h Sentry watch** before the next merge on the same path.

No PR depends on another's code except D2 ← O1. Parallelizable where review bandwidth allows.

---

## 4. Verification & rollout

Per CLAUDE.md goal-driven execution — each item has a verifiable success check:

| Item | Success criterion (verifiable) |
|---|---|
| O1 | Railway backend builder confirmed = Dockerfile; next deploy still boots supervisord stack |
| O2 | `scheduler` service gone; backend logs still show `schedule:run` firing; no double-execution |
| O3 | `NIXPACKS_NO_CACHE` removed; frontend build succeeds with layer cache; deploy time ↓ |
| D2 | `Procfile`/`nixpacks.toml` removed; redeploy still builds from Dockerfile |
| D3 | `--sleep=60` in Dockerfile; Neon `jobs` table poll rate drops (pg_stat_user_tables seq_scan growth ↓) |
| F4 | `knip` clean for that dep; `@radix-ui/react-checkbox` absent from `package.json` |
| F5 | Railway build no longer runs vitest; CI runs it instead; build minutes ↓ |
| F1 | `grep "pusher"` in eager `dist/assets/index-*.js` → absent; chat realtime still connects post-login |
| F2 | `vendor-charts` not in dashboard first-paint preload; loads on chart mount |
| F3 | Fonts trimmed/self-hosted; Lighthouse LCP improved vs baseline |
| A1 | gemini `cached_tokens %` (Neon `messages`, 2–3 day window) trends up |
| A2 | Greeting messages no longer trigger a decision-model call (log/trace) |
| B1 | No `DB::transaction` in `shouldGenerate`; existing tests pass |
| B2 | `VipController::index` issues one grouped Order query; test added |
| B3 | Two indexes dropped; no query-plan regression on `conversations` reads |

**Baselines to capture before starting:** Lighthouse LCP on 3 pages; Neon `jobs`/`cache` seq_scan counters; gemini cache-hit %; OpenRouter 7d run-rate; eager-bundle gzip size.

**Rollout:** each PR independently revertable. Frontend perf PRs measured with Lighthouse before/after. Ops actions (O1–O3) reversible (re-create service / re-add env / re-confirm builder).

---

## 5. Phase 2 — decision-gated (deferred, NOT in this plan)

Larger levers held back because they need an owner decision or quality validation:

| Phase 2 item | Decision needed before doing it |
|---|---|
| 💤 **Neon autosuspend** (remove `db:ping` `routes/console.php`, pair with D3, relax `conversations:auto-enable-bots` from everyMinute → ~5 min) | **What Neon billing plan?** If Launch (300 CU-hr included, using ~182) savings ≈ $0. If usage-based, it matters. + accept ~0.5–1s cold-start on first request after idle (owner indicated latency-tolerant, but confirm for first-request). |
| ☁️ **Move frontend SPA → Cloudflare Pages** (drop one always-on Railway express service; `frontend/server.js` only serves static `dist/`) | Willing to do `www.botjao.com` DNS cutover? How to handle `VITE_*` build env vars (`VITE_API_URL`, `VITE_REVERB_*`). |
| ✂️ **Trim flow 24 system prompt** (36,709 chars ≈ 13.6K tokens, re-sent every turn) + **cap reasoning budget** (gemini emits ~1,122 reasoning tokens/call) | Both have a sales-bot quality dimension — require a small A/B on real conversations + owner reviews prompt content. |
| 👁️ **Re-enable frontend Sentry** (`VITE_SENTRY_DSN` unset → error monitoring effectively OFF in prod) | Intentional? If re-enabling, drop `replayIntegration` (or lazy-load Sentry) first to avoid pulling rrweb into the bundle. |

---

## 6. Open questions (resolve during implementation)

- **O1 builder discrepancy** — confirm in Railway UI which builder the *next* deploy uses (config says RAILPACK, logs say Dockerfile).
- **worker-db location** — runs as a supervisord program inside the backend container (per `backend/Dockerfile`), not a separate billable Railway service. Confirm before/while editing D3.
- **Why `NIXPACKS_NO_CACHE=1`** was set on frontend (gate for O3).
- **Neon billing plan** (gates the Phase 2 autosuspend value).
- Possible redundancy between `messages_conversation_id_created_at_index` and `idx_messages_conv_id_desc` (both used — needs EXPLAIN before any drop; **not** in this round).

---

## 7. References

- Live verification sweep: workflow `cost-perf-state-verify` (5 agents, 2026-06-10) — Railway MCP (project `ba714504-...`), Neon MCP (project `solitary-math-34010034`), direct code reads.
- Prior art: `docs/superpowers/specs/2026-05-15-perf-audit-design.md`, `2026-05-15-perf-phase1-design.md`, `2026-05-12-db-cost-reduction-design.md`, `2026-05-25-refactor-initiative-roadmap.md`.
- Key files: `backend/Dockerfile`, `backend/app/Support/QueueRouter.php`, `backend/routes/console.php`, `backend/app/Jobs/ProcessAggregatedMessages.php`, `backend/app/Http/Controllers/Api/VipController.php`, `backend/app/Services/{RAGService,StreamingResponseOrchestrator,StockInjectionService}.php`, `frontend/src/stores/authStore.ts`, `frontend/src/lib/echo.ts`, `frontend/index.html`, `frontend/vite.config.ts`, `frontend/server.js`.
