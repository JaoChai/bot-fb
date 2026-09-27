# Frontend Improvement — Phase 0 + 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Playwright smoke safety net (Phase 0), then stop the recharts chunk from loading on every route (Phase 1).

**Architecture:** Playwright runs against `vite preview` of a production build, with every `/api/**` request mocked through `page.route`, so no backend is needed. Phase 1 changes only `codeSplitting.groups` in `vite.config.ts`, and each change is verified against the built output.

**Tech Stack:** Vite 8.2.2 (Rolldown 1.2.7), React 19.2.8, React Router 8.3.1, TanStack Query 5.102.8, `@playwright/test` 1.63.0 (latest on npm, 2026-09-25).

**Spec:** `docs/superpowers/specs/2026-09-25-frontend-improvement-design.md`

**Scope note:** This plan covers spec §3 (Phase 0) and §4 (Phase 1) only. Phase 2 (`queryOptions`) and Phase 3 (file splits) get their own plan after this lands, because both rely on the Phase 0 suite as their regression gate.

## Global Constraints

- Work only inside `frontend/` plus `.github/workflows/ci.yml`. No backend changes, no production calls.
- Every PR must pass: `npm run lint`, `npm test` (baseline 32 files / 152 tests), `npm run build`, `npx playwright test`, and CI.
- Mocks are deterministic. Every `/api/**` request is fulfilled by `page.route`, and no request reaches `localhost:8000`.
- Keep `includeDependenciesRecursively` at its default (true), per Rolldown docs.
- Keep existing query keys, the IndexedDB cache key, and `buster: 'v4'` unchanged.
- Git: one branch per phase, `test/frontend-playwright-smoke` and `perf/frontend-chart-chunk`, each cut from `origin/main`. Use a worktree per phase (`superpowers:using-git-worktrees`).

## Documentation Basis

- Playwright, Context7 `/microsoft/playwright`: `defineConfig({ webServer: { command, url, reuseExistingServer: !process.env.CI } })`; projects use `devices['Desktop Chrome']` / `devices['Pixel 5']`; mocking via `page.route(glob, route => route.fulfill({ json }))` registered before `page.goto`.
- Rolldown, Context7 `/rolldown/rolldown`: groups capture matched modules plus their dependencies recursively; `priority` decides which group wins when several match (a higher number wins).

## File Structure

- Create `frontend/playwright.config.ts`: runner config with two projects and the preview server.
- Create `frontend/e2e/fixtures.ts`: an authenticated page fixture, the API mock router, and a page-error collector.
- Create `frontend/e2e/mock-data.ts`: minimal typed JSON for the mocked endpoints.
- Create `frontend/e2e/smoke.spec.ts`: 5 journeys.
- Create `frontend/tsconfig.e2e.json`: type-checks `e2e/` and `playwright.config.ts`.
- Modify `frontend/tsconfig.json` (add the reference), `frontend/package.json` (devDep + script), `frontend/.gitignore` (reports), `.github/workflows/ci.yml` (e2e step).
- Create `frontend/scripts/check-initial-chunks.mjs`: Phase 1 guard that reads `dist/index.html`.
- Modify `frontend/vite.config.ts`: Phase 1 group priorities.

---

## PR 1 — Phase 0 (`test/frontend-playwright-smoke`)

### Task 1: Playwright harness plus a login smoke test

**Files:** create `playwright.config.ts`, `e2e/fixtures.ts`, `e2e/mock-data.ts`, `e2e/smoke.spec.ts`, `tsconfig.e2e.json`; modify `package.json`, `tsconfig.json`, `.gitignore`.

**Interfaces:**
- Produces: `test` / `expect` from `e2e/fixtures.ts`, plus fixtures `mockApi` (auto), `pageErrors: string[]`, and `loggedIn: void`. Also `unmockedCalls: string[]`, a list of API paths answered by the catch-all.

- [ ] **Step 1: Install**

```bash
cd frontend && npm install -D @playwright/test@1.63.0 && npx playwright install chromium
```

- [ ] **Step 2: Config** (`frontend/playwright.config.ts`)

