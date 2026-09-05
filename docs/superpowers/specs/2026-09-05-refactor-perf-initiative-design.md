# Refactor + Performance Initiative — Design

**Date:** 2026-09-05
**Status:** Approved in chat, pending spec review
**Path:** Architectural (brainstorming skill)
**Approach:** A — 4 tracks ordered by risk (low → high), one PR per unit, independent rollback

---

## 1. Problem

Baseline measured 2026-09-05 (all green: backend 1123 passed / 15 skipped, frontend 154 passed, lint 0 errors / 24 warnings, build OK, total JS 492 kB gzip).

| Area | Finding | Evidence |
|---|---|---|
| Runtime | **OPcache is not enabled** in production image (`php:8.4-fpm-alpine` ships without it; Dockerfile never installs/configures it). Every request recompiles PHP. | `backend/Dockerfile` — no `opcache` anywhere |
| Runtime | CMD runs `config:cache` + `route:cache` only; `event:cache` skipped | `backend/Dockerfile` CMD |
| Security | `composer audit`: 16 advisories in 2 packages (`league/commonmark` ×10, `guzzlehttp/guzzle` ×6) | `composer audit` 2026-09-05 |
| Security | `npm audit --omit=dev`: `react-router` 8.0.1 (high), `nanoid`, `postcss`, `qs` | `npm audit` 2026-09-05 |
| Deps | Laravel 13.17 → 13.30.1 available; many minor bumps on both sides | `composer outdated --direct`, `npm outdated` |
| Backend | Webhook pipeline v2 built (PR #249) but **default OFF**; legacy paths alive in all 3 jobs (LINE 941 / FB 679 / TG 558 LOC); `FacebookChannelAdapter` missing; v2 steps lack parity (dedup, stats, broadcast, lead recovery, LINE aggregation, plugins) | `docs/webhook-pipeline-v2-rollout.md` |
| Backend | LINE bot 26 (the only production bot) already runs the **LINE-specific pipeline** (`LineWebhook/*`, 1,710 LOC, live since 2026-05-16) — not legacy, not v2 | `ProcessLINEWebhook::handle()` branches |
| Backend | `RAGService` 1,099 LOC / 27 methods, split deferred since Sprint 4 (2026-05-25) | `app/Services/RAGService.php` |
| Backend | `error_log("PLUGIN DEBUG …")` ×3 leaks to prod stderr | `app/Services/FlowPluginService.php:26,33,37` |
| Frontend | Two parallel conversation hook systems both in use: `hooks/chat/*` (native-chat, newer) and `hooks/conversations/*` (via `useConversations.ts` shim). 9 hooks duplicated: `useAddNote useAddTags useBotTags useConversationStats useDeleteNote useMarkAsRead useRemoveTag useUpdateConversation useUpdateNote` | knip + import grep |
| Frontend | knip: 1 unused file, 70 unused exports, 27 unused types | `npx knip` |
| Frontend | Radix installed twice: monolith `radix-ui` (2 files) + 17 `@radix-ui/*` packages (15 `components/ui/*` files + Header + ChatPage) → `vendor-radix` 139 kB raw | `package.json`, import grep |
| Frontend | React Compiler not enabled; ESLint react-hooks v7 compiler rules produce 24 warnings | `vite.config.ts`, `npm run lint` |

## 2. Goals

1. Production PHP runs with OPcache; framework caches fully warmed at boot.
2. Zero high-severity advisories in `composer audit` and `npm audit --omit=dev`; CI fails if they return.
3. One conversation hook system in the frontend; knip reports 0 unused; one Radix package; React Compiler on with 0 compiler-rule warnings.
4. Webhook processing runs through **one** pipeline (v2) for all channels; legacy paths and both feature flags deleted after a 7-day production soak on LINE bot 26.
5. `RAGService` split into 4 focused classes with no behavior change (`prompt:eval` identical).

## 3. Decisions (user-confirmed 2026-09-05)

| # | Decision |
|---|---|
| D1 | All four tracks in scope; order 0 → 1 → 2 → 3 (3 overlaps the Track 2 soak). |
| D2 | Dependency policy: **minor/patch + security only**. No major bumps (Pest 5, PHPUnit 13, Vitest 5, TypeScript 7, lucide-react 1.x, jsdom 30, web-vitals 6, globals 17, @testing-library/jest-dom 7, @types/node 26). |
| D3 | Repo changes to Dockerfile/CI/config are allowed. Railway env vars, webhook flags and Neon settings are changed **by the user** following a runbook; Claude does not touch Railway/Neon via MCP. |
| D4 | Only **LINE** serves customers in production. Facebook/Telegram parity is verified by tests only. |
| D5 | Legacy webhook paths and both flags are **deleted in this initiative** after the soak. |
| D6 | v2 LINE step list **wraps the existing `LineWebhook/*` services** rather than re-implementing parity in the thin v2 steps. Rationale: that code is what production has run for 3+ months. |
| D7 | Dropped from scope: `schema:dump` (tests use sqlite, prod uses pgsql — dump is not portable; suite already runs in 7.7 s), PHP 8.5 in Docker (stay 8.4, matches CI), migrating `@OA\` docblocks to attributes (keeps `doctrine/annotations`), cosmetic backend cleanups (`response()->json` ×133, `protected $casts` ×18, `app()` service locator ×74) — recorded as follow-ups in §9. |

## 4. Track 0 — Infra + dependencies

**One PR. Effort 1–2 days. Risk 🟢.**

### 4.1 OPcache
- Dockerfile: add `opcache` to `docker-php-ext-install`.
- New file `backend/docker/php/opcache.ini`, copied to `/usr/local/etc/php/conf.d/`:
  ```ini
  opcache.enable=1
  opcache.enable_cli=0
  opcache.memory_consumption=128
  opcache.interned_strings_buffer=16
  opcache.max_accelerated_files=20000
  opcache.validate_timestamps=0
  opcache.save_comments=1
  ```
  `validate_timestamps=0` is safe because the image is immutable per deploy. `save_comments=1` is required for attribute/docblock reflection (Laravel, l5-swagger). JIT stays off (no measured benefit for I/O-bound API; can be evaluated later).
- CMD: replace `php artisan config:cache && php artisan route:cache` with `php artisan optimize` (config + events + routes + views, per Laravel 13 deployment docs). `migrate --force` stays.

### 4.2 Dependencies
- Backend: `composer update` constrained to current majors (`laravel/framework` → 13.30.x, reverb, sanctum, sentry, predis, telescope, pint, etc.). Resolves all 16 advisories (commonmark and guzzle are transitive; a plain update pulls patched versions).
- Frontend: `npm update` (respects caret ranges) + `npm audit fix` (no `--force`). Expected: react-router 8.3.x, axios 1.20, TanStack Query 5.102, zod 4.5, vite 8.2, vitest 4.1.11, etc.
- Keep `doctrine/annotations` (D7).

### 4.3 CI hardening (`.github/workflows/ci.yml`)
- backend-tests: add step `composer audit --abort-on-severity=high` after install.
- frontend-checks: add step `npm audit --omit=dev --audit-level=high`.
- Cache invalidation unchanged.

### 4.4 Cleanup
- Delete the three `error_log("PLUGIN DEBUG …")` lines in `FlowPluginService`. If any is load-bearing for a test, replace with `Log::debug`.

### 4.5 Verification
- `composer test` and `npm test` green; `pint --test`, `npm run lint`, `tsc --noEmit` clean.
- `composer audit` → 0 high; `npm audit --omit=dev` → 0 high.
- Post-deploy: `docker exec … php -r 'var_dump(opcache_get_status()["opcache_enabled"]);'` returns `true`; `/up` and `/api/health` 200; Sentry 24 h watch shows no new error class.
- Rollback: revert PR (image rebuild). No schema or data changes.

## 5. Track 1 — Frontend cleanup

**Two PRs. Effort 2–3 days. Risk 🟡.**

### 5.1 PR-A: single conversation hook system
Canonical location: `src/hooks/chat/`.

1. Consumers of the 9 duplicated hooks (`NotesPanel`, `TagsPanel`, `BotControl`, `useChatActions`, `ChatPage`) switch imports to `@/hooks/chat`. Signature differences between the two implementations are reconciled in favor of the `hooks/chat` version; callers adapt.
2. Hooks that exist only in the legacy set move into `hooks/chat/` verbatim: `useToggleHandover`, `useCloseConversation`, `useReopenConversation`, `useClearContext`, `useClearContextAll`, `useSendAgentMessage`, `useBulkAddTags`, plus the `useConversations`/`useInfiniteConversations`/`useConversation`/`useConversationMessages` read hooks **only if** still imported after step 1 (otherwise deleted).
3. Delete `src/hooks/conversations/` and `src/hooks/useConversations.ts`. Move the 8 contract tests to the new files.
4. Exit criteria: `grep -r "hooks/conversations\|hooks/useConversations" src` returns nothing; vitest green; manual smoke: open chat, add note, add/remove tag, mark read, toggle handover, send agent message.

### 5.2 PR-B: dead code, Radix, React Compiler
1. Apply knip findings: delete `src/components/flow-editor/index.ts`, remove the 70 unused exports and 27 unused types (delete the symbol when it has no internal use; drop only the `export` keyword when it does). Apply knip's 7 config hints. Exit: `npx knip` → 0 issues.
2. Radix: rewrite the 17 importing files to `import { X as XPrimitive } from "radix-ui"` (the monolith re-exports every primitive namespace). Remove the 17 `@radix-ui/*` entries from `package.json`. Keep the `vendor-radix` code-splitting group but change its test to `[/\\]node_modules[/\\](radix-ui|@radix-ui)[/\\]`.
3. React Compiler (per react.dev, `@vitejs/plugin-react` ≥ 6 + Vite 8):
   ```ts
   import react, { reactCompilerPreset } from "@vitejs/plugin-react"
   import babel from "@rolldown/plugin-babel"
   plugins: [tailwindcss(), react(), babel({ presets: [reactCompilerPreset()] })]
   ```
   Dev deps: `babel-plugin-react-compiler`, `@rolldown/plugin-babel`. The 24 `react-hooks/*` compiler warnings are fixed in a separate PR-C with its own plan (`docs/superpowers/plans/<date>-track1c-compiler-warnings.md`): 12 `set-state-in-effect`, 6 `refs`, 5 `exhaustive-deps`, 1 `incompatible-library` across 13 files, each needing a site-specific rewrite. The five rules stay at `warn` until PR-C lands, then move to `error`. Components the compiler cannot optimize (e.g. the `useVirtualizer` site in `MessageList.tsx`) are already skipped automatically ("Compilation Skipped") — no `"use no memo"` needed.
4. Exit: lint 0 errors (warnings deferred to PR-C); build succeeds; `vendor-radix` chunk not larger than 139.14 kB raw; Playwright smoke of Chat, Flow editor, Bots, Dashboard. **Bundle-size outcome (2026-09-05):** knip + Radix alone kept total JS at 494.6 kB gzip (baseline 492.3); enabling the React Compiler raised it to 547.2 kB (+10.6%). Accepted as the cost of compiler memoization on the re-render-heavy chat/list pages; re-measure INP/re-render counts in PR-C. Note that Rolldown output size varies run-to-run by tens of kB, so a hard gzip gate is not enforceable as written.

Rollback: revert PR. No persisted state involved (IndexedDB query cache uses `buster: 'v3'`; bump to `'v4'` in PR-A because query keys move).

## 6. Track 2 — Webhook pipeline v2: parity → enable → delete legacy

**Three PRs + 7-day soak. Effort ~1 week code. Risk 🔴 (customer-facing).**

### 6.1 Current state (verified)
`ProcessLINEWebhook::handle()` has three branches, in order: v2 shared pipeline (`WebhookPipelineV2Flag`) → LINE pipeline (`LineWebhookPipelineFlag`, **live on bot 26**) → legacy `processEvent()`. v2's shared steps are `ResolveConversationStep` (443 LOC) → `GenerateResponseStep` (43) → `SendResponseStep` (51) and lack the behaviors listed in §1. `ChannelAdapterFactory` registers only `line` and `telegram`.

### 6.2 PR-1: LINE on v2 by wrapping the proven pipeline
New adapter steps in `app/Services/Webhook/Steps/Line/`, each ~20 LOC, delegating 1:1 to the existing service:

| Step | Wraps | Short-circuits when |
|---|---|---|
| `LineGateStep` | `LineWebhookGatingService` | `GateDecision` says skip |
| `LineContextStep` | `LineWebhookContextService` | aggregation window still open |
| `LineResponseStep` | `LineWebhookResponseService` | — |
| `LineOutputStep` | `LineWebhookOutputService` | — |

`WebhookPipeline::line()` returns `[gate, context, response, output]`; the generic `ResolveConversationStep`/`GenerateResponseStep`/`SendResponseStep` are no longer used for LINE. Non-text events (sticker/image/other) keep routing through `StickerHandler` / `VisionHandler` / `NonTextHandler` from `LineContextStep`, exactly as `runPipeline()` does today. The `LineWebhook\WebhookContext` and `Webhook\WebhookContext` DTOs coexist in this PR; the LINE steps construct the former from the latter.

Tests: existing `ProcessLINEWebhookPipelineTest` runs against **both** flags on (v2) and LINE-flag-only, asserting identical persisted messages and outbound LINE calls. Fixtures reused, no new fixture format.

### 6.3 PR-2: Facebook / Telegram parity + `FacebookChannelAdapter`
Add to the generic steps (used by FB/TG only after PR-1):
- `ResolveConversationStep`: external-message-id dedup (currently in each legacy job).
- New `RecordStatsStep`: conversation/bot counters + `LeadRecoveryService::markCustomerResponded`.
- New `BroadcastStep` (last, outside the transaction): the post-commit `MessageSent` / `ConversationUpdated` broadcasts the legacy jobs emit.
- `FacebookChannelAdapter` implementing `ChannelAdapterInterface` over `FacebookService::sendMessage`; register `'facebook'` in `ChannelAdapterFactory`.
- Postback: `FacebookEventMapper` already maps postbacks; `messages.type='postback'` schema fix landed in #250, so no schema work.

Tests: extend `ProcessFacebookWebhookMapperTest` / `ProcessTelegramWebhookMapperTest` with v2-on cases asserting dedup, stats, broadcast (`Event::fake`), and adapter dispatch. Known sqlite `channel_type` limitation for Telegram create-path stays documented (pre-existing).

### 6.4 Rollout (user-operated) — runbook `docs/superpowers/runbooks/<deploy-date>-webhook-v2-rollout.md` (written in PR-2)
1. After PR-1 + PR-2 deploy: set `WEBHOOK_PIPELINE_V2_ENABLED=true`, `WEBHOOK_PIPELINE_V2_BOT_IDS=26`.
2. Soak 7 days. Stop signals (from `docs/webhook-pipeline-v2-rollout.md`): any new Sentry error class on `Jobs\Process*Webhook`, unexpected circuit-breaker OPEN, queue depth trending up. Rollback = flip flag off (no deploy).
3. On success, clear `WEBHOOK_PIPELINE_V2_BOT_IDS` (all bots) for 48 h, then proceed to PR-3.

### 6.5 PR-3: delete legacy
- Remove `processEvent()`, `runPipeline()`, `processMessagingEvent()` and the Telegram inline transaction path from the three jobs; each job becomes mapper → `WebhookPipeline::run`. Target ≤ 150 LOC each.
- Delete `LineWebhookPipelineFlag`, `WebhookPipelineV2Flag`, `config/line_webhook.php` (contains only the two flag keys), `config/webhook_pipeline_v2.php`, the `PROCESS_LINE_PIPELINE_*` and `WEBHOOK_PIPELINE_V2_*` entries in `.env.example`, and `docs/webhook-pipeline-v2-rollout.md` (superseded by this spec + runbook).
- Delete tests that only exercised the legacy branches; keep pipeline tests.
- Rollback after PR-3 is a git revert + deploy (flags no longer exist).

## 7. Track 3 — RAGService split

**One PR, executed during the Track 2 soak. Effort 2–3 days. Risk 🟡.**

New namespace `app/Services/RAG/`, verbatim method moves (no logic edits, per the D12 precedent):

| Class | Methods moved from `RAGService` |
|---|---|
| `RAGService` (orchestrator, stays at `App\Services\RAGService` for callers) | `generateResponse`, `testRAG`, `shouldSkipCache`, `getChatModelForBot`, `getFallbackChatModelForBot`, `resolveReasoningEffort`, `getApiKeyForBot` |
| `RAGKnowledgeBase` | `shouldUseKnowledgeBase`, `getKnowledgeBaseContext`, `getFlowKnowledgeBaseContext`, `flowHasKnowledgeBases`, `formatKnowledgeBaseContext`, `formatThaiContext`, `formatEnglishContext`, `applyCRAG` |
| `RAGPromptBuilder` | `buildEnhancedPrompt`, `buildPurchaseHistoryBlock`, `formatThaiDate`, `injectStockStatus`, `buildChainOfThoughtInstruction`, `getSystemPromptForBot`, `getDefaultSystemPrompt` |
| `RAGIntentDetector` | `isSimpleMessage`, `detectComplexity`, `detectToolIntent`, `detectLanguage` |

Constructor injection only (no `app()` inside the new classes). Public methods currently called from outside `RAGService` (`formatKnowledgeBaseContext`, `injectStockStatus`, `getFlowKnowledgeBaseContext`, `flowHasKnowledgeBases`, `detectComplexity`, `detectToolIntent`, `isSimpleMessage`) keep thin delegating wrappers on `RAGService` so no caller changes in this PR.

Verification: existing RAG/stock/purchase-history tests green; `php artisan prompt:eval` result identical to the pre-split run (record both in the PR); each new file ≤ 350 LOC.

## 8. Cross-cutting rules

- Every PR: `composer test`, `pint --test`, `npm test`, `npm run lint`, `tsc --noEmit`, CI green, then 24 h Sentry watch after deploy (48 h for Track 2).
- Verbatim-move rule for Track 2 PR-1 and Track 3: bodies are copied, not rewritten. Behavior changes go in separate PRs.
- No feature work rides along. Every changed line traces to a section above.
- Worktree per track (`superpowers:using-git-worktrees`); branch names `chore/track0-infra-deps`, `refactor/track1-frontend-hooks`, `refactor/track1-frontend-cleanup`, `refactor/webhook-v2-line`, `refactor/webhook-v2-parity`, `refactor/webhook-v2-remove-legacy`, `refactor/rag-split`.

## 9. Follow-ups (explicitly out of scope)

- Major version bumps (D2) — revisit after this initiative lands.
- `@OA\` docblocks → PHP attributes, then drop `doctrine/annotations`.
- Controllers: 133 `response()->json` → `ApiResponseTrait` / API Resources; `FlowController` prompt templates → config.
- Models: 18 `protected $casts` → `casts()`; 74 `app()` service-locator sites → constructor injection.
- `types/api.ts` (726 LOC) codegen from l5-swagger spec.
- Frontend Express static server → evaluate Railway static/Caddy.
- OPcache JIT evaluation with real traffic numbers.
- Sprint-3 follow-ups from the 2026-05-25 roadmap (narrow cache invalidation, shared `ConversationResponse` types) — partially subsumed by Track 1 PR-A.

## 10. References

- `docs/superpowers/specs/2026-05-25-refactor-initiative-roadmap.md` (prior sprints, D9–D18)
- `docs/superpowers/specs/2026-08-26-webhook-pipeline-refactor-design.md`, `docs/webhook-pipeline-v2-rollout.md`
- Laravel 13 deployment docs (`php artisan optimize`), release notes (min PHP 8.3, minimal breaking changes) — via Context7 `/laravel/docs/__branch__13.x`
- React Compiler installation for Vite 8 / `@vitejs/plugin-react` ≥ 6 (`reactCompilerPreset` + `@rolldown/plugin-babel`) — via Context7 `/reactjs/react.dev`
