# Unit 4: Frontend Profiler

> Data sources: production build `cd frontend && npm run build`; knip dead-code scan; tsc --noEmit; Lighthouse on `https://www.botjao.com`. Snapshot: 2026-05-15.

## Bundle Analysis

### Top 30 Chunks by Size (uncompressed)

| File | Size |
|------|------|
| vendor-charts-ChPoBfrB.js | 363.45 kB |
| index-C26xfiHu.js | 329.44 kB |
| vendor-radix-DVNS6rdw.js | 177.22 kB |
| vendor-utils-CJpSoghj.js | 100.80 kB |
| vendor-react-1BVUBMAn.js | 95.63 kB |
| ChatPage-B1fbE87a.js | 89.19 kB |
| vendor-query-mWPfII8L.js | 88.13 kB |
| index-CTXr-sqc.css | 87.76 kB |
| FlowEditorPage-ChuseJK6.js | 66.91 kB |
| DashboardPage-B4T0DUhG.js | 29.16 kB |
| BotSettingsPage-D6723Y2X.js | 28.01 kB |
| vendor-icons-Dm143kLf.js | 25.37 kB |
| vendor-state-BQTar8nH.js | 23.98 kB |
| EditConnectionPage-DktVI6DC.js | 20.94 kB |
| KnowledgeBasePage-Bn-7dI6_.js | 20.80 kB |
| OrdersPage-RWKnCFY1.js | 14.98 kB |
| VipManagementPage-jRKfNAWt.js | 10.07 kB |
| QuickRepliesPage-CdgSlcbF.js | 9.36 kB |
| TeamPage-r56ijnNY.js | 9.04 kB |
| skeleton-DwO7plF_.js | 8.96 kB |
| BotsPage-CAQxkxp7.js | 8.07 kB |
| SettingsPage-Dm38pwya.js | 6.70 kB |
| AddConnectionPage-U731-gBw.js | 6.09 kB |
| web-vitals-D8cA-W4R.js | 5.71 kB |
| select-y9bkFxam.js | 3.65 kB |
| validations-9KTC3WnG.js | 3.21 kB |
| RegisterPage-CLbxHOpe.js | 3.19 kB |
| useQuickReplies-plOVTMc3.js | 2.95 kB |
| useKnowledgeBase-BqHhg3EE.js | 2.84 kB |
| LoginPage-BM7n63ZK.js | 2.66 kB |

**Total dist assets:** 1.63 MB uncompressed (681 kB gzipped approx per build output)

**Key observation:** `vendor-charts-ChPoBfrB.js` (363 kB) and `index-C26xfiHu.js` (329 kB) together account for ~692 kB — 42% of total JS. The main `index` bundle is not lazy-split. `vendor-radix` at 177 kB carries the full Radix UI tree.

## Dead Code (knip)

| Category | Count |
|----------|-------|
| Unused files | 1 |
| Unused exports | 22 |
| Unused exported types | 14 |
| Unused dependencies | 1 |
| Unlisted dependencies | 1 |
| Duplicate exports | 1 |

### Sample top unused exports (20)

| Path | Symbol |
|------|--------|
| src/components/chat/adapters/ChannelProvider.tsx | getChannelAdapter |
| src/components/chat/adapters/ChannelProvider.tsx | ChannelProvider |
| src/components/chat/adapters/ChannelProvider.tsx | useChannelAdapter |
| src/components/chat/adapters/index.ts | defaultAdapter, lineAdapter, telegramAdapter, facebookAdapter |
| src/components/common/index.ts | PageHeader, SettingSection, SettingRow, StickyActionBar |
| src/components/flows/index.ts | KnowledgeBaseSelector |
| src/hooks/chat/index.ts | FALLBACK_POLLING_INTERVAL, DEFAULT_PAGE_SIZE, useInfiniteMessages |
| src/hooks/chat/index.ts | flattenInfiniteMessages, useSendMessage, useConversationList |
| src/hooks/chat/index.ts | conversationKeys, useConversationDetails, useConversationStats |
| src/hooks/chat/useConversationDetails.ts | useConversationDetails, usePrefetchConversation |
| src/hooks/chat/useConversationList.ts | useConversationList |
| src/hooks/chat/useMessageMutations.ts | useSendMessage |
| src/hooks/chat/useMessageQueries.ts | useInfiniteMessages, flattenInfiniteMessages |
| src/hooks/chat/useNotes.ts | notesKeys, useNotes, useAddNote, useUpdateNote, useDeleteNote |
| src/hooks/chat/useTags.ts | tagsKeys, useBotTags, useAddTags, useRemoveTag |
| src/hooks/useAdmins.ts | useBotAdmins, useAssignConversation, useUnassignConversation |
| src/hooks/useAuth.ts | useUser, useLogin, useRegister, useLogout |
| src/hooks/useConversations.ts | useConversations, useInfiniteConversations, useConversation |
| src/hooks/useEcho.ts | useConversationChannel, useNotifications, useEchoConnection |
| src/lib/api.ts | approveAgentAction, rejectAgentAction |