```ts
import { defineConfig, devices } from '@playwright/test'

const PORT = 4173

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    trace: 'retain-on-failure',
    serviceWorkers: 'block',
  },
  projects: [
    { name: 'desktop-chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile', use: { ...devices['Pixel 5'] } },
  ],
  webServer: {
    command: `npm run build && npx vite preview --port ${PORT} --strictPort --host 127.0.0.1`,
    url: `http://127.0.0.1:${PORT}`,
    timeout: 180_000,
    reuseExistingServer: !process.env.CI,
  },
})
```

- [ ] **Step 3: Mock data** (`frontend/e2e/mock-data.ts`). The shapes are copied from `src/types/api.ts` and `src/test/mocks/handlers.ts`.

```ts
export const user = {
  id: 1, name: 'E2E Owner', email: 'e2e@example.com', role: 'owner',
  email_verified_at: '2026-01-01T00:00:00.000000Z',
  created_at: '2026-01-01T00:00:00.000000Z', updated_at: '2026-01-01T00:00:00.000000Z',
}
export const token = 'e2e-token'
```

- [ ] **Step 4: Fixtures** (`frontend/e2e/fixtures.ts`)

```ts
import { test as base, expect } from '@playwright/test'
import { user, token } from './mock-data'

type Fixtures = { mockApi: void; pageErrors: string[]; unmockedCalls: string[]; loggedIn: void }

export const test = base.extend<Fixtures>({
  unmockedCalls: async ({}, use) => { await use([]) },
  pageErrors: async ({ page }, use) => {
    const errors: string[] = []
    page.on('pageerror', (e) => errors.push(e.message))
    await use(errors)
  },
  mockApi: [async ({ page, unmockedCalls }, use) => {
    // Catch-all first: Playwright gives the most recently registered route priority,
    // so specific routes registered later (in tests or in routes below) override this one.
    await page.route('**/api/**', (route) => {
      unmockedCalls.push(new URL(route.request().url()).pathname)
      return route.fulfill({ json: { data: [] } })
    })
    await page.route('**/api/auth/login', (route) =>
      route.fulfill({ json: { data: { user, token } } }))
    await page.route('**/api/auth/user', (route) => route.fulfill({ json: { data: user } }))
    // Block realtime so no websocket reaches a real host.
    await page.route(/pusher|reverb|sockjs/, (route) => route.abort())
    await use()
  }, { auto: true }],
  loggedIn: async ({ page }, use) => {
    await page.addInitScript(([u, t]) => {
      localStorage.setItem('auth_token', t as string)
      localStorage.setItem('auth-storage', JSON.stringify({
        state: { user: u, token: t, isAuthenticated: true }, version: 0,
      }))
    }, [user, token] as const)
    await use()
  },
})
export { expect }
```

- [ ] **Step 5: First test, RED check.** Write `e2e/smoke.spec.ts`:

```ts
import { test, expect } from './fixtures'

