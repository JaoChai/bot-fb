# Frontend Improvement — Design

**Date:** 2026-09-25
**Status:** Approved in chat (Marci, option C: all phases)
**Scope:** `frontend/` only. No backend, no UI/design changes, no production data.
**Approach:** 4 phases, one PR each, ordered by risk (safety net → perf → structure). Each PR is independently revertable.

## 1. Baseline (measured 2026-09-25, `main` @ `4063aa7f`)

- `npm run lint`: 0 errors. `npm test`: 32 files / 152 tests passed. `npm run build`: OK.
- Total JS: 556.87 kB gzip. `dist/index.html` preloads `vendor-charts` (112.97 kB gzip) on every route, including `/login`.
- `src/`: 244 TS/TSX files, 29,157 LOC. Files > 500 LOC: `types/api.ts` 695, `components/analytics/OrdersAnalytics.tsx` 619, `components/flow/PluginSection.tsx` 519, `pages/settings/QuickRepliesPage.tsx` 513.
- 25 inline `queryKey: [...]` literals; 49 `invalidateQueries` calls. A `queryKeys` factory exists in `src/lib/query.ts` but covers only 7 domains.
- No Playwright / E2E tests. CI `frontend-checks` runs audit, lint, vitest, build.
- Query cache is persisted to IndexedDB (`BOTJAO_QUERY_CACHE_v2`, `buster: 'v4'` in `main.tsx`).

## 2. Documentation Basis

| Dependency (lockfile) | Source | Finding | Decision |
|---|---|---|---|
| `@tanstack/react-query` 5.102.8 | Context7 `/tanstack/query` — guides/query-options | `queryOptions()` co-locates `queryKey` + `queryFn`, returns the input unchanged at runtime, types the key so `getQueryData`/`setQueryData`/`invalidateQueries` stay in sync. `infiniteQueryOptions` for infinite queries. | Phase 2 uses per-domain `queryOptions` factories. |
| `react` 19.2.8 + `babel-plugin-react-compiler` 1.0.0 | Context7 `/reactjs/react.dev` — learn/react-compiler/introduction | "For existing code, we recommend either leaving existing memoization in place (removing it can change compilation output) or carefully testing before removing." | Do **not** bulk-remove the 107 `useMemo`/`useCallback` + 13 `memo()`. Out of scope. |
| `react-router` 8.3.1 | Context7 `/websites/reactrouter` (docs up to 7.18.2; v8 not indexed) | `route.lazy` exists for data routers. | Current `lazyWithRetryNamed` + `Suspense` + `ChunkErrorBoundary` already code-splits all 16 pages. No change. |
| `rolldown` 1.2.7 (via `vite` 8.2.2) | Context7 `/rolldown/rolldown` — manual-code-splitting | Groups capture matched modules **and their dependencies recursively** (`includeDependenciesRecursively`, default true); groups have `priority`. Turning recursion off can produce invalid output. | Phase 1 fixes ordering/priority; keep recursion on. |
| `recharts` 3.10.1, `clsx` 2.1.1 | `npm ls clsx` | One deduped `clsx` shared by recharts, `class-variance-authority`, and `cn()`. | Root cause input for Phase 1. |

## 3. Phase 0 — Playwright smoke safety net

**Goal:** a repeatable browser check that the main journeys still render, before any refactor.

- Add devDependency `@playwright/test` (version pinned at install; re-check Context7 `/microsoft/playwright` for config API before writing).
- `frontend/playwright.config.ts`: `webServer` runs `vite preview` on a built app; projects `desktop-chromium` and `mobile` (a Playwright mobile device preset).
- API is mocked at the network layer with `page.route` (deterministic, no backend, no DB, no production). Reuse fixture shapes from `src/test/mocks` where they fit.
- Smoke specs in `frontend/e2e/`: Login renders + submits; Dashboard renders cards/chart; Bots list renders; Chat opens a conversation and shows messages; Flow editor opens and switches tabs. Each asserts visible content and no uncaught page errors.
- CI: new step in `frontend-checks` after build: install Chromium, `npx playwright test`. Raise job `timeout-minutes` if needed. Upload report on failure.
- `vitest.config.ts` `include` already limits to `src/**`, so e2e files are not picked up by vitest; add `e2e/` to ESLint/tsconfig/knip scope as required.

**Exit:** all specs green locally on both projects, green in CI; existing 152 vitest tests unchanged.

## 4. Phase 1 — Charts load only where used

**Root cause (measured):** built `vendor-charts` exports `clsx` (`c as m`); `utils-*.js` (`cn()`) and `vendor-utils-*.js` import `m` from `vendor-charts`. Because groups capture dependencies recursively and `vendor-charts` is listed before `vendor-utils`, recharts pulls the shared `clsx` into its chunk, so every page that calls `cn()` preloads recharts.

**Change:** `frontend/vite.config.ts` `codeSplitting.groups` only — make shared utilities (at minimum `clsx`) win over `vendor-charts` (reorder and/or explicit `priority`). Exact form chosen by building and inspecting output; recursion stays on.

**Exit:**
- `dist/index.html` has no `vendor-charts` reference; `grep` of non-chart chunks shows no import from `vendor-charts`.
- `vendor-charts` is imported only by Dashboard/Orders/chart component chunks.
- Report initial-load JS (sum of chunks referenced by `index.html`) before vs after.
- Phase 0 Playwright green (Dashboard and Orders charts still render).

## 5. Phase 2 — `queryOptions` per domain

- New `src/queries/<domain>.ts` exporting `<domain>Queries` built with `queryOptions` / `infiniteQueryOptions`, keys composed from the existing `queryKeys` factory (extended for missing domains).
- Hooks call `useQuery(<domain>Queries.x(args))`; mutations invalidate via `<domain>Queries.x(args).queryKey` or the factory prefix.
- **Hard rule: every query key stays byte-for-byte identical** to today, so the persisted IndexedDB cache and `NON_PERSISTENT_KEYS` keep working and `buster` is not bumped.
- Order: domains with inline keys first — orders, slips, dashboard, cost-analytics, admins (`bot-admins-counts`), conversation-stats, settings, search-users, messages — then the already-factored domains. One commit per domain.
- Tests: per-domain vitest asserting each key equals its current literal.

**Exit:** `grep -rn "queryKey: \['" frontend/src` → no results outside tests; vitest + Playwright green; `tsc -b` clean.

## 6. Phase 3 — Split large files (verbatim moves)

- `types/api.ts` → `types/api/<section>.ts` following its 17 existing section comments; `types/api/index.ts` re-exports everything so `@/types/api` imports are unchanged.
- `OrdersAnalytics.tsx`, `PluginSection.tsx`, `QuickRepliesPage.tsx` → extract subcomponents into sibling files. Bodies are copied, not rewritten; props pass through; no logic changes in this PR.

**Exit:** the four original files and every file extracted from them are ≤ 300 LOC; `tsc -b`, lint, vitest, Playwright green; `npx knip` introduces no new unused exports.

## 7. Out of scope

- Bulk removal of manual memoization (see Documentation Basis).
- Router lazy-loading migration.
- Replacing the Express static server.
- Any UI/UX or visual redesign; any backend change.

## 8. Process and rollback

- Branches: `test/frontend-playwright-smoke`, `perf/frontend-chart-chunk`, `refactor/frontend-query-options`, `refactor/frontend-split-large-files`. Worktree per phase.
- Every PR: `npm run lint`, `npm test`, `npm run build`, `npx playwright test`, CI green, owner approves merge. 24 h Sentry watch after deploy.
- Rollback: revert the PR. No persisted-state migration (keys unchanged).