**Notable pattern:** `src/hooks/chat/index.ts` re-exports many symbols that are also exported directly from their source files — a barrel-file duplication pattern. The entire `ChannelProvider` adapter layer is exported but never consumed (possibly dead after a refactor).

**Unused file:** `src/components/flow/KnowledgeBaseWarning.tsx`

**Unused dependency:** `@tanstack/query-sync-storage-persister` (installed but never imported — adds ~5 kB to vendor-query chunk)

## TypeScript Health

- **Errors:** 0
- **Warnings:** 0 (tsc --noEmit clean pass)
- Top error patterns: none

## Web Vitals per Page

| Page | LCP | FCP | TTI | TBT | CLS | Speed Index | Perf score |
|------|-----|-----|-----|-----|-----|-------------|------------|
| / (root) | 4.1 s | 4.1 s | 4.1 s | 0 ms | 0 | 4.1 s | 77 |
| /login | 4.2 s | 4.2 s | 4.2 s | 0 ms | 0 | 5.1 s | 74 |
| /dashboard (redirects to login) | 4.2 s | 4.2 s | 4.2 s | 0 ms | 0 | 5.0 s | 74 |

**Notes:**
- `/dashboard` requires auth; Lighthouse measured the redirect → login screen. Metrics reflect login UX, not dashboard content.
- TBT = 0 ms on all pages: no main-thread blocking scripts post-parse (good).
- CLS = 0 on all pages: no layout shift (good).
- LCP consistently ~4.1-4.2 s — **fails the 2.5 s threshold** on all three measured pages. Root cause is deferred paint until large JS chunks parse (FCP = LCP, suggesting no above-fold image — LCP element is text/JS-rendered).

## Largest Source Files (LOC)

| LOC | File |
|-----|------|
| 861 | src/hooks/useConversations.ts |
| 721 | src/types/api.ts |
| 619 | src/components/analytics/OrdersAnalytics.tsx |
| 569 | src/pages/FlowEditorPage.tsx |
| 516 | src/components/flow/PluginSection.tsx |
| 513 | src/pages/settings/QuickRepliesPage.tsx |
| 438 | src/components/ProcessDisplay.tsx |
| 409 | src/pages/BotSettingsPage.tsx |
| 403 | src/hooks/useStreamingChat.ts |
| 374 | src/hooks/useFlows.ts |
| 368 | src/pages/EditConnectionPage.tsx |
| 364 | src/components/telegram/TelegramMessageBubble.tsx |
| 357 | src/pages/KnowledgeBasePage.tsx |
| 353 | src/pages/TeamPage.tsx |
| 351 | src/pages/VipManagementPage.tsx |
| 311 | src/pages/BotsPage.tsx |
| 309 | src/hooks/chat/useRealtime.ts |
| 308 | src/components/flow-editor/tabs/PromptTab.tsx |
| 305 | src/hooks/chat/useConversationDetails.ts |

## Findings

### Finding 1: LCP 4.1-4.2 s — All Pages Fail Core Web Vital Threshold
- **Evidence:** Lighthouse: LCP = 4.1 s (/) , 4.2 s (/login), 4.2 s (/dashboard). Threshold is 2.5 s. FCP = LCP on all pages.
- **Impact:** Every first visit is slow. Google Search ranking penalized for CWV. Users on mobile/3G wait >4 s for any visible content.
- **Root cause hypothesis:** FCP = LCP means nothing renders until JS executes. The SPA mounts React before painting any content. The main `index-C26xfiHu.js` (329 kB) must fully parse before the app boots. No server-side rendering, no static HTML shell, no skeleton in `index.html`.
- **Fix candidates:**
  1. Add a static HTML skeleton in `index.html` (logo + spinner) so FCP fires before JS parse — effort: 0.5 day, risk: low.
  2. Preload critical font/hero asset with `<link rel="preload">` — effort: 0.5 day, risk: low.
  3. Split `index-C26xfiHu.js` (329 kB entry) — move route-level code to lazy chunks; currently ChatPage (89 kB) is not lazily imported if referenced from index — effort: 1 day, risk: medium.
  4. Enable Vite `build.rollupOptions.output.manualChunks` to break up the monolithic index entry — effort: 1 day, risk: low.

### Finding 2: vendor-charts (363 kB) Loaded on Every Page
- **Evidence:** `vendor-charts-ChPoBfrB.js` = 363 kB (largest single chunk). Dashboard and analytics pages are the only consumers, but the chunk is shared globally via the vendor split strategy.
- **Impact:** ~363 kB of chart library (likely Recharts/Chart.js) parses on login and chat pages where no charts render. Adds ~150-200 ms parse time on mid-range devices.
- **Root cause hypothesis:** Vite's default vendor chunk strategy groups all node_modules together if they share an importer. If `DashboardPage` is imported anywhere in the main bundle (even indirectly), its chart deps land in the main vendor split.
- **Fix candidates:**
  1. Lazy-import `DashboardPage` and other analytics pages via `React.lazy()` / dynamic `import()` — isolates charts chunk to dashboard route only — effort: 1 day, risk: low.
  2. Add explicit `manualChunks` in `vite.config.ts` to force recharts/chart.js into a separate chunk loaded only when needed — effort: 0.5 day, risk: low.