test('login page renders and signs in to the dashboard', async ({ page, pageErrors }) => {
  await page.goto('/login')
  await page.getByPlaceholder('name@example.com').fill('e2e@example.com')
  await page.getByPlaceholder('รหัสผ่าน').fill('password')
  await page.getByRole('button', { name: /เข้าสู่ระบบ/ }).click()
  await expect(page).toHaveURL(/\/dashboard$/)
  await expect(page.getByRole('heading', { name: 'แดชบอร์ด' })).toBeVisible()
  expect(pageErrors).toEqual([])
})
```

Run: `npx playwright test --project=desktop-chromium`. Expected: it either passes, or fails on a concrete selector or unmocked shape. Open `LoginPage.tsx` and read the submit button's real text before fixing. Verify RED by temporarily changing `'แดชบอร์ด'` to `'XX'`: the test must FAIL. Then restore it.

- [ ] **Step 6: Type-check and scripts**

`frontend/tsconfig.e2e.json`:

```json
{
  "compilerOptions": {
    "target": "ES2022", "module": "ESNext", "moduleResolution": "bundler",
    "strict": true, "noEmit": true, "skipLibCheck": true, "types": ["node"]
  },
  "include": ["e2e", "playwright.config.ts"]
}
```

Add `{ "path": "./tsconfig.e2e.json" }` to `tsconfig.json` `references`. Add the script `"test:e2e": "playwright test"` to `package.json`. Add `playwright-report/` and `test-results/` to `frontend/.gitignore`.

If `tsc -b` complains that a referenced project lacks `composite`, then instead add `e2e` + `playwright.config.ts` to `tsconfig.node.json` `include`. Record which option was used in the commit message.

- [ ] **Step 7: Verify**

```bash
npm run lint && npx tsc -b && npm test && npx playwright test
```

Expected: lint 0 errors, 152 vitest passed, 2 Playwright passed (1 test × 2 projects).

- [ ] **Step 8: Commit** `test(e2e): add playwright harness with mocked api and login smoke`

### Task 2: Smoke journeys for dashboard, bots, chat and flow editor

**Files:** modify `e2e/smoke.spec.ts` and `e2e/mock-data.ts`.

**Interfaces:** consumes `test`, `loggedIn`, `pageErrors`, `unmockedCalls` from Task 1.

- [ ] **Step 1: Write the 4 tests**

```ts
test.describe('authenticated', () => {
  test('dashboard', async ({ page, loggedIn, pageErrors }) => {
    void loggedIn
    await page.goto('/dashboard')
    await expect(page.getByRole('heading', { name: 'แดชบอร์ด' })).toBeVisible()
    expect(pageErrors).toEqual([])
  })
  test('bots list', async ({ page, loggedIn, pageErrors }) => {
    void loggedIn
    await page.goto('/bots')
    await expect(page.getByRole('heading', { name: 'การเชื่อมต่อ' })).toBeVisible()
    await expect(page.getByText('E2E Bot')).toBeVisible()
    expect(pageErrors).toEqual([])
  })
  test('chat opens a conversation', async ({ page, loggedIn, pageErrors }) => {
    void loggedIn
    await page.goto('/chat')
    await page.getByText('E2E Customer').first().click()
    await expect(page.getByText('สวัสดีครับ')).toBeVisible()
    expect(pageErrors).toEqual([])
  })
  test('flow editor opens and switches tabs', async ({ page, loggedIn, pageErrors }) => {
    void loggedIn
    await page.goto('/flows/1/edit?botId=1')
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
    await page.getByRole('tab').nth(1).click()
    await expect(page.getByRole('tab').nth(1)).toHaveAttribute('data-state', 'active')
    expect(pageErrors).toEqual([])
  })
})
```

- [ ] **Step 2: Run (expect RED) and harvest endpoints.** Add a temporary `test.afterEach(({ unmockedCalls }) => console.log(unmockedCalls))`, then run `npx playwright test --project=desktop-chromium`. For every path the page needs real data from (dashboard summary, bots list, conversations, messages, flow detail), add a route in `mockApi`. The body shape must come from the matching type in `src/types/api.ts` or from the `*Response` interface in the hook that fetches it (e.g. `useDashboard.ts` → `{ data: DashboardData }`). Put the bodies in `mock-data.ts`: one bot named `E2E Bot` (id 1), one conversation whose customer is `E2E Customer`, one message `สวัสดีครับ`, and one flow (id 1, bot 1). Check the real Flow editor URL shape in `router.tsx` and `FlowEditorPage.tsx`. Remove the temporary log.

- [ ] **Step 3: Green on both projects.** Run `npx playwright test`. Expected: 10 passed (5 × 2). If a mobile layout hides an element (e.g. the conversation list sits behind a toggle), branch on `test.info().project.name === 'mobile'` and use the real mobile control. Never skip the mobile project.

- [ ] **Step 4: Verify stability.** `npx playwright test --repeat-each=3` must give 30 passed with 0 flaky.

- [ ] **Step 5: Commit** `test(e2e): smoke dashboard, bots, chat, flow editor on desktop and mobile`

### Task 3: CI step

**Files:** modify `.github/workflows/ci.yml` (job `frontend-checks`).

- [ ] **Step 1:** Change `timeout-minutes: 10` to `15`, then add these steps after "TypeScript check and production build":

```yaml
      - name: Install Playwright browser
        working-directory: frontend
        run: npx playwright install --with-deps chromium
      - name: E2E smoke (Playwright)
        working-directory: frontend
        run: npx playwright test
      - name: Upload Playwright report
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report
          path: frontend/playwright-report
          retention-days: 7
```

- [ ] **Step 2:** Local sanity: `CI=1 npx playwright test` gives 10 passed. (`webServer` rebuilds, and `reuseExistingServer` is false.)
- [ ] **Step 3: Commit** `ci(frontend): run playwright smoke in frontend-checks`
- [ ] **Step 4:** Push the branch and open the PR. The CI `frontend-checks` job must be green, and the PR description lists the actual pass counts. Merging waits for owner approval.

---

## PR 2 — Phase 1 (`perf/frontend-chart-chunk`, cut after PR 1 merges)

### Task 4: Guard script (RED)

**Files:** create `frontend/scripts/check-initial-chunks.mjs`; modify `package.json`.

**Interfaces:** produces `npm run check:chunks`, which exits 1 if the entry HTML or any chunk it preloads imports `vendor-charts`.

- [ ] **Step 1: Write the script**

```js
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { gzipSync } from 'node:zlib'

const dist = new URL('../dist/', import.meta.url).pathname
const html = readFileSync(join(dist, 'index.html'), 'utf8')
const initial = [...html.matchAll(/assets\/[^"]+\.js/g)].map((m) => m[0])
let gz = 0
const offenders = []
for (const f of initial) {
  const src = readFileSync(join(dist, f))
  gz += gzipSync(src).length
  if (f.includes('vendor-charts') || /from"\.\/vendor-charts-/.test(src.toString())) offenders.push(f)
}
console.log(`initial JS files: ${initial.length}, gzip: ${(gz / 1024).toFixed(1)} kB`)
if (offenders.length) {
  console.error(`vendor-charts reachable from entry via: ${offenders.join(', ')}`)
  process.exit(1)
}
console.log('OK: vendor-charts not in initial load')
```

Add the script `"check:chunks": "node scripts/check-initial-chunks.mjs"`.

- [ ] **Step 2: RED.** Run `npm run build && npm run check:chunks`. Expected: exit 1, listing `vendor-charts`, `utils-*`, and `vendor-utils-*`. Record the printed gzip total as the baseline.

### Task 5: Fix group priority (GREEN)

**Files:** modify `frontend/vite.config.ts` (`codeSplitting.groups`).

- [ ] **Step 1: Minimal change.** Give every group an explicit `priority`, with shared utilities above charts:

```ts
groups: [
  { name: "vendor-react", test: /[/\\]node_modules[/\\](react|react-dom|react-router)[/\\]/, priority: 60 },
  { name: "vendor-utils", test: /[/\\]node_modules[/\\](date-fns|clsx|tailwind-merge|class-variance-authority|zod)[/\\]/, priority: 50 },
  { name: "vendor-radix", test: /[/\\]node_modules[/\\](radix-ui|@radix-ui)[/\\]/, priority: 40 },
  { name: "vendor-query", test: /[/\\]node_modules[/\\](@tanstack[/\\]react-query|@tanstack[/\\]react-virtual|axios)[/\\]/, priority: 40 },
  { name: "vendor-state", test: /[/\\]node_modules[/\\](zustand|react-hook-form|@hookform[/\\]resolvers)[/\\]/, priority: 40 },
  { name: "vendor-icons", test: /[/\\]node_modules[/\\]lucide-react[/\\]/, priority: 40 },
  { name: "vendor-charts", test: /[/\\]node_modules[/\\]recharts[/\\]/, priority: 10 },
],
```

Update the existing comment to say that priority, not array order, decides ownership, and that charts sit lowest so shared deps (clsx, react) land in the eager groups.

- [ ] **Step 2: GREEN.** Run `npm run build && npm run check:chunks`. Expected: exit 0 and a gzip total about 100 kB below the baseline. If it still fails, inspect which recharts dependency leaks (`grep -o 'from"./vendor-charts[^"]*"' dist/assets/*.js`) and add that package to the `vendor-utils` test. Never turn off `includeDependenciesRecursively`.
- [ ] **Step 3: Chart pages still work.** Run `grep -l vendor-charts dist/assets/*.js`. Expected: only Dashboard/Orders/OrdersAnalytics/DualAxisChart/ProductsSummaryCard chunks plus `vendor-charts` itself. Then run `npx playwright test`: 10 passed (the Dashboard journey renders the chart page).
- [ ] **Step 4: Full gate.** `npm run lint && npm test && npx playwright test` must give 0 errors, 152 passed, and 10 passed.
- [ ] **Step 5: CI guard.** In `ci.yml`, add a step `Check initial chunks` running `npm run check:chunks` after the build step.
- [ ] **Step 6: Commit** `perf(frontend): keep recharts out of the initial load via group priority`. Push the PR, and put the before/after initial gzip numbers from the script in the description. Merging waits for owner approval, followed by a 24 h Sentry watch after deploy.

---

## Self-review

- Spec coverage: §3 → Tasks 1–3 (config, both projects, page.route mocks, 5 journeys, CI, report upload). §4 → Tasks 4–5 (index.html check, chart-only importers, before/after numbers, Playwright green). §5–6 → deferred to the next plan, as the scope note says.
- Placeholders: none. Task 2 Step 2 is discovery-driven by design, because the endpoint list depends on the runtime requests, and it gives an exact procedure plus a source for the shapes.
- Names: `mockApi`, `loggedIn`, `pageErrors`, `unmockedCalls`, and `check:chunks` are used consistently.