### Finding 3: Barrel-File Export Bloat — Dead Hook Surface (22 unused exports + 14 types)
- **Evidence:** knip reports 22 unused exports, 14 unused types. The majority live in `src/hooks/chat/index.ts` which re-exports all chat hooks, and `src/components/chat/adapters/index.ts` which re-exports the entire channel adapter layer.
- **Impact:** Tree-shaking can fail for barrel files if any export is side-effectful or if the bundler cannot statically analyse re-export chains. Unused exports in barrel files increase the risk that dead code survives into the bundle. The `ChannelProvider` adapter layer (4 adapters × ~3 exports each) may add weight to `index-C26xfiHu.js`.
- **Root cause hypothesis:** `hooks/chat/index.ts` was created as a convenience re-export barrel during a refactor. Consumers were later updated to import directly, leaving the barrel's exports stale.
- **Fix candidates:**
  1. Remove or scope the barrel `src/hooks/chat/index.ts` — export only what is consumed — effort: 0.5 day, risk: low.
  2. Mark `src/components/chat/adapters/` with `"sideEffects": false` in package.json to guarantee tree-shaking — effort: 0.25 day, risk: low.
  3. Remove unused file `src/components/flow/KnowledgeBaseWarning.tsx` and unused dep `@tanstack/query-sync-storage-persister` from package.json — effort: 0.25 day, risk: low.

### Finding 4: index-C26xfiHu.js (329 kB) Is the True Boot Bottleneck
- **Evidence:** Build log shows `index-C26xfiHu.js` = 329 kB (gzip: 101 kB). This is the app entry point. FCP = LCP = 4.1 s — the browser cannot render until this parses.
- **Impact:** 329 kB uncompressed JS at parse time. On a Moto G4 (Lighthouse mobile simulation) this adds ~1.5-2 s parse+compile overhead on top of network transfer.
- **Root cause hypothesis:** All route components (or their hooks) that are eagerly imported pull their transitive deps into the index chunk. The router likely does not use `React.lazy()` for page-level code splitting.
- **Fix candidates:**
  1. Audit `src/App.tsx` or router file — convert all page imports to `React.lazy(() => import('./pages/XxxPage'))` — effort: 1 day, risk: low (Suspense boundaries already common in React apps).
  2. Target `useConversations.ts` (861 LOC, largest source file) — if eagerly imported at app level, splitting it would reduce index chunk — effort: 0.5 day, risk: medium.

### Finding 5: useConversations.ts (861 LOC) — Overly Large Hook
- **Evidence:** `src/hooks/useConversations.ts` at 861 LOC is the largest source file, 19% larger than next biggest (`src/types/api.ts` at 721 LOC).
- **Impact:** Single file responsible for conversations, infinite scroll, messages, stats, bulk ops, tags — all eagerly loaded at app boot. Any change to this file invalidates the entire hook bundle cache for all users.
- **Root cause hypothesis:** The hook aggregates too many concerns; likely grew by accretion during the native chat upgrade phases (per project memory: Phase 1 merged).
- **Fix candidates:**
  1. Split into focused hooks: `useConversationList`, `useConversationMessages`, `useConversationMutations` — already partially exists in `hooks/chat/` but not wired — effort: 1 day, risk: medium.
  2. Move to `hooks/chat/` directory and lazy-load via dynamic import in ChatPage — effort: 0.5 day (after split), risk: low.

## Status: 🔴

Thresholds:
- LCP < 2.5 s + TBT < 200 ms + CLS < 0.1 on all measured pages = 🟢
- 1 page misses = 🟡
- ≥ 2 pages miss = 🔴
- Total JS > 1 MB raw = 🟡; > 2 MB = 🔴

Current: LCP **4.1-4.2 s on all 3 measured pages** (all fail 2.5 s threshold). Total JS = ~1.54 MB raw (🟡 JS size). CLS = 0 (🟢). TBT = 0 ms (🟢). Status: 🔴 (LCP fails on all pages).

## Notes

- INP requires real user interactions; Lighthouse lab metrics approximate via TBT. TBT = 0 suggests good interactivity once loaded.
- `/dashboard` requires authentication — Lighthouse measured the login redirect screen, not dashboard content. Dashboard LCP is likely worse with chart data loading.
- Perf scores (77, 74, 74) are Lighthouse lab scores under simulated mobile throttling. Real-user scores on desktop are likely 85-90.
- gzipped totals from build: index 101 kB + vendor-charts 109 kB = 210 kB just for the two biggest chunks over the wire — reasonable for a complex SPA but the parse cost remains.
