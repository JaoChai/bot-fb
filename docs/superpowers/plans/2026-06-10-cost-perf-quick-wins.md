# Cost + Performance Quick-Wins Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship 12 low-risk code quick-wins + 3 Railway ops actions that cut cost and improve performance across backend, frontend, and DevOps, organized as 4 independently-shippable PRs.

**Architecture:** Surgical changes only — no rewrites. Three pre-flight Railway ops actions (OPS-1/2/3) precede four small PRs: **PR-1** DevOps/build hygiene, **PR-2** frontend critical-path perf, **PR-3** AI prompt efficiency, **PR-4** backend surgical. Each PR is its own branch, independently revertable, with a **24h Sentry watch** between merges. `OPS-1` (confirm backend builder = Dockerfile) **gates PR-1 task D2**; everything else is parallelizable.

**Tech Stack:** Laravel 12 / PHP 8.4 (PHPUnit), React 19 / Vite 7 (vitest), Railway (Dockerfile + supervisord), Neon Postgres, Redis, OpenRouter; CI in `.github/workflows/ci.yml`.

---

## How to use this plan

- **Spec:** `docs/superpowers/specs/2026-06-10-cost-perf-quick-wins-design.md`. This plan implements its §2 work items.
- **Order:** OPS-1 → OPS-2 → OPS-3 (Railway console/MCP, not git) **first**. Then PR-1 (after OPS-1 green), with PR-2, PR-3, PR-4 runnable in any order / in parallel as review bandwidth allows. Merge one PR, watch Sentry 24h on its paths, then the next.
- **Anchor caveat:** All `file:line` references were re-verified live on 2026-06-10, but line numbers drift. Each task's first action re-reads the real file — trust the pasted current-code snippet over the line number if they disagree.
- **Drift already corrected (PR-3):** the spec line-referenced the chat-emulator orchestrator for A1/A2; the live trace found the **production** path is `RAGService::generateResponse`. PR-3 targets the production path and documents this in a `> NOTE` at the top of its section. `isSimpleMessage` was an inline expression, not a method — A2 extracts it.
- **Out of scope (unchanged from spec):** ProcessLINEWebhook rewrite, web-framework swap, merging worker-llm/worker-fast, deleting worker-db, and the decision-gated Phase 2 levers (Neon autosuspend, Cloudflare Pages, prompt-trim/reasoning-cap A/B, frontend Sentry re-enable).

---

## Ops actions (pre-flight — Railway dashboard/MCP, not git)

No git branch — these are Railway console / MCP actions on project `bot-facebook` (`ba714504-2721-4535-9fc7-6b3d903c481a`, env `production` = `40f44433-1f1e-40cb-8e0c-7b2e83ff14a4`), done **before** any PR. Run in order: **OPS-1 first (gates PR-1 / task D2)** → OPS-2 → OPS-3. All three are reversible. Confidence tags: `live` = read via Railway MCP on 2026-06-10; `inferred` = not directly observed.

> NOTE (live drift vs spec): spec §2/§6 say `get_service_config(backend)` "reports `Builder: RAILPACK`". Confirmed `live` 2026-06-10 — backend (`36066744-e919-4084-ab57-a31e76694a9a`) **Builder: RAILPACK**. The sibling service that builds the *same* `/backend` Dockerfile — `scheduler` (`7454f43a-cb20-411a-b435-bc74131a88c0`) — reports **Builder: DOCKERFILE** (`live`). So "set backend builder = DOCKERFILE" is literally "make backend match what scheduler already reports for the same root dir." The Railpack→Dockerfile build-log discrepancy itself is `inferred` (build logs not re-read in this pass).

> NOTE (full service IDs — the 8-digit IDs in the spec are truncated; MCP rejects them):
> - backend = `36066744-e919-4084-ab57-a31e76694a9a`
> - scheduler = `7454f43a-cb20-411a-b435-bc74131a88c0`
> - frontend = `725c69c1-acc7-4b44-8eda-5fd09a2e1538`

---

### Task OPS-1: Confirm backend service builder = Dockerfile (gate for PR-1/D2)

**Files:** none (Railway console / MCP only). Anchors: `backend/Dockerfile:91-167` (supervisord block), `backend/Dockerfile:177` (CMD).

- [ ] **Step 1: Capture current backend builder (inspect).** Run the read-only MCP tool:
  ```
  mcp__plugin_railway_railway__get_service_config(
    project_id="ba714504-2721-4535-9fc7-6b3d903c481a",
    service_id="36066744-e919-4084-ab57-a31e76694a9a")
  ```
  Expected (matches `live` capture 2026-06-10 — record this as the "before" state):
  ```
  ## Service Config (id: 36066744-e919-4084-ab57-a31e76694a9a)
  Environment: production
  Source repo: JaoChai/bot-fb
  Root directory: /backend
  Builder: RAILPACK
  Start command:
  Health check path: /api/health
  Variables defined: 68
  ```
  `Builder: RAILPACK` is the footgun: a Railpack/Nixpacks redeploy would not run `backend/Dockerfile`'s supervisord stack (the Dockerfile is what actually boots nginx + php-fpm + scheduler + 3 queue workers).

- [ ] **Step 2: Cross-check against the sibling that already reports Dockerfile.** Run:
  ```
  mcp__plugin_railway_railway__get_service_config(
    project_id="ba714504-2721-4535-9fc7-6b3d903c481a",
    service_id="7454f43a-cb20-411a-b435-bc74131a88c0")
  ```
  Expected (`live`):
  ```
  Root directory: /backend
  Builder: DOCKERFILE
  Start command: php artisan config:cache && php artisan schedule:work
  ```
  Confirms the *same* `/backend` root resolves to `DOCKERFILE` on another service — so the target value for backend is `DOCKERFILE`.

- [ ] **Step 3: Set builder = Dockerfile in the Railway UI (action).**
  1. Railway dashboard → project **bot-facebook** → service **backend** → **Settings** tab → **Build** section.
  2. **Builder**: change from the current value to **Dockerfile**.
  3. **Dockerfile Path**: set to `Dockerfile` (resolved relative to Root Directory `/backend`, i.e. the file at `backend/Dockerfile`).
  4. Click **Save / Deploy** — this triggers a new deployment. Do NOT merge PR-1/D2 (`Procfile`/`nixpacks.toml` deletion) until this deploy is green.

- [ ] **Step 4: Verify config now reports Dockerfile (verification).** Re-run:
  ```
  mcp__plugin_railway_railway__get_service_config(
    project_id="ba714504-2721-4535-9fc7-6b3d903c481a",
    service_id="36066744-e919-4084-ab57-a31e76694a9a")
  ```
  Expected: line now reads `Builder: DOCKERFILE` (was `RAILPACK`).

- [ ] **Step 5: Verify the deploy boots the supervisord stack (verification).** Load `get_logs` (`ToolSearch query="select:mcp__plugin_railway_railway__get_logs"`) and read the latest backend deployment logs, then confirm both the Dockerfile CMD (`backend/Dockerfile:177`) and every supervisord program (`backend/Dockerfile:91-167`) started. Expected lines (order/pids vary):
  ```
  # from CMD (Dockerfile:177)
  INFO success: ... config:cache / route:cache / migrate --force
  # supervisord spawning the 6 programs (Dockerfile:97,106,115,124,140,155)
  INFO spawned: 'php-fpm' with pid ...
  INFO spawned: 'nginx' with pid ...
  INFO spawned: 'scheduler' with pid ...
  INFO spawned: 'queue-worker-llm_00' with pid ...
  INFO spawned: 'queue-worker-fast_00' with pid ...
  INFO spawned: 'queue-worker-db_00' with pid ...
  ```
  Also confirm the Railway health check `/api/health` (`Health check path` in Step 1) goes green. If all six programs spawn and health is green, the builder change is correct and D2 is unblocked.

- [ ] **Rollback note:** If the Dockerfile deploy fails or the container does not boot supervisord, in **Settings → Build** set **Builder** back to **Railpack** (the prior value from Step 1) and redeploy; or use the **Deployments** tab → previous green deployment → **Redeploy/Rollback** to restore the last-known-good image. No git change is involved, so rollback is config-only and immediate.

---

### Task OPS-2: Delete the redundant standalone `scheduler` Railway service

**Files:** none (Railway console / MCP only). Anchors: `backend/Dockerfile:115-122` (`[program:scheduler]`), `backend/routes/console.php:14-75` (scheduled commands).

> Why it's safe to delete: backend's own supervisord already runs the scheduler. `backend/Dockerfile:115-122`:
> ```
> [program:scheduler]
> command=sh -c "while true; do php /var/www/html/artisan schedule:run --verbose --no-interaction; sleep 60; done"
> ```
> The standalone `scheduler` service runs the *same* schedule a second way — its start command is `php artisan config:cache && php artisan schedule:work` (`live`, Step 1 below). Two schedulers = double-fire hazard for every entry in `backend/routes/console.php`: `conversations:auto-enable-bots` (everyMinute, line 14-17), `db:ping` (everyFourMinutes, line 75), `ProcessLeadRecovery` (hourly, line 28 — would double-send LINE follow-ups), plus the cleanup DELETEs (lines 32, 48, 52-58, 61-66, 69-72).

- [ ] **Step 1: Capture the scheduler service's current config (inspect, for rollback).** Run:
  ```
  mcp__plugin_railway_railway__get_service_config(
    project_id="ba714504-2721-4535-9fc7-6b3d903c481a",
    service_id="7454f43a-cb20-411a-b435-bc74131a88c0")
  ```
  Expected (`live`, 2026-06-10 — save verbatim; this is the rollback recipe):
  ```
  Source repo: JaoChai/bot-fb
  Root directory: /backend
  Builder: DOCKERFILE
  Start command: php artisan config:cache && php artisan schedule:work
  Variables defined: 53
  ```

- [ ] **Step 2: Confirm the service is idle (inspect).** Run:
  ```
  mcp__plugin_railway_railway__service_metrics(
    project_id="ba714504-2721-4535-9fc7-6b3d903c481a",
    service_id="7454f43a-cb20-411a-b435-bc74131a88c0",
    hours_back=24,
    measurements=["CPU_USAGE","MEMORY_USAGE_GB"])
  ```
  Expected (`live`, 2026-06-10 — 1441 sample points, flat zero — service is not actively consuming resources, confirming it is redundant and safe to remove):
  ```
  ### CPU_USAGE
    Current: 0.0000  Average (1441pts): 0.0000  Min: 0.0000  Max: 0.0000
  ### MEMORY_USAGE_GB
    Current: 0.0000  Average (1441pts): 0.0000  Min: 0.0000  Max: 0.0000
  ```

- [ ] **Step 3: Delete the service (action).** Railway dashboard → project **bot-facebook** → service **scheduler** → **Settings** tab → scroll to **Danger** → **Remove Service** → confirm by typing the service name. (MCP equivalent, if preferred over UI: `mcp__plugin_railway_railway__remove_service(service_id="7454f43a-cb20-411a-b435-bc74131a88c0", project_id="ba714504-2721-4535-9fc7-6b3d903c481a")` — mutating; not run here, this is the plan.)

- [ ] **Step 4: Verify it's gone (verification).** Run:
  ```
  mcp__plugin_railway_railway__list_services(
    project_id="ba714504-2721-4535-9fc7-6b3d903c481a")
  ```
  Expected: list contains exactly **4** services — `Redis`, `reverb`, `backend`, `frontend` — and **no** `scheduler` (previously 5; the `7454f43a-...` entry is absent).

- [ ] **Step 5: Verify backend's supervisord scheduler still fires (verification).** Read the latest backend deployment logs (`get_logs`, loaded in OPS-1 Step 5) over a ~2 min window and confirm the per-minute schedule is running from inside the backend container (`backend/Dockerfile:115-122` → `backend/routes/console.php:14-17`). Expected: recurring `schedule:run` output naming the every-minute command, e.g.
  ```
  Running ['artisan' conversations:auto-enable-bots] ...... DONE
  ```
  appearing roughly once per minute, with **single** (not doubled) execution. Optionally confirm `db:ping` shows up on the 4-minute cadence (`backend/routes/console.php:75`).

- [ ] **Rollback note:** Re-create the service from the Step 1 capture: Railway → **+ New** → **GitHub Repo** → `JaoChai/bot-fb`; then **Settings**: Root Directory `/backend`, Builder **Dockerfile**, Start Command `php artisan config:cache && php artisan schedule:work`, and re-attach its 53 env vars (clone from backend's variable set / shared variables). Because both schedulers fire the same `routes/console.php`, the duplicate is functionally restored on next deploy.

---

### Task OPS-3: Remove `NIXPACKS_NO_CACHE=1` from the frontend service

**Files:** none (Railway console / MCP only). Frontend service `725c69c1-acc7-4b44-8eda-5fd09a2e1538`, Builder **NIXPACKS** (`live`), root `/frontend`.

- [ ] **Step 1: Read the current value (inspect).** Run:
  ```
  mcp__plugin_railway_railway__list_variables(
    project_id="ba714504-2721-4535-9fc7-6b3d903c481a",
    service_id="725c69c1-acc7-4b44-8eda-5fd09a2e1538")
  ```
  Expected (`live`, 2026-06-10 — note the first line; record the full set so the var can be restored exactly):
  ```
  NIXPACKS_NO_CACHE=1
  RAILWAY_ENVIRONMENT=production
  ... (VITE_API_URL, VITE_REVERB_* etc.) ...
  ```
  `NIXPACKS_NO_CACHE=1` applies because the frontend builder is **NIXPACKS** (`get_service_config(frontend)` → `Builder: NIXPACKS`, `live`). It forces a no-layer-cache rebuild (full `npm ci`) every deploy. **Why it was likely set** (`inferred`): a one-off stale-cache workaround during an earlier Vite/dep bump — not a permanent requirement.

- [ ] **Step 2: Remove the variable (action).** Railway dashboard → project **bot-facebook** → service **frontend** → **Variables** tab → row `NIXPACKS_NO_CACHE` → **⋯ / trash** → **Delete** → **Deploy** to apply. (This does not touch any `VITE_*` build vars, so the bundle config is unchanged.)

- [ ] **Step 3: Verify the variable is gone (verification).** Re-run:
  ```
  mcp__plugin_railway_railway__list_variables(
    project_id="ba714504-2721-4535-9fc7-6b3d903c481a",
    service_id="725c69c1-acc7-4b44-8eda-5fd09a2e1538")
  ```
  Expected: output no longer contains a `NIXPACKS_NO_CACHE` line; all `VITE_*` and `RAILWAY_*` vars remain (count drops from 7 listed values incl. injected to one fewer; "Variables defined" goes 6 → 5).

- [ ] **Step 4: Verify next build uses cache and succeeds (verification).** Trigger/await one frontend redeploy, then read its build logs (`get_logs`, frontend deployment). Expected: Nixpacks reuses cached layers instead of a cold full install — log shows cache reuse for the npm install step (e.g. `CACHED [stage-0 ... npm ci]` / restored Nixpacks cache mount) rather than a fresh `npm ci` downloading every package — and the build ends **green** (`Build succeeded` / deployment `SUCCESS`). Compare the build duration to the pre-change deploy from Step 1's history — expect it to drop.

- [ ] **Rollback note:** If a cached build produces a stale/broken bundle, re-add the var: Railway → frontend → **Variables** → **New Variable** `NIXPACKS_NO_CACHE` = `1` → Deploy (MCP equivalent: `mcp__plugin_railway_railway__set_variables` with `{"NIXPACKS_NO_CACHE":"1"}` for the frontend service). This restores the forced full-rebuild behavior immediately.

---

## PR-1 — DevOps / build hygiene

> **DEPENDS ON Ops OPS-1** (builder confirm). Task PR1-D2 must not be merged/deployed until OPS-1 has confirmed the Railway **backend** service builds from `backend/Dockerfile` (the service config currently reports `RAILPACK` while build logs show a Dockerfile build — a redeploy under the wrong builder could boot the `Procfile` dev-server `php artisan serve` stack). PR1-D3/F4/F5 are independent of OPS-1 and may proceed regardless.

> **NOTE (verified 2026-06-10):** The backend has **no** tracked `railway.json`/`railway.toml` (`git ls-files backend/railway.*` → empty), so the builder is set only in the Railway UI. The sole in-repo references to the files being deleted are: `frontend/railway.json:5` → `"nixpacksConfigPath": "nixpacks.toml"` which points at **`frontend/nixpacks.toml`** (a different file — leave it), and `backend/app/Support/QueueRouter.php:11` docblock which names `backend/Procfile` (orphan cleaned up inside PR1-D2). Current working branch in the repo is `refactor/cost-perf-quick-wins`; this PR still branches off `main` per the plan.

Branch: `chore/devops-build-hygiene` off main

### Task PR1-D2: Delete stale `backend/Procfile` + `backend/nixpacks.toml`

**Files:**
- Delete: `backend/Procfile`
- Delete: `backend/nixpacks.toml`
- Modify: `backend/app/Support/QueueRouter.php:11` (orphaned docblock reference)

- [ ] **Step 1: Read the two files being removed (no edit — confirm what/why).**
  `backend/Procfile` (current, line 1 is the dev-server `web:` line that is the footgun):
  ```
  web: php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=$PORT
  worker-llm: php artisan queue:work --queue=llm,webhooks,default --sleep=3 --tries=3 --backoff=5 --max-jobs=1000 --max-time=3600 --timeout=160
  worker-fast: php artisan queue:work --queue=webhooks,default --sleep=3 --tries=3 --backoff=5 --max-jobs=1000 --max-time=3600 --timeout=160
  worker-db: php artisan queue:work database --queue=llm,webhooks,default --sleep=3 --tries=3 --backoff=5 --max-jobs=1000 --max-time=3600 --timeout=160
  reverb: php artisan config:cache && php artisan reverb:start --host=0.0.0.0 --port=$PORT
  scheduler: php artisan config:cache && php artisan schedule:work
  ```
  `backend/nixpacks.toml` `[start]` (line 20) also ends in `php artisan serve` — same dev-server footgun. Production actually runs nginx + php-fpm + supervisord from `backend/Dockerfile` (the supervisord block at `backend/Dockerfile:91-167` is the real process list), so both files are vestigial.

- [ ] **Step 2: Prove nothing in backend code/config references these files (only the QueueRouter docblock).**
  ```bash
  cd /Users/jaochai/Code/bot-fb/backend && grep -rn "Procfile\|nixpacks" app/ config/ routes/ bootstrap/ public/
  ```
  Expected: exactly one line —
  ```
  app/Support/QueueRouter.php:11: * backend/Dockerfile (supervisor blocks) and backend/Procfile.
  ```
  (Confirms the only live reference is the docblock cleaned up in Step 3.)

- [ ] **Step 3: Remove the orphaned `Procfile` mention from the QueueRouter docblock.**
  Current (`backend/app/Support/QueueRouter.php:9-12`):
  ```php
   * Queue names here must stay in sync with the worker --queue= lists in
   * backend/Dockerfile (supervisor blocks) and backend/Procfile.
   */
  ```
  Replace with:
  ```php
   * Queue names here must stay in sync with the worker --queue= lists in
   * backend/Dockerfile (supervisor blocks).
   */
  ```

- [ ] **Step 4: Confirm the QueueRouter change is comment-only and tests still pass.**
  ```bash
  cd /Users/jaochai/Code/bot-fb/backend && php artisan test --filter='QueueRouter'
  ```
  Expected: green — both existing tests pass, e.g.
  ```
  PASS  Tests\Unit\QueueRouterConnectionTest
  PASS  Tests\Unit\Support\QueueRouterTest
  Tests:    ... passed
  ```

- [ ] **Step 5: Delete both files from git.**
  ```bash
  cd /Users/jaochai/Code/bot-fb && git rm backend/Procfile backend/nixpacks.toml
  ```
  Expected:
  ```
  rm 'backend/Procfile'
  rm 'backend/nixpacks.toml'
  ```

- [ ] **Step 6: (OPS-1 gate) Confirm the live backend builder is Dockerfile before merge.**
  Read-only Railway MCP check (service `36066744`):
  ```
  mcp__plugin_railway_railway__get_service_config(service: "36066744")
  ```
  Expected: builder resolves to Dockerfile (OPS-1 already corrected the `RAILPACK` discrepancy). If it still reports `RAILPACK`, **stop** — OPS-1 is not done; do not merge this task.

- [ ] **Step 7: Commit.**
  ```bash
  cd /Users/jaochai/Code/bot-fb && git commit -am "chore(backend): remove stale Procfile and nixpacks.toml dev-server config"
  ```
  Expected: commit created with 3 files changed (2 deletions + QueueRouter docblock).

---

### Task PR1-D3: Slow `queue-worker-db` fallback poll from 3s → 60s

**Files:**
- Modify: `backend/Dockerfile:157` (`[program:queue-worker-db]` command)

> **NOTE:** `worker-db` is the **Redis-outage fallback** consumer. `backend/app/Support/QueueRouter.php:28-31` returns the `database` connection **only** when Redis is down (`RedisHealthGate::isRedisUp()` false), otherwise `null` (Redis). So 99% of the time `worker-db` polls an **empty** Neon `jobs` table once per `--sleep`. Keep the worker (it must stay always-on to drain jobs during an outage), just stop it hammering Neon ~1×/sec. The Dockerfile comment at `backend/Dockerfile:153-154` already documents this role.

- [ ] **Step 1: Confirm the exact current line (only the `database` worker has `queue:work database`).**
  ```bash
  cd /Users/jaochai/Code/bot-fb && grep -n "queue:work database" backend/Dockerfile
  ```
  Expected:
  ```
  157:command=php /var/www/html/artisan queue:work database --queue=llm,webhooks,default --sleep=3 --tries=3 --backoff=5 --max-jobs=1000 --max-time=3600 --timeout=160
  ```

- [ ] **Step 2: Change `--sleep=3` → `--sleep=60` on that one line.**
  Current (`backend/Dockerfile:157`):
  ```
  command=php /var/www/html/artisan queue:work database --queue=llm,webhooks,default --sleep=3 --tries=3 --backoff=5 --max-jobs=1000 --max-time=3600 --timeout=160
  ```
  Replace with:
  ```
  command=php /var/www/html/artisan queue:work database --queue=llm,webhooks,default --sleep=60 --tries=3 --backoff=5 --max-jobs=1000 --max-time=3600 --timeout=160
  ```
  (Leave `queue-worker-llm:126` and `queue-worker-fast:142` at `--sleep=3` — they drain the live Redis queues.)

- [ ] **Step 3: Verify only the database worker changed.**
  ```bash
  cd /Users/jaochai/Code/bot-fb && grep -n "sleep=60\|sleep=3" backend/Dockerfile
  ```
  Expected (exactly three lines — db at 60, llm + fast still at 3):
  ```
  126:command=php /var/www/html/artisan queue:work --queue=llm,webhooks,default --sleep=3 ...
  142:command=php /var/www/html/artisan queue:work --queue=webhooks,default --sleep=3 ...
  157:command=php /var/www/html/artisan queue:work database --queue=llm,webhooks,default --sleep=60 ...
  ```

- [ ] **Step 4: Commit.**
  ```bash
  cd /Users/jaochai/Code/bot-fb && git commit -am "perf(queue): slow worker-db fallback poll from 3s to 60s to cut Neon jobs seq_scan"
  ```
  Expected: commit created, 1 file changed.

- [ ] **Step 5: (post-deploy, read-only) Confirm Neon `jobs` seq_scan growth slows.**
  After this PR deploys, run before/after (Neon MCP, project `solitary-math-34010034`):
  ```sql
  SELECT relname, seq_scan FROM pg_stat_user_tables WHERE relname = 'jobs';
  ```
  Expected: `seq_scan` for `jobs` increments ~20× slower than baseline (≈1/60s vs ≈1/3s per worker-db idle poll). This is an observation, not a merge gate.

---

### Task PR1-F4: Remove dead dep `@radix-ui/react-checkbox` + orphan `checkbox.tsx`

**Files:**
- Delete: `frontend/src/components/ui/checkbox.tsx`
- Modify: `frontend/package.json:23` (drop the dependency line)

> **NOTE:** Spec says "knip-confirmed unused", but `knip` is **not** a project dependency (`node_modules/.bin/knip` absent, not in `package.json`). To avoid a network `npx` fetch, this task proves disuse with `grep` + the already-in-CI `tsc --noEmit` instead of running `npx knip`/`npm run build`. (`npm run build` would also trigger the `prebuild` vitest run until PR1-F5 lands.)

- [ ] **Step 1: Prove `checkbox.tsx` is the only importer of the dep and nothing imports the component.**
  ```bash
  cd /Users/jaochai/Code/bot-fb/frontend && echo "--- dep importers ---"; grep -rn "@radix-ui/react-checkbox" src/; echo "--- component importers ---"; grep -rn "ui/checkbox" src/
  ```
  Expected:
  ```
  --- dep importers ---
  src/components/ui/checkbox.tsx:2:import * as CheckboxPrimitive from "@radix-ui/react-checkbox"
  --- component importers ---
  ```
  (Only `checkbox.tsx` itself imports the dep; **no** file imports the `ui/checkbox` component. The `DropdownMenuCheckboxItem` / `[role=checkbox]` hits in `dropdown-menu.tsx`/`table.tsx` come from `@radix-ui/react-dropdown-menu` (`dropdown-menu.tsx:2`) and a CSS attribute selector — unrelated to this dep. There is also no `components/ui/index.*` barrel re-exporting it.)

- [ ] **Step 2: Delete the orphan component file.**
  ```bash
  cd /Users/jaochai/Code/bot-fb && git rm frontend/src/components/ui/checkbox.tsx
  ```
  Expected:
  ```
  rm 'frontend/src/components/ui/checkbox.tsx'
  ```

- [ ] **Step 3: Remove the dependency from `package.json`.**
  Current (`frontend/package.json:22-24`):
  ```json
      "@radix-ui/react-avatar": "^1.1.11",
      "@radix-ui/react-checkbox": "^1.3.3",
      "@radix-ui/react-collapsible": "^1.1.12",
  ```
  Replace with:
  ```json
      "@radix-ui/react-avatar": "^1.1.11",
      "@radix-ui/react-collapsible": "^1.1.12",
  ```

- [ ] **Step 4: Refresh the lockfile (removes the dep from `package-lock.json`).**
  ```bash
  cd /Users/jaochai/Code/bot-fb/frontend && npm install
  ```
  Expected: completes; `git diff --stat frontend/package-lock.json` shows `@radix-ui/react-checkbox` entries removed (no other dep version churn). If the sandbox blocks the registry, run with network access.

- [ ] **Step 5: Confirm the tree still type-checks with no dangling import.**
  ```bash
  cd /Users/jaochai/Code/bot-fb/frontend && npx tsc --noEmit
  ```
  Expected: no output, exit 0 (same command CI uses at `.github/workflows/ci.yml:63-65`). Also confirm the dep is gone:
  ```bash
  cd /Users/jaochai/Code/bot-fb/frontend && grep -c "react-checkbox" package.json
  ```
  Expected: `0`.

- [ ] **Step 6: Commit.**
  ```bash
  cd /Users/jaochai/Code/bot-fb && git commit -am "chore(frontend): drop unused @radix-ui/react-checkbox dep and orphan checkbox component"
  ```
  Expected: commit created (checkbox.tsx deleted, package.json + package-lock.json updated).

---

### Task PR1-F5: Stop running vitest during the Railway `vite build`

**Files:**
- Modify: `frontend/package.json:11` (remove the `prebuild` lifecycle hook)

> **NOTE (spec drift):** The spec's "ensure ci.yml runs `vitest run` as a separate job/step" is **already satisfied** — `.github/workflows/ci.yml:60-62` (`frontend-checks` job) already runs `npm run test` (= the `test` script = `vitest run`). So **no `ci.yml` edit is needed**; the only change is deleting the `prebuild` hook. `frontend/nixpacks.toml` (Railway frontend build) runs `npm run build`, and npm fires the `prebuild` lifecycle hook before `build`, so today every deploy runs the full vitest suite. Vitest sets `NODE_ENV=test` itself, so the CI `npm run test` step preserves the removed `NODE_ENV=test` behavior.

- [ ] **Step 1: Confirm the current scripts and that CI already runs tests.**
  ```bash
  cd /Users/jaochai/Code/bot-fb && sed -n '9,18p' frontend/package.json; echo "--- ci test step ---"; sed -n '60,62p' .github/workflows/ci.yml
  ```
  Expected:
  ```
    "scripts": {
      "dev": "vite",
      "prebuild": "NODE_ENV=test vitest run",
      "build": "tsc -b && vite build",
      ...
      "test": "vitest run",
      ...
  --- ci test step ---
        - name: Run tests
          working-directory: frontend
          run: npm run test
  ```
  (Confirms CI already covers vitest, so removing `prebuild` loses no CI coverage.)

- [ ] **Step 2: Remove the `prebuild` line so `vite build` no longer triggers vitest.**
  Current (`frontend/package.json:10-12`):
  ```json
      "dev": "vite",
      "prebuild": "NODE_ENV=test vitest run",
      "build": "tsc -b && vite build",
  ```
  Replace with:
  ```json
      "dev": "vite",
      "build": "tsc -b && vite build",
  ```

- [ ] **Step 3: Verify `npm run build` no longer invokes vitest, and CI still does.**
  ```bash
  cd /Users/jaochai/Code/bot-fb/frontend && echo "--- build script chain ---"; npm run build --dry-run 2>/dev/null || node -e "const s=require('./package.json').scripts; console.log('prebuild:', s.prebuild ?? 'ABSENT'); console.log('build:', s.build)"; echo "--- ci still runs vitest ---"; grep -n "npm run test" ../.github/workflows/ci.yml
  ```
  Expected:
  ```
  prebuild: ABSENT
  build: tsc -b && vite build
  --- ci still runs vitest ---
  62:          run: npm run test
  ```
  (No `prebuild` hook remains; `vite build` runs only `tsc -b && vite build`; CI's `frontend-checks` still runs vitest.)

- [ ] **Step 4: Commit.**
  ```bash
  cd /Users/jaochai/Code/bot-fb && git commit -am "chore(frontend): stop running vitest during vite build (CI already covers tests)"
  ```
  Expected: commit created, `frontend/package.json` 1 file changed.

---

### Task PR1-LAST: Push branch + open PR

**Files:** (none — git/gh only)

- [ ] **Step 1: Confirm the four commits are present and the working tree is clean.**
  ```bash
  cd /Users/jaochai/Code/bot-fb && git log --oneline main..HEAD; git status --short
  ```
  Expected: four commits (D2, D3, F4, F5 messages above) and an empty `git status` (clean tree).

- [ ] **Step 2: Push the branch.**
  ```bash
  cd /Users/jaochai/Code/bot-fb && git push -u origin chore/devops-build-hygiene
  ```
  Expected: branch published; prints the `origin/chore/devops-build-hygiene` tracking line.

- [ ] **Step 3: Open the PR.**
  ```bash
  cd /Users/jaochai/Code/bot-fb && gh pr create --base main --head chore/devops-build-hygiene \
    --title "chore(devops): build hygiene — drop stale Procfile/nixpacks, slow worker-db poll, prune dead checkbox dep, move vitest out of build" \
    --body "$(cat <<'EOF'
## PR-1 — DevOps / build hygiene

Low-risk build/config hygiene from the 2026-06-10 cost+perf quick-wins spec. No app-logic changes.

### Changes
- **D2** Delete `backend/Procfile` + `backend/nixpacks.toml` — vestigial dev-server config (`Procfile` `web:` = `php artisan serve`); prod runs nginx+php-fpm+supervisord from `backend/Dockerfile`. Also drops the now-orphaned `backend/Procfile` mention in `QueueRouter.php`'s docblock. **Gated on Ops OPS-1** confirming the Railway backend builder = Dockerfile.
- **D3** `backend/Dockerfile` `[program:queue-worker-db]`: `--sleep=3 → 60`. worker-db is the always-on Redis-outage fallback (`QueueRouter::connection()` returns `database` only when Redis is down); slowing its idle poll cuts ~1/sec seq_scans on Neon's empty `jobs` table. Worker kept; llm/fast workers untouched.
- **F4** Remove dead dep `@radix-ui/react-checkbox` + orphan `frontend/src/components/ui/checkbox.tsx` (grep + `tsc --noEmit` confirm no importers).
- **F5** Remove the `prebuild` (`NODE_ENV=test vitest run`) hook so Railway's `vite build` no longer runs the full test suite each deploy. CI (`.github/workflows/ci.yml` `frontend-checks`) already runs vitest via `npm run test`, so test coverage is unchanged.

### Verification
- `grep -n "queue:work database" backend/Dockerfile` → `--sleep=60`
- `grep -c "react-checkbox" frontend/package.json` → `0`; `npx tsc --noEmit` clean
- `frontend/package.json` has no `prebuild`; CI `npm run test` step intact
- Post-deploy: backend still builds from Dockerfile; Neon `jobs` seq_scan growth slows

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
  ```
  Expected: `gh` prints the new PR URL (`https://github.com/JaoChai/bot-fb/pull/<n>`).

---

Relevant files (all absolute): `/Users/jaochai/Code/bot-fb/backend/Procfile`, `/Users/jaochai/Code/bot-fb/backend/nixpacks.toml`, `/Users/jaochai/Code/bot-fb/backend/Dockerfile` (line 157), `/Users/jaochai/Code/bot-fb/backend/app/Support/QueueRouter.php` (line 11 + 28-31), `/Users/jaochai/Code/bot-fb/frontend/package.json` (lines 11, 23), `/Users/jaochai/Code/bot-fb/frontend/src/components/ui/checkbox.tsx`, `/Users/jaochai/Code/bot-fb/frontend/nixpacks.toml`, `/Users/jaochai/Code/bot-fb/.github/workflows/ci.yml` (lines 60-62).

---

## PR-2 — Frontend perf

Branch: `perf/frontend-critical-path` off main

> NOTE (drift checks, verified 2026-06-10 against live files):
> - `authStore.ts` static import of echo is at **line 4** (`import { disconnectEcho, reconnectEcho } from '@/lib/echo';`) — spec anchor correct.
> - Render-blocking Google Fonts block in `index.html` is **lines 13–16** (comment + 2 `preconnect` + 1 `stylesheet`), not 14–16 as the spec said.
> - Font-weight audit: `font-light` (300) has **0** usages in `src/`; weights actually used are 400/500/600/700. So Noto Sans Thai `300` is dropped; Inter keeps all 4 (all used).
> - Current `dist/` baselines (real): `pusher` appears **102×** in the eager entry `dist/assets/index-*.js`; `pusher:*` lives **only** in that entry chunk. `DashboardPage-*.js` statically imports `vendor-charts-*.js` (`__vitePreload` count = 0). These ground the before/after expectations below.
> - Mount sites confirmed: `DashboardPage.tsx:128` (`DualAxisChart`), `:137` (`ProductsSummaryCard`); `OrdersPage.tsx:19` (`OrdersAnalytics`). All three are **named** exports → reuse the existing `lazyWithRetryNamed` helper (`src/lib/lazyWithRetry.ts`), same pattern as `src/router.tsx`.

---

### Task PR2-F1: Defer pusher-js + laravel-echo off the eager first-load

**Files:**
- Modify: `frontend/src/stores/authStore.ts` (line 4 import; `login` ~48–58; `logout` ~60–70)
- Test: `frontend/src/stores/authStore.test.ts` (mirror existing `vi.mock("@/lib/echo")` style — double quotes, no semicolons)

- [ ] **Step 1: Capture LCP + eager-bundle baselines (BEFORE any change).**
```bash
cd /Users/jaochai/Code/bot-fb/frontend && npm run build
# Eager-bundle pusher baseline (expect a large count now)
ENTRY=$(grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' dist/index.html | head -1)
echo "pusher in eager entry (before): $(grep -o pusher "dist$ENTRY" | wc -l | tr -d ' ')"
grep -rl "pusher:connection" dist/assets/*.js
# Lighthouse LCP baseline for the public /login path (eager bundle path F1+F3 affect most)
npm run preview &
npx -y lighthouse "http://localhost:4173/login" --preset=desktop --only-categories=performance \
  --quiet --chrome-flags="--headless=new" --output=json --output-path="$TMPDIR/pr2-lcp-login-before.json"
node -e "console.log('LCP /login (before):', require('$TMPDIR/pr2-lcp-login-before.json').audits['largest-contentful-paint'].displayValue)"
kill %1 2>/dev/null
```
Expected: `pusher in eager entry (before): 102` (any value > 0); the `grep -rl` prints only `dist/assets/index-*.js`; a printed `LCP /login (before): <Ns>`. **Record the LCP number in the PR description.**
> Authed pages: capture `/dashboard` and `/orders` LCP manually via Chrome DevTools → Lighthouse after logging in (they are auth-gated). Note both numbers for the after-comparison.

- [ ] **Step 2: Write the failing test (TDD red).** Add the echo-mock imports and a new describe block.

Edit 1 — add mock import after the existing store import:
```ts
import { useAuthStore } from "./authStore"
import { disconnectEcho, reconnectEcho } from "@/lib/echo"
```
Edit 2 — insert a new `describe` block inside `describe("authStore", ...)`, right after the `setLoading` block. Replace:
```ts
      useAuthStore.getState().setLoading(true)
      expect(useAuthStore.getState().isLoading).toBe(true)
    })
  })
})
```
with:
```ts
      useAuthStore.getState().setLoading(true)
      expect(useAuthStore.getState().isLoading).toBe(true)
    })
  })

  describe("lazy echo loading", () => {
    const validUser = {
      id: 1,
      name: "Test",
      email: "test@example.com",
      role: "owner" as const,
      email_verified_at: null,
      created_at: "2024-01-01",
      updated_at: "2024-01-01",
    }

    it("does not call echo on store init", () => {
      vi.clearAllMocks()
      // Reading the store must not touch realtime libs (laravel-echo + pusher-js)
      useAuthStore.getState()
      expect(reconnectEcho).not.toHaveBeenCalled()
      expect(disconnectEcho).not.toHaveBeenCalled()
    })

    it("defers echo reconnect off the synchronous login path", () => {
      vi.clearAllMocks()
      useAuthStore.getState().login(validUser, "tok")
      // Dynamic import() resolves in a later microtask, so within this tick
      // reconnectEcho has NOT run yet (it is no longer a static, synchronous call).
      expect(reconnectEcho).not.toHaveBeenCalled()
    })

    it("reconnects echo after login (async)", async () => {
      vi.clearAllMocks()
      useAuthStore.getState().login(validUser, "tok")
      await vi.waitFor(() => expect(reconnectEcho).toHaveBeenCalledTimes(1))
    })

    it("defers echo disconnect off the synchronous logout path", () => {
      vi.clearAllMocks()
      useAuthStore.getState().logout()
      expect(disconnectEcho).not.toHaveBeenCalled()
    })

    it("disconnects echo after logout (async)", async () => {
      vi.clearAllMocks()
      useAuthStore.getState().logout()
      await vi.waitFor(() => expect(disconnectEcho).toHaveBeenCalledTimes(1))
    })
  })
})
```
> NOTE: The two "defers … off the synchronous path" tests are the genuine red cases (current code calls `reconnectEcho`/`disconnectEcho` synchronously). The "reconnects/disconnects after … (async)" tests are green-both-ways behavior guards that prove realtime still wires up post-auth.

- [ ] **Step 3: Run the test — see it fail.**
```bash
cd /Users/jaochai/Code/bot-fb/frontend && npx vitest run src/stores/authStore.test.ts
```
Expected: red — `Tests  2 failed | 12 passed (14)`, with the two failures being `defers echo reconnect off the synchronous login path` and `defers echo disconnect off the synchronous logout path`.

- [ ] **Step 4: Implement — convert the static import to dynamic `import()` in the handlers.**

Edit A — remove the static import (line 4). Replace:
```ts
import type { User } from '@/types/api';
import { disconnectEcho, reconnectEcho } from '@/lib/echo';
```
with:
```ts
import type { User } from '@/types/api';
```
Edit B — `login` handler. Replace:
```ts
      login: (user, token) => {
        localStorage.setItem('auth_token', token);
        set({
          user,
          token,
          isAuthenticated: true,
          isLoading: false,
        });
        // Reconnect Echo with fresh token for real-time features
        reconnectEcho();
      },
```
with:
```ts
      login: (user, token) => {
        localStorage.setItem('auth_token', token);
        set({
          user,
          token,
          isAuthenticated: true,
          isLoading: false,
        });
        // Lazy-load realtime libs (laravel-echo + pusher-js) only after auth,
        // keeping them out of the eager first-load bundle.
        void import('@/lib/echo').then(({ reconnectEcho }) => reconnectEcho());
      },
```
Edit C — `logout` handler. Replace:
```ts
      logout: () => {
        // Disconnect Echo to prevent stale token reconnection attempts
        disconnectEcho();
        localStorage.removeItem('auth_token');
        set({
          user: null,
          token: null,
          isAuthenticated: false,
          isLoading: false,
        });
      },
```
with:
```ts
      logout: () => {
        localStorage.removeItem('auth_token');
        set({
          user: null,
          token: null,
          isAuthenticated: false,
          isLoading: false,
        });
        // Disconnect Echo to prevent stale token reconnection attempts.
        // Lazy import so realtime libs stay out of the eager bundle.
        void import('@/lib/echo').then(({ disconnectEcho }) => disconnectEcho());
      },
```

- [ ] **Step 5: Run the test — see it pass.**
```bash
cd /Users/jaochai/Code/bot-fb/frontend && npx vitest run src/stores/authStore.test.ts
```
Expected: green — `Test Files  1 passed (1)` / `Tests  14 passed (14)`.

- [ ] **Step 6: Build-grep verification — pusher gone from the eager entry chunk.**
```bash
cd /Users/jaochai/Code/bot-fb/frontend && npm run build
ENTRY=$(grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' dist/index.html | head -1)
echo "pusher in eager entry (after): $(grep -o pusher "dist$ENTRY" | wc -l | tr -d ' ')"
echo "pusher now lives in: $(grep -rl 'pusher:connection' dist/assets/*.js)"
```
Expected: `pusher in eager entry (after): 0`; the second line prints a **non-index** chunk (the dynamically-imported echo chunk), confirming pusher-js + laravel-echo moved off first-load while still shipping for post-login realtime.

- [ ] **Step 7: Commit.**
```bash
cd /Users/jaochai/Code/bot-fb && git checkout -b perf/frontend-critical-path && \
git add frontend/src/stores/authStore.ts frontend/src/stores/authStore.test.ts && \
git commit -m "perf(frontend): defer pusher-js + laravel-echo off eager bundle

Convert authStore's static @/lib/echo import to a dynamic import() inside
login/logout so realtime libs load only post-auth. Removes ~102 pusher refs
from the eager index chunk; verified absent via build-grep.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task PR2-F2: Lazy-load recharts chart components

**Files:**
- Modify: `frontend/src/pages/DashboardPage.tsx` (imports 1–21; `DualAxisChart` mount :128; `ProductsSummaryCard` mount :137)
- Modify: `frontend/src/pages/OrdersPage.tsx` (imports 1–3; `OrdersAnalytics` mount :19)
- Verify: built `dist/assets/DashboardPage-*.js`, `OrdersPage-*.js`

> NOTE: No component tests exist for these pages (`find src/pages src/components/{dashboard,analytics} -name '*.test.tsx'` → none), and behavior is unchanged, so verification is build-structure + the existing `npm run build` typecheck rather than TDD.

- [ ] **Step 1: Capture F2 baseline (charts currently STATIC-imported by the page chunk).**
```bash
cd /Users/jaochai/Code/bot-fb/frontend && npm run build
DP=$(ls dist/assets/DashboardPage-*.js); OP=$(ls dist/assets/OrdersPage-*.js)
echo "DashboardPage __vitePreload (before): $(grep -o __vitePreload "$DP" | wc -l | tr -d ' ')"
echo "DashboardPage references: $(grep -oE 'vendor-charts-[A-Za-z0-9_-]+\.js' "$DP" | head -1)"
echo "OrdersPage __vitePreload (before): $(grep -o __vitePreload "$OP" | wc -l | tr -d ' ')"
```
Expected: both `__vitePreload (before): 0` and `DashboardPage references: vendor-charts-*.js` → confirms recharts is a **static** import of each page chunk today (pulled before the cards paint).

- [ ] **Step 2: Convert DashboardPage charts to `lazy` + `Suspense`.**

Edit A — imports. Replace the import block (lines 1–21):
```ts
import { useMemo } from 'react';
import { ShoppingCart, DollarSign, MessageSquare, Banknote } from 'lucide-react';
import { formatTHB, formatBaht } from '@/lib/currency';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/connections';
import { useDashboardSummary } from '@/hooks/useDashboard';
import { useCostAnalytics } from '@/hooks/useCostAnalytics';
import { useOrderSummary, useOrdersByProduct } from '@/hooks/useOrders';
import {
  DashboardStatCard,
  RecentActivityTimeline,
  DashboardSkeleton,
  BotStatusList,
  BusinessHealthBar,
  DualAxisChart,
  CompactCostBreakdown,
  CompactStockToggle,
  RecentOrdersPreview,
  ProductsSummaryCard,
} from '@/components/dashboard';
import { useAuthStore } from '@/stores/authStore';
```
with:
```ts
import { useMemo, Suspense } from 'react';
import { ShoppingCart, DollarSign, MessageSquare, Banknote } from 'lucide-react';
import { formatTHB, formatBaht } from '@/lib/currency';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/connections';
import { useDashboardSummary } from '@/hooks/useDashboard';
import { useCostAnalytics } from '@/hooks/useCostAnalytics';
import { useOrderSummary, useOrdersByProduct } from '@/hooks/useOrders';
import {
  DashboardStatCard,
  RecentActivityTimeline,
  DashboardSkeleton,
  BotStatusList,
  BusinessHealthBar,
  CompactCostBreakdown,
  CompactStockToggle,
  RecentOrdersPreview,
} from '@/components/dashboard';
import { lazyWithRetryNamed } from '@/lib/lazyWithRetry';
import { useAuthStore } from '@/stores/authStore';

// Charts pull the 109 KB-gzip recharts (vendor-charts) chunk. Lazy-load them so
// the metric cards above paint before recharts is fetched.
const DualAxisChart = lazyWithRetryNamed(
  () => import('@/components/dashboard/DualAxisChart'),
  'DualAxisChart',
);
const ProductsSummaryCard = lazyWithRetryNamed(
  () => import('@/components/dashboard/ProductsSummaryCard'),
  'ProductsSummaryCard',
);
```
> NOTE: `DualAxisChart`/`ProductsSummaryCard` are removed from the `@/components/dashboard` barrel import here and imported directly via `lazy`. The barrel (`index.ts`) still re-exports them, but DashboardPage was the only consumer (`grep` confirmed), so Rollup tree-shakes the now-unused re-export out of this chunk's graph.

Edit B — `DualAxisChart` mount (:128). Replace:
```tsx
      <DualAxisChart
        orderTimeSeries={orderData?.time_series ?? []}
        costTimeSeries={costData?.time_series ?? []}
        vipCustomers={data?.summary.vip_customers}
        vipTotalSpent={data?.summary.vip_total_spent}
      />
```
with:
```tsx
      <Suspense
        fallback={
          <div className="rounded-xl border bg-card p-6 shadow-sm">
            <Skeleton className="mb-4 h-5 w-48" />
            <Skeleton className="h-[300px] w-full rounded-lg" />
          </div>
        }
      >
        <DualAxisChart
          orderTimeSeries={orderData?.time_series ?? []}
          costTimeSeries={costData?.time_series ?? []}
          vipCustomers={data?.summary.vip_customers}
          vipTotalSpent={data?.summary.vip_total_spent}
        />
      </Suspense>
```
(Fallback mirrors the existing `DashboardSkeleton` chart block — same `h-[300px]` height to avoid CLS.)

Edit C — `ProductsSummaryCard` mount (:137). Replace:
```tsx
        {productsData && productsData.length > 0 && <ProductsSummaryCard products={productsData} />}
```
with:
```tsx
        {productsData && productsData.length > 0 && (
          <Suspense
            fallback={
              <div className="rounded-xl border bg-card p-6 shadow-sm">
                <Skeleton className="mb-4 h-5 w-36" />
                <div className="grid gap-6 md:grid-cols-2">
                  <div className="space-y-3">
                    {[...Array(5)].map((_, i) => (
                      <div key={i} className="flex items-center gap-3">
                        <Skeleton className="size-7 rounded-full" />
                        <Skeleton className="h-4 flex-1" />
                        <Skeleton className="h-4 w-16" />
                      </div>
                    ))}
                  </div>
                  <Skeleton className="h-[200px] w-full rounded-lg" />
                </div>
              </div>
            }
          >
            <ProductsSummaryCard products={productsData} />
          </Suspense>
        )}
```
(Fallback mirrors `DashboardSkeleton`'s products block.)

- [ ] **Step 3: Convert OrdersPage to lazy `OrdersAnalytics` + `Suspense`.**

Edit A — imports (lines 1–3). Replace:
```tsx
import { useMemo } from 'react';
import { PageHeader } from '@/components/connections';
import { OrdersAnalytics } from '@/components/analytics/OrdersAnalytics';
```
with:
```tsx
import { useMemo, Suspense } from 'react';
import { PageHeader } from '@/components/connections';
import { lazyWithRetryNamed } from '@/lib/lazyWithRetry';

// OrdersAnalytics pulls recharts (vendor-charts). Lazy-load so the orders route
// chunk stays light and recharts loads as a separate async chunk.
const OrdersAnalytics = lazyWithRetryNamed(
  () => import('@/components/analytics/OrdersAnalytics'),
  'OrdersAnalytics',
);
```
Edit B — mount (:19). Replace:
```tsx
      <PageHeader title="ออเดอร์" meta={today} />
      <OrdersAnalytics />
```
with:
```tsx
      <PageHeader title="ออเดอร์" meta={today} />
      <Suspense
        fallback={
          <div className="flex items-center justify-center py-12">
            <div className="text-muted-foreground">กำลังโหลดข้อมูล...</div>
          </div>
        }
      >
        <OrdersAnalytics />
      </Suspense>
```
(Fallback mirrors `OrdersAnalytics`'s own `summaryLoading` block.)

- [ ] **Step 4: Build (typechecks via `tsc -b`) — see it succeed.**
```bash
cd /Users/jaochai/Code/bot-fb/frontend && npm run build
```
Expected: `✓ built in …`, no TS errors, and the chunk list still shows a separate `vendor-charts-*.js`.

- [ ] **Step 5: Build-grep verification — charts now load dynamically.**
```bash
cd /Users/jaochai/Code/bot-fb/frontend
DP=$(ls dist/assets/DashboardPage-*.js); OP=$(ls dist/assets/OrdersPage-*.js)
echo "DashboardPage __vitePreload (after): $(grep -o __vitePreload "$DP" | wc -l | tr -d ' ')"
echo "OrdersPage __vitePreload (after): $(grep -o __vitePreload "$OP" | wc -l | tr -d ' ')"
echo "vendor-charts NOT modulepreloaded in dashboard first paint:"; grep -c "vendor-charts" dist/index.html
```
Expected: both `__vitePreload (after): ≥1` (recharts is now reached through Vite's dynamic-import preload, i.e. an async chunk fetched on chart mount rather than a static top-level import) and `0` for the `dist/index.html` check (vendor-charts is not preloaded on first paint). Cross-check with a Lighthouse/Network re-run: `vendor-charts-*.js` request starts after the page chunk renders the metric cards.

- [ ] **Step 6: Commit.**
```bash
cd /Users/jaochai/Code/bot-fb && \
git add frontend/src/pages/DashboardPage.tsx frontend/src/pages/OrdersPage.tsx && \
git commit -m "perf(frontend): lazy-load recharts chart components

Wrap DualAxisChart/ProductsSummaryCard (dashboard) and OrdersAnalytics (orders)
in lazy + Suspense so metric cards paint before the 109 KB-gzip vendor-charts
chunk loads. Verified: page chunks now reach recharts via __vitePreload.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task PR2-F3: Self-host + trim web fonts off the critical path

**Decision (one approach, justified):** Self-host via `@fontsource/inter` + `@fontsource/noto-sans-thai`, importing only the weights actually used (400/500/600/700). This **removes both third-party origins** (`fonts.googleapis.com` + `fonts.gstatic.com`) and the render-blocking external stylesheet from the LCP path — fonts become same-origin, hashed, long-cacheable bundle assets. We keep the family names `Inter` / `Noto Sans Thai` (the default `@fontsource/*` — non-variable — registers exactly those names), so `index.css:184`'s `font-family: 'Inter', 'Noto Sans Thai', …` needs **no change**. We drop Noto Sans Thai weight `300` (0 usages of `font-light`). `unicode-range` subsetting means the browser only fetches the Latin (Inter) / Thai (Noto) glyphs it needs.

**Files:**
- Modify: `frontend/package.json` (via `npm install` — adds 2 deps)
- Modify: `frontend/src/main.tsx` (after the `import "./index.css"` at line 11)
- Modify: `frontend/index.html` (remove lines 13–16)

- [ ] **Step 1: Install the font packages.**
```bash
cd /Users/jaochai/Code/bot-fb/frontend && npm install @fontsource/inter @fontsource/noto-sans-thai
```
Expected: `@fontsource/inter` and `@fontsource/noto-sans-thai` added to `dependencies` in `package.json` (+ `package-lock.json` updated), `added N packages`.

- [ ] **Step 2: Import the self-hosted weights in `main.tsx`.** Replace:
```ts
import "./index.css"
```
with:
```ts
import "./index.css"
// Self-hosted fonts (replace render-blocking Google Fonts). Weights match the
// Tailwind font utilities used: 400/500/600/700. Noto Sans Thai 300 is unused
// and omitted. unicode-range subsetting downloads only Latin/Thai glyphs needed.
import "@fontsource/inter/400.css"
import "@fontsource/inter/500.css"
import "@fontsource/inter/600.css"
import "@fontsource/inter/700.css"
import "@fontsource/noto-sans-thai/400.css"
import "@fontsource/noto-sans-thai/500.css"
import "@fontsource/noto-sans-thai/600.css"
import "@fontsource/noto-sans-thai/700.css"
```
(Side-effect CSS imports — same form as the existing `import "./index.css"`, so TS/Vite handle them with no extra typing.)

- [ ] **Step 3: Remove the Google Fonts block from `index.html`.** Replace (lines 13–16):
```html
    <!-- Google Fonts: Inter + Noto Sans Thai -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
```
with:
```html
    <!-- Fonts self-hosted via @fontsource (imported in src/main.tsx) -->
```
(Keep the `api.botjao.com` dns-prefetch/preconnect on lines 10–11.)

- [ ] **Step 4: Build verification — no third-party font origins, fonts emitted same-origin.**
```bash
cd /Users/jaochai/Code/bot-fb/frontend && npm run build
echo "google font refs in built html: $(grep -c 'fonts.googleapis\|fonts.gstatic' dist/index.html)"
echo "self-hosted woff2 emitted: $(ls dist/assets/*.woff2 2>/dev/null | wc -l | tr -d ' ')"
```
Expected: `google font refs in built html: 0` and `self-hosted woff2 emitted:` ≥ 8 (4 Inter-latin + 4 Noto-Thai; `@fontsource` may emit additional subset files — count just needs to be > 0 and the googleapis/gstatic count must be 0). Baseline reference: current `dist` ships **0** woff2 and references googleapis.

- [ ] **Step 5: Lighthouse LCP re-measure (manual, vs PR2-F1 baseline).** Re-run the Step-1 Lighthouse command from PR2-F1 against `/login`, save to `$TMPDIR/pr2-lcp-login-after.json`, and record `/dashboard` + `/orders` LCP manually. Expected: LCP ≤ baseline on all 3 pages (fewer render-blocking font requests + no cross-origin handshake). Put before/after numbers in the PR description.

- [ ] **Step 6: Commit.**
```bash
cd /Users/jaochai/Code/bot-fb && \
git add frontend/package.json frontend/package-lock.json frontend/src/main.tsx frontend/index.html && \
git commit -m "perf(frontend): self-host + trim fonts off the critical path

Replace render-blocking Google Fonts (Inter 4w + Noto Sans Thai 5w across
googleapis+gstatic) with same-origin @fontsource imports, weights 400/500/600/700
only (drop unused Noto 300). Removes 2 cross-origin handshakes + a blocking
external stylesheet from the LCP path.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task PR2-LAST: Push branch + open PR

- [ ] **Step 1: Push the branch.**
```bash
cd /Users/jaochai/Code/bot-fb && git push -u origin perf/frontend-critical-path
```
Expected: branch published; `gh` prints the remote tracking ref.

- [ ] **Step 2: Open the PR.**
```bash
cd /Users/jaochai/Code/bot-fb && gh pr create --base main --head perf/frontend-critical-path \
  --title "perf(frontend): critical-path quick wins (defer echo, lazy charts, self-host fonts)" \
  --body "$(cat <<'EOF'
PR-2 of the 2026-06-10 cost+perf quick-wins plan. Frontend critical-path only; each item independently revertable.

## Changes
- **F1** Defer `pusher-js` + `laravel-echo` off the eager bundle — `authStore` login/logout now `import('@/lib/echo')` dynamically. Verified: `pusher` 102→0 in `dist/assets/index-*.js`.
- **F2** Lazy-load recharts — `DualAxisChart`, `ProductsSummaryCard` (dashboard), `OrdersAnalytics` (orders) wrapped in `lazy` + `Suspense` with skeleton fallbacks. Metric cards paint before the 109 KB-gzip `vendor-charts` chunk.
- **F3** Self-host fonts via `@fontsource` (Inter + Noto Sans Thai, weights 400/500/600/700), removing googleapis/gstatic from the LCP path.

## Verification
- `npx vitest run src/stores/authStore.test.ts` → 14 passed (incl. 5 new lazy-echo tests).
- `npm run build` green; build-greps confirm pusher off eager chunk, charts via `__vitePreload`, 0 google-font refs in built HTML.
- Lighthouse LCP before/after (`/login`, `/dashboard`, `/orders`):
  - /login: <before> → <after>
  - /dashboard: <before> → <after>
  - /orders: <before> → <after>

## Rollout
Measure LCP, merge, then 24h Sentry watch (verify chat realtime connects post-login) before the next same-path merge — per spec §3/§4.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```
Expected: `gh` prints the new PR URL. Fill in the `<before>/<after>` LCP numbers captured in F1 Step 1 and F3 Step 5 before submitting.

---

## PR-3 — AI prompt efficiency

Branch: `perf/ai-prompt-efficiency` off main

> NOTE (spec drift, verified 2026-06-10 against live code):
> - The spec's `isSimpleMessage()` does **not** exist as a method. It is an inline expression at `backend/app/Services/RAGService.php:116` (`mb_strlen($userMessage) <= 30 && preg_match(self::SIMPLE_MESSAGE_PATTERN, ...)`) using the private const at `RAGService.php:25`. A2 extracts it into a real `isSimpleMessage()` method.
> - Spec A2 cites `StreamingResponseOrchestrator.php:188-210`, but that is the chat-**emulator** SSE preview path (`StreamController`). The **production LINE-bot path** is `RAGService::generateResponse` (called from `ProcessAggregatedMessages.php:343` → `AIService.php:40`), whose unconditional `analyzeIntent` is at `RAGService.php:99`. A2 gates the **production** call (highest value, and the only one cleanly unit-testable via the injected `IntentAnalysisService`). The orchestrator's gating is emulator-only and deferred.
> - Spec A1 lists three anchors. The gemini `cached_tokens %` metric is driven by **production** traffic, i.e. `RAGService::buildEnhancedPrompt` (`RAGService.php:349-399`). The orchestrator path assembles via the shared `injectStockStatus` (`StockInjectionService.php:95-113`, also called by `FlowController.php:569`), so reordering it would change shared semantics for multiple callers; that is intentionally **out of scope** for this surgical cost win.
> - A1 and A2 are **metadata/ordering only** — neither changes the chat-model call, so the user-visible answer text must be unchanged. Both tasks call out a manual A/B sanity check on a real conversation.

### Task PR3-T0: Characterize current greeting → decision-model behaviour

**Files:**
- Modify (test): `backend/tests/Unit/Services/RAGServiceTest.php` (add a helper + one characterization test before the class-closing `}` at line 354)

This pins the **current** behaviour (a greeting invokes the decision model) so the A2 change is a reviewable one-line test diff. Prompt-ordering is already characterized by the existing tests `test_memory_notes_injected_before_kb_context`, `test_build_enhanced_prompt_with_all_components`, `test_memory_prepended_before_base_prompt`, `test_memory_before_base_prompt_before_kb` (RAGServiceTest.php:109-245), so no extra ordering characterization is added here — A1 updates those.

- [ ] **Step 1: Add a service-builder helper + characterization test.** Insert the following two methods into `RAGServiceTest` immediately before the final closing `}` (after `test_no_skip_for_fresh_conversation_long_message`, line 353). All referenced classes are already imported (RAGServiceTest.php:8-13).

```php
    /**
     * Build a RAGService with caller-controlled OpenRouter + IntentAnalysis mocks,
     * a real StockInjectionService, and stub mocks for the rest. Used by the
     * generateResponse() characterization/behaviour tests.
     */
    private function makeServiceWith(
        OpenRouterService $openRouter,
        IntentAnalysisService $intentAnalysis,
        ?FlowCacheService $flowCache = null,
    ): RAGService {
        return new RAGService(
            $this->createMock(SemanticSearchService::class),
            $this->createMock(HybridSearchService::class),
            $openRouter,
            $intentAnalysis,
            $flowCache ?? $this->createMock(FlowCacheService::class),
            null, // queryEnhancement
            null, // semanticCache
            null, // CRAGService
            app(\App\Services\StockInjectionService::class),
        );
    }

    public function test_greeting_currently_triggers_intent_analysis(): void
    {
        // CHARACTERIZATION (pre-PR3-A2): a trivial greeting currently makes a
        // decision-model round-trip. PR3-A2 flips this to never(); keeping the
        // baseline green here makes that behaviour change explicit in review.
        $intentAnalysis = $this->createMock(IntentAnalysisService::class);
        $intentAnalysis->expects($this->once())
            ->method('analyzeIntent')
            ->willReturn([
                'intent' => 'chat',
                'confidence' => 0.95,
                'model_used' => 'decider',
                'method' => 'llm_decision',
            ]);

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->method('generateBotResponse')->willReturn([
            'content' => 'สวัสดีค่ะ ยินดีให้บริการค่ะ',
            'model' => 'chat-model',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]);

        $service = $this->makeServiceWith($openRouter, $intentAnalysis);
        $bot = Bot::factory()->create(['user_id' => $this->user->id, 'decision_model' => 'some/decider']);

        $service->generateResponse($bot, 'สวัสดี');
    }
```

- [ ] **Step 2: Run the test — expect PASS on current code.**

```
cd backend && php artisan test --filter 'test_greeting_currently_triggers_intent_analysis'
```

Expected:
```
   PASS  Tests\Unit\Services\RAGServiceTest
  ✓ greeting currently triggers intent analysis

  Tests:    1 passed
```

- [ ] **Step 3: Commit.**

```
git add backend/tests/Unit/Services/RAGServiceTest.php
git commit -m "test(rag): characterize greeting triggers decision-model baseline"
```

### Task PR3-A1: Reorder prompt so the static persona is the cacheable prefix

**Files:**
- Modify: `backend/app/Services/RAGService.php:355-380` (inside `buildEnhancedPrompt`)
- Modify (test): `backend/tests/Unit/Services/RAGServiceTest.php` (flip 3 existing ordering tests + add 2 new tests + 2 imports)

Goal: `buildEnhancedPrompt` currently **prepends** memory and the stock header ahead of `$basePrompt` (the large static persona), defeating prefix caching. Move the static persona to the front; keep all dynamic content (memory, stock header, KB, bubbles, end-of-prompt stock reminder) present but **after** the persona.

- [ ] **Step 1: Add imports to the test file.** In `RAGServiceTest.php`, after `use App\Models\Conversation;` (line 6) add:

```php
use App\Models\ProductStock;
```

and after `use App\Services\SemanticSearchService;` (line 13) add:

```php
use Illuminate\Support\Facades\Cache;
```

- [ ] **Step 2: Add two new failing ordering tests.** Insert into `RAGServiceTest` after `test_memory_before_base_prompt_before_kb` (currently ends at line 245):

```php
    public function test_static_persona_precedes_dynamic_memory(): void
    {
        // After the reorder the static persona must lead so it forms a stable,
        // cacheable prefix; dynamic memory comes after it.
        $persona = 'You are Captain Ad, a friendly sales assistant. [PERSONA-MARKER]';
        $memoryNotes = ['ลูกค้าชื่อสมชาย', 'ชอบกาแฟเย็น'];

        $result = $this->callBuildEnhancedPrompt($persona, '', null, $memoryNotes);

        $personaPos = strpos($result, '[PERSONA-MARKER]');
        $memoryPos = strpos($result, '## Memory:');

        $this->assertNotFalse($personaPos);
        $this->assertNotFalse($memoryPos);
        $this->assertLessThan($memoryPos, $personaPos, 'Static persona must lead (cacheable prefix)');
    }

    public function test_static_persona_precedes_stock_and_stock_still_present(): void
    {
        Cache::forget(ProductStock::STOCK_CACHE_KEY);
        ProductStock::factory()->outOfStock()->create([
            'name' => 'Nolimit Level Up+ BM',
            'slug' => 'bm',
            'aliases' => ['BM'],
        ]);

        $persona = 'You are Captain Ad. [PERSONA-MARKER]';

        $result = $this->callBuildEnhancedPrompt($persona, '', null, []);

        // Caution (spec A1): stock info must NOT be dropped by the reorder.
        $this->assertStringContainsString('⛔⛔⛔ STOCK STATUS', $result);
        $this->assertStringContainsString('⛔ STOCK REMINDER', $result);

        // Persona leads; stock header comes after it.
        $personaPos = strpos($result, '[PERSONA-MARKER]');
        $stockPos = strpos($result, '⛔⛔⛔ STOCK STATUS');
        $this->assertLessThan($stockPos, $personaPos, 'Persona must precede stock header for prefix caching');
    }
```

- [ ] **Step 3: Flip the 3 existing tests that pin the OLD (memory-before-base) ordering.**

In `test_build_enhanced_prompt_with_all_components`, replace the trailing block (RAGServiceTest.php:211-214):

```php
        // Memory before base prompt
        $memoryPos = strpos($result, '## Memory:');
        $basePos = strpos($result, 'You are a helpful assistant.');
        $this->assertLessThan($basePos, $memoryPos, 'Memory should be prepended before base prompt');
```

with:

```php
        // Static persona leads; memory injected after (cacheable-prefix ordering)
        $memoryPos = strpos($result, '## Memory:');
        $basePos = strpos($result, 'You are a helpful assistant.');
        $this->assertLessThan($memoryPos, $basePos, 'Base persona should lead, memory injected after');
```

Replace the whole `test_memory_prepended_before_base_prompt` method (RAGServiceTest.php:217-229):

```php
    public function test_memory_prepended_before_base_prompt(): void
    {
        $basePrompt = 'You are Captain Ad sales bot.';
        $memoryNotes = ['ลูกค้า VIP เคยซื้อ Nolimit Level Up+ 2 ครั้ง'];

        $result = $this->callBuildEnhancedPrompt($basePrompt, '', $this->bot, $memoryNotes);

        // Memory appears before base prompt
        $memoryPos = strpos($result, '## Memory:');
        $basePos = strpos($result, 'You are Captain Ad sales bot.');
        $this->assertLessThan($basePos, $memoryPos, 'Memory must come before base prompt');
        $this->assertStringContainsString('- ลูกค้า VIP เคยซื้อ Nolimit Level Up+ 2 ครั้ง', $result);
    }
```

with:

```php
    public function test_base_persona_precedes_memory(): void
    {
        $basePrompt = 'You are Captain Ad sales bot.';
        $memoryNotes = ['ลูกค้า VIP เคยซื้อ Nolimit Level Up+ 2 ครั้ง'];

        $result = $this->callBuildEnhancedPrompt($basePrompt, '', $this->bot, $memoryNotes);

        // Static persona leads; memory injected after (cacheable prefix)
        $memoryPos = strpos($result, '## Memory:');
        $basePos = strpos($result, 'You are Captain Ad sales bot.');
        $this->assertLessThan($memoryPos, $basePos, 'Base persona must come before memory');
        $this->assertStringContainsString('- ลูกค้า VIP เคยซื้อ Nolimit Level Up+ 2 ครั้ง', $result);
    }
```

Replace the whole `test_memory_before_base_prompt_before_kb` method (RAGServiceTest.php:231-245):

```php
    public function test_memory_before_base_prompt_before_kb(): void
    {
        $basePrompt = 'System prompt here.';
        $kbContext = '## KB Context:';
        $memoryNotes = ['ชอบสีดำ', 'ที่อยู่: สุขุมวิท 55'];

        $result = $this->callBuildEnhancedPrompt($basePrompt, $kbContext, null, $memoryNotes);

        // Order: Memory → base prompt → KB
        $memoryPos = strpos($result, '## Memory:');
        $basePos = strpos($result, 'System prompt here.');
        $kbPos = strpos($result, '## KB Context:');
        $this->assertLessThan($basePos, $memoryPos);
        $this->assertLessThan($kbPos, $basePos);
    }
```

with:

```php
    public function test_base_prompt_before_memory_before_kb(): void
    {
        $basePrompt = 'System prompt here.';
        $kbContext = '## KB Context:';
        $memoryNotes = ['ชอบสีดำ', 'ที่อยู่: สุขุมวิท 55'];

        $result = $this->callBuildEnhancedPrompt($basePrompt, $kbContext, null, $memoryNotes);

        // Order: base persona → memory → KB (persona leads as cacheable prefix)
        $memoryPos = strpos($result, '## Memory:');
        $basePos = strpos($result, 'System prompt here.');
        $kbPos = strpos($result, '## KB Context:');
        $this->assertLessThan($memoryPos, $basePos);
        $this->assertLessThan($kbPos, $memoryPos);
    }
```

- [ ] **Step 4: Run the updated tests — expect FAIL on current code.**

```
cd backend && php artisan test --filter RAGServiceTest
```

Expected (the reorder-sensitive tests fail because the persona is still last):
```
   FAIL  Tests\Unit\Services\RAGServiceTest
  ⨯ static persona precedes dynamic memory
  ⨯ static persona precedes stock and stock still present
  ⨯ base persona precedes memory
  ⨯ base prompt before memory before kb
  ...
  Tests:    4 failed, N passed
```

- [ ] **Step 5: Reorder `buildEnhancedPrompt` so the static persona leads.** In `backend/app/Services/RAGService.php`, replace the current prepend block (RAGService.php:355-380):

```php
        $prompt = '';

        if (! empty($memoryNotes)) {
            $prompt .= "## Memory:\n";
            foreach ($memoryNotes as $content) {
                $prompt .= "- {$content}\n";
            }
            $prompt .= "---\n\n";
        }

        // Always inject stock — conditional injection caused sales of out-of-stock products
        $stocks = $this->stockInjectionService->getStockStatus();
        $hasOutOfStock = $stocks->where('in_stock', false)->isNotEmpty();

        if ($hasOutOfStock) {
            $stockInjection = $this->stockInjectionService->buildStockInjection($stocks);
            if (! empty($stockInjection)) {
                $prompt .= $stockInjection."\n---\n\n";
            }
        }

        $prompt .= $basePrompt;

        if (! empty($kbContext)) {
            $prompt .= "\n\n".$kbContext;
        }
```

with:

```php
        // Static persona leads so it forms a stable, cacheable prefix for
        // OpenRouter/gemini prefix caching. Dynamic memory/stock/KB come AFTER.
        $prompt = $basePrompt;

        if (! empty($memoryNotes)) {
            $prompt .= "\n\n## Memory:\n";
            foreach ($memoryNotes as $content) {
                $prompt .= "- {$content}\n";
            }
            $prompt .= '---';
        }

        // Always inject stock — conditional injection caused sales of out-of-stock products
        $stocks = $this->stockInjectionService->getStockStatus();
        $hasOutOfStock = $stocks->where('in_stock', false)->isNotEmpty();

        if ($hasOutOfStock) {
            $stockInjection = $this->stockInjectionService->buildStockInjection($stocks);
            if (! empty($stockInjection)) {
                $prompt .= "\n\n".$stockInjection;
            }
        }

        if (! empty($kbContext)) {
            $prompt .= "\n\n".$kbContext;
        }
```

(The multiple-bubbles block at RAGService.php:382-388 and the end-of-prompt stock reminder at RAGService.php:390-396 are unchanged — the reminder stays last, closest to the user message.)

- [ ] **Step 6: Run the full RAGService suite — expect PASS.**

```
cd backend && php artisan test --filter RAGServiceTest
```

Expected:
```
   PASS  Tests\Unit\Services\RAGServiceTest
  ✓ static persona precedes dynamic memory
  ✓ static persona precedes stock and stock still present
  ✓ base persona precedes memory
  ✓ base prompt before memory before kb
  ...
  Tests:    N passed
```

- [ ] **Step 7: Manual A/B sanity (behaviour-sensitive — do before commit).** In the chat emulator (or a staging bot), send one greeting and one product question with an out-of-stock product configured; confirm the reply text and the out-of-stock refusal are materially unchanged vs. main. Record before/after in the PR description. Ops follow-up (not a code gate): watch gemini `cached_tokens %` on the Neon `messages` table trend up over 2-3 days after deploy.

- [ ] **Step 8: Commit.**

```
git add backend/app/Services/RAGService.php backend/tests/Unit/Services/RAGServiceTest.php
git commit -m "perf(rag): lead system prompt with static persona for prefix caching"
```

### Task PR3-A2: Skip the decision-model round-trip for greeting/trivial turns

**Files:**
- Modify: `backend/app/Services/RAGService.php:98-103` (gate the intent call), `:116` (remove now-duplicate inline check), add a new `isSimpleMessage()` method after `generateResponse` (after line 247)
- Modify (test): `backend/tests/Unit/Services/RAGServiceTest.php` (convert the T0 characterization test to the new behaviour + add a substantive-message test)

- [ ] **Step 1: Convert the T0 characterization test to the new (skip) behaviour and add the counter-case.** Replace the whole `test_greeting_currently_triggers_intent_analysis` method (added in PR3-T0) with:

```php
    public function test_greeting_skips_decision_model(): void
    {
        // A trivial greeting must NOT make a decision-model round-trip.
        $intentAnalysis = $this->createMock(IntentAnalysisService::class);
        $intentAnalysis->expects($this->never())->method('analyzeIntent');

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->method('generateBotResponse')->willReturn([
            'content' => 'สวัสดีค่ะ ยินดีให้บริการค่ะ',
            'model' => 'chat-model',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]);

        $service = $this->makeServiceWith($openRouter, $intentAnalysis);
        $bot = Bot::factory()->create(['user_id' => $this->user->id, 'decision_model' => 'some/decider']);

        $result = $service->generateResponse($bot, 'สวัสดี');

        $this->assertSame('chat', $result['intent']['intent']);
        $this->assertTrue($result['intent']['skipped']);
        $this->assertSame('simple_message_skip', $result['intent']['method']);
    }

    public function test_substantive_message_invokes_decision_model(): void
    {
        // A real product question still makes the decision-model round-trip.
        $intentAnalysis = $this->createMock(IntentAnalysisService::class);
        $intentAnalysis->expects($this->once())
            ->method('analyzeIntent')
            ->willReturn([
                'intent' => 'chat',
                'confidence' => 0.9,
                'model_used' => 'decider',
                'method' => 'llm_decision',
            ]);

        $openRouter = $this->createMock(OpenRouterService::class);
        $openRouter->method('generateBotResponse')->willReturn([
            'content' => 'ได้ค่ะ เดี๋ยวแจ้งราคาให้นะคะ',
            'model' => 'chat-model',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]);

        $service = $this->makeServiceWith($openRouter, $intentAnalysis);
        $bot = Bot::factory()->create(['user_id' => $this->user->id, 'decision_model' => 'some/decider']);

        $result = $service->generateResponse($bot, 'ขอราคาสินค้า Nolimit Level Up ทุกแพ็กเกจมีอะไรบ้างครับ');

        $this->assertSame('llm_decision', $result['intent']['method']);
    }
```

- [ ] **Step 2: Run — expect FAIL on current code.** Current `generateResponse` calls `analyzeIntent` unconditionally, so the greeting test's `never()` is violated.

```
cd backend && php artisan test --filter 'test_greeting_skips_decision_model'
```

Expected:
```
   FAIL  Tests\Unit\Services\RAGServiceTest
  ⨯ greeting skips decision model
  ...
  IntentAnalysisService::analyzeIntent(...) was not expected to be called more than 0 times
```

- [ ] **Step 3: Extract a real `isSimpleMessage()` method.** In `backend/app/Services/RAGService.php`, insert immediately after the closing `}` of `generateResponse` (after line 247, before the `/** Check if the bot should use its Knowledge Base. */` comment at line 249):

```php
    /**
     * Whether a message is a trivial greeting/acknowledgement that needs neither
     * a decision-model round-trip nor a KB lookup (always resolves to 'chat').
     */
    public function isSimpleMessage(string $userMessage): bool
    {
        return mb_strlen($userMessage) <= 30
            && (bool) preg_match(self::SIMPLE_MESSAGE_PATTERN, trim($userMessage));
    }
```

- [ ] **Step 4: Gate the intent call behind `isSimpleMessage()`.** Replace the current unconditional call (RAGService.php:98-103):

```php
        // Step 1: Analyze intent using Decision Model
        $intent = $this->intentAnalysis->analyzeIntent($bot, $userMessage, [
            'validIntents' => ['chat', 'knowledge', 'flow'],
            'includeExamples' => true,
            'apiKey' => $apiKey,
        ]);
```

with:

```php
        // Step 1: Analyze intent using Decision Model.
        // Skip the decision-model round-trip for trivial greetings/acknowledgements —
        // they never need the KB and always resolve to 'chat'. Saves one ~300-800ms LLM hop.
        $isSimpleMessage = $this->isSimpleMessage($userMessage);

        if ($isSimpleMessage) {
            $intent = [
                'intent' => 'chat',
                'confidence' => 1.0,
                'model_used' => null,
                'method' => 'simple_message_skip',
                'skipped' => true,
            ];
        } else {
            $intent = $this->intentAnalysis->analyzeIntent($bot, $userMessage, [
                'validIntents' => ['chat', 'knowledge', 'flow'],
                'includeExamples' => true,
                'apiKey' => $apiKey,
            ]);
        }
```

- [ ] **Step 5: Remove the now-duplicate inline check.** `$isSimpleMessage` is computed above and reused at the `$shouldUseKB` guard (RAGService.php:120). Replace (RAGService.php:116-118):

```php
        $isSimpleMessage = mb_strlen($userMessage) <= 30 && preg_match(self::SIMPLE_MESSAGE_PATTERN, trim($userMessage));

        // Step 4: Get KB context if intent is 'knowledge' and KB enabled
```

with:

```php
        // Step 4: Get KB context if intent is 'knowledge' and KB enabled
```

(The `$shouldUseKB` guard at line 120 is unchanged. Behaviour preserved: for a greeting `! $isSimpleMessage` is already `false`, so KB was — and stays — skipped; only the LLM decision hop is removed.)

- [ ] **Step 6: Run the RAGService suite + the IntentAnalysis suite — expect PASS.**

```
cd backend && php artisan test --filter RAGServiceTest && php artisan test --filter IntentAnalysisServiceTest
```

Expected:
```
   PASS  Tests\Unit\Services\RAGServiceTest
  ✓ greeting skips decision model
  ✓ substantive message invokes decision model
  ...
   PASS  Tests\Unit\Services\IntentAnalysisServiceTest
  ...
  Tests:    N passed
```

- [ ] **Step 7: Manual A/B sanity (behaviour-sensitive).** Confirm a greeting still gets a normal greeting reply (the chat-model call is untouched; only the decision hop and its metadata change: `method` becomes `simple_message_skip`, `models_used.decision` becomes `null`). Verify a substantive product question still routes through the decision model and KB exactly as before. Note the result in the PR. Ops verification (spec A2 success criterion): greeting messages no longer emit a decision-model call in logs/trace.

- [ ] **Step 8: Commit.**

```
git add backend/app/Services/RAGService.php backend/tests/Unit/Services/RAGServiceTest.php
git commit -m "perf(rag): skip decision-model round-trip for trivial greetings"
```

### Task PR3-LAST: Push and open the PR

**Files:** none (git/GitHub only)

- [ ] **Step 1: Run the full backend test suite once more as a final gate.**

```
cd backend && php artisan test
```

Expected: `Tests: N passed` (no failures/errors).

- [ ] **Step 2: Push the branch.**

```
git push -u origin perf/ai-prompt-efficiency
```

Expected: `* [new branch] perf/ai-prompt-efficiency -> perf/ai-prompt-efficiency`.

- [ ] **Step 3: Open the PR.**

```
gh pr create --base main --head perf/ai-prompt-efficiency \
  --title "perf(rag): AI prompt efficiency — cacheable persona prefix + skip greeting decision hop" \
  --body "$(cat <<'EOF'
## Summary
PR-3 of the cost+perf quick-wins (spec: docs/superpowers/specs/2026-06-10-cost-perf-quick-wins-design.md).

- **A1** — Reorder `RAGService::buildEnhancedPrompt` so the large static persona leads (cacheable prefix); dynamic memory/stock/KB now follow it. Stock header + end-of-prompt reminder both still present.
- **A2** — Gate the production `analyzeIntent` round-trip behind a new `RAGService::isSimpleMessage()`; trivial greetings/acknowledgements skip the decision-model hop (they never use the KB and always resolve to `chat`).

Both are ordering/metadata-only — the chat-model call is untouched, so answer text is unchanged.

## Scope notes (drift from spec)
- `isSimpleMessage` did not exist as a method; extracted from the inline check at RAGService.php (was line 116).
- A2 gates the **production** path (RAGService::generateResponse), not the emulator orchestrator the spec line-referenced.
- The chat-emulator path (StreamingResponseOrchestrator → injectStockStatus) is left unchanged on purpose; it does not drive the production gemini cache-% metric and the method is shared with FlowController.

## Tests
- T0 characterization → A2 behaviour flip (greeting once→never), plus a substantive-message counter-case.
- A1 flips 3 existing ordering tests + adds persona-before-memory and persona-before-stock(+still-present) tests.
- `php artisan test` green.

## Manual A/B (behaviour-sensitive)
- Greeting reply unchanged; out-of-stock refusal unchanged. [attach before/after]

## Verification to watch post-deploy
- A1: gemini `cached_tokens %` (Neon `messages`, 2-3 day window) trends up.
- A2: greeting messages no longer emit a decision-model call in logs/trace.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: prints the new PR URL (`https://github.com/JaoChai/bot-fb/pull/<n>`).

Relevant files (absolute):
- `/Users/jaochai/Code/bot-fb/backend/app/Services/RAGService.php`
- `/Users/jaochai/Code/bot-fb/backend/tests/Unit/Services/RAGServiceTest.php`
- `/Users/jaochai/Code/bot-fb/backend/tests/Unit/Services/IntentAnalysisServiceTest.php`
- `/Users/jaochai/Code/bot-fb/backend/app/Services/Streaming/StreamingResponseOrchestrator.php` (emulator path, referenced only)
- `/Users/jaochai/Code/bot-fb/backend/app/Services/StockInjectionService.php` (shared `injectStockStatus`, referenced only)

---

## PR-4 — Backend surgical

Branch: `perf/backend-surgical` off main

> NOTE: All anchors re-verified against live code on read. Real current line numbers: `ProcessAggregatedMessages::shouldGenerate()` is at **lines 211–244** (the `DB::transaction` block is **215–241**), not the spec's "~215–241" for the whole method. `VipController::index` loop is at **lines 31–62** (Order aggregate query at **45–48**), not "~33–48". The two indexes are still live (not dropped by the 2026_05_15 cleanup migration — verified). Source-of-truth create migrations: `idx_conversations_webhook_lookup` → `2026_02_16_100000_add_webhook_lookup_index_to_conversations.php:25-29`; `conversations_last_message_id_index` → `2026_04_01_100000_add_last_message_id_to_conversations_table.php:21` (`$table->index('last_message_id')`, Laravel default name `conversations_last_message_id_index`, a plain B-tree on `(last_message_id)`).

### Task PR4-B1: Remove DB::transaction around read-only refresh() in shouldGenerate()

This is a pure refactor (behavior unchanged), so per CLAUDE.md "Refactor X → ensure tests pass before and after" we add a **characterization test** that passes BOTH before and after the edit, then make the surgical change.

**Files:**
- Create (Test): `backend/tests/Unit/Jobs/ProcessAggregatedMessagesShouldGenerateTest.php`
- Modify: `backend/app/Jobs/ProcessAggregatedMessages.php:211-244`

- [ ] **Step 1: Write the characterization test.** Mirrors the reflection helper from `backend/tests/Unit/Services/FlowPluginServiceTest.php:25-32` and factory/RefreshDatabase conventions from `backend/tests/Unit/Services/LeadRecoveryServiceEagerLoadTest.php`. Create `backend/tests/Unit/Jobs/ProcessAggregatedMessagesShouldGenerateTest.php`:

  ```php
  <?php

  namespace Tests\Unit\Jobs;

  use App\Jobs\ProcessAggregatedMessages;
  use App\Models\Bot;
  use App\Models\Conversation;
  use Illuminate\Foundation\Testing\RefreshDatabase;
  use ReflectionClass;
  use Tests\TestCase;

  class ProcessAggregatedMessagesShouldGenerateTest extends TestCase
  {
      use RefreshDatabase;

      private function callShouldGenerate(Bot $bot, Conversation $conversation): bool
      {
          $job = new ProcessAggregatedMessages($bot, $conversation, 'group-1', 'U_test_user');
          $m = (new ReflectionClass($job))->getMethod('shouldGenerate');
          $m->setAccessible(true);

          return $m->invoke($job);
      }

      public function test_returns_true_when_bot_active_and_not_in_handover(): void
      {
          $bot = Bot::factory()->create(['status' => 'active']);
          $conv = Conversation::factory()->create([
              'bot_id' => $bot->id,
              'is_handover' => false,
          ]);

          $this->assertTrue($this->callShouldGenerate($bot, $conv));
      }

      public function test_returns_false_when_bot_inactive(): void
      {
          $bot = Bot::factory()->create(['status' => 'inactive']);
          $conv = Conversation::factory()->create([
              'bot_id' => $bot->id,
              'is_handover' => false,
          ]);

          $this->assertFalse($this->callShouldGenerate($bot, $conv));
      }

      public function test_returns_false_when_conversation_in_handover(): void
      {
          $bot = Bot::factory()->create(['status' => 'active']);
          $conv = Conversation::factory()->create([
              'bot_id' => $bot->id,
              'is_handover' => true,
          ]);

          $this->assertFalse($this->callShouldGenerate($bot, $conv));
      }
  }
  ```

- [ ] **Step 2: Run the test against the UNCHANGED code — must already pass (it characterizes current behavior).**

  ```bash
  cd backend && php artisan test --filter=ProcessAggregatedMessagesShouldGenerateTest
  ```

  Expected: `Tests:  3 passed` (3 green). If any fail here, the characterization is wrong — fix the test before touching the job.

- [ ] **Step 3: Remove the DB::transaction wrapper.** In `backend/app/Jobs/ProcessAggregatedMessages.php`, replace the current body of `shouldGenerate()` (lines 213–243).

  Current code (lines 211–244):
  ```php
      private function shouldGenerate(): bool
      {
          $shouldGenerate = false;

          DB::transaction(function () use (&$shouldGenerate) {
              // Refresh conversation and bot to get latest state
              $this->conversation->refresh();
              $this->bot->refresh();

              // Check if bot was deactivated while waiting
              if ($this->bot->status !== 'active') {
                  Log::debug('[Aggregation] Early exit: bot inactive', [
                      'bot_id' => $this->bot->id,
                      'status' => $this->bot->status,
                      'conversation_id' => $this->conversation->id,
                  ]);

                  return;
              }

              // Check if handover mode was enabled while waiting
              if ($this->conversation->is_handover) {
                  Log::debug('[Aggregation] Early exit: handover mode enabled', [
                      'conversation_id' => $this->conversation->id,
                  ]);

                  return;
              }

              $shouldGenerate = true;
          });

          return $shouldGenerate;
      }
  ```

  Replacement:
  ```php
      private function shouldGenerate(): bool
      {
          // Read-only refresh of latest bot/conversation state — no writes or locks,
          // so no transaction needed (dropping it avoids per-job BEGIN/COMMIT round-trips).
          $this->conversation->refresh();
          $this->bot->refresh();

          // Check if bot was deactivated while waiting
          if ($this->bot->status !== 'active') {
              Log::debug('[Aggregation] Early exit: bot inactive', [
                  'bot_id' => $this->bot->id,
                  'status' => $this->bot->status,
                  'conversation_id' => $this->conversation->id,
              ]);

              return false;
          }

          // Check if handover mode was enabled while waiting
          if ($this->conversation->is_handover) {
              Log::debug('[Aggregation] Early exit: handover mode enabled', [
                  'conversation_id' => $this->conversation->id,
              ]);

              return false;
          }

          return true;
      }
  ```

  > NOTE: Do NOT remove the `use Illuminate\Support\Facades\DB;` import (line 21) — `DB::raw(...)` is still used in `updateStats()` at lines 434-444. Leave it.

- [ ] **Step 4: Re-run the same test — must still pass (refactor preserved behavior).**

  ```bash
  cd backend && php artisan test --filter=ProcessAggregatedMessagesShouldGenerateTest
  ```

  Expected: `Tests:  3 passed`.

- [ ] **Step 5: Commit.**

  ```bash
  cd backend && git add app/Jobs/ProcessAggregatedMessages.php tests/Unit/Jobs/ProcessAggregatedMessagesShouldGenerateTest.php && git commit -m "perf(jobs): drop redundant DB::transaction around read-only refresh in shouldGenerate"
  ```

  Expected: one commit created with 2 files changed.

### Task PR4-B2: Collapse the N+1 Order aggregate in VipController::index

True TDD: the new test asserts exactly **one** `orders` query; current code issues one per VIP conversation (2 here) → test fails first, then the fix makes it pass. Totals assertions pass before and after.

**Files:**
- Modify (Test): `backend/tests/Feature/VipControllerTest.php` (append one method; mirrors existing setup at lines 18-51)
- Modify: `backend/app/Http/Controllers/Api/VipController.php:31-62`

- [ ] **Step 1: Add the failing feature test.** Append this method inside `VipControllerTest` (after `test_index_returns_customers_with_vip_auto_notes`, before `test_index_rejects_unauthorized_users`). It uses `DB::listen` query-counting like `backend/tests/Unit/Services/LeadRecoveryServiceEagerLoadTest.php:76-101`. Add `use Illuminate\Support\Facades\DB;` to the imports if not present (current imports end at line 12 — it is NOT present, so add it):

  ```php
  public function test_index_collapses_order_aggregate_into_single_query(): void
  {
      $user = User::factory()->create(['role' => 'owner']);
      Sanctum::actingAs($user);

      $bot = Bot::factory()->create(['user_id' => $user->id]);

      $vipNote = fn (string $id) => [[
          'id' => $id,
          'content' => 'VIP',
          'type' => 'memory',
          'source' => 'vip_auto',
          'created_by' => null,
          'created_at' => now()->toISOString(),
          'updated_at' => now()->toISOString(),
      ]];

      $alice = CustomerProfile::factory()->create(['display_name' => 'Alice']);
      $convA = Conversation::factory()->create([
          'bot_id' => $bot->id,
          'customer_profile_id' => $alice->id,
          'memory_notes' => $vipNote('00000000-0000-0000-0000-0000000000a1'),
      ]);
      Order::factory()->count(2)->create([
          'bot_id' => $bot->id,
          'conversation_id' => $convA->id,
          'customer_profile_id' => $alice->id,
          'status' => 'completed',
          'total_amount' => 500,
      ]);

      $bob = CustomerProfile::factory()->create(['display_name' => 'Bob']);
      $convB = Conversation::factory()->create([
          'bot_id' => $bot->id,
          'customer_profile_id' => $bob->id,
          'memory_notes' => $vipNote('00000000-0000-0000-0000-0000000000b1'),
      ]);
      Order::factory()->count(3)->create([
          'bot_id' => $bot->id,
          'conversation_id' => $convB->id,
          'customer_profile_id' => $bob->id,
          'status' => 'completed',
          'total_amount' => 1000,
      ]);

      $orderQueries = 0;
      DB::listen(function ($query) use (&$orderQueries) {
          if (str_contains($query->sql, 'from "orders"')) {
              $orderQueries++;
          }
      });

      $response = $this->getJson("/api/bots/{$bot->id}/vip/customers");

      DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

      $response->assertOk();
      // Correct per-customer totals must survive the refactor.
      $response->assertJsonFragment(['display_name' => 'Alice', 'order_count' => 2, 'total_amount' => 1000.0]);
      $response->assertJsonFragment(['display_name' => 'Bob', 'order_count' => 3, 'total_amount' => 3000.0]);

      // The loop must collapse to ONE grouped Order query, not one per VIP conversation.
      $this->assertSame(1, $orderQueries, "Expected a single grouped orders query, got $orderQueries");
  }
  ```

  And add the import near the top (current import block is lines 5-12):
  ```php
  use Illuminate\Support\Facades\DB;
  ```

- [ ] **Step 2: Run the test — expect the query-count assertion to FAIL on current code.**

  ```bash
  cd backend && php artisan test --filter=test_index_collapses_order_aggregate_into_single_query
  ```

  Expected: FAIL with `Expected a single grouped orders query, got 2` (current loop issues one Order aggregate per VIP conversation).

- [ ] **Step 3: Replace the loop with a single grouped lookup.** In `backend/app/Http/Controllers/Api/VipController.php`, replace lines 31–62.

  Current code (lines 31–62):
  ```php
          $rows = [];
          $seen = [];
          foreach ($conversations as $conv) {
              $note = $this->findVipNote($conv->memory_notes ?? []);
              if (! $note) {
                  continue;
              }
              $cpId = $conv->customer_profile_id;
              if (isset($seen[$cpId])) {
                  continue;
              }
              $seen[$cpId] = true;

              // Recompute stats (same query shape as VipDetectionService)
              $stats = Order::where('customer_profile_id', $cpId)
                  ->where('status', 'completed')
                  ->selectRaw('COUNT(*) as c, COALESCE(SUM(total_amount), 0) as total, MAX(created_at) as last')
                  ->first();

              $rows[] = [
                  'customer_profile_id' => $cpId,
                  'display_name' => $conv->customerProfile?->display_name,
                  'picture_url' => $conv->customerProfile?->picture_url,
                  'channel_type' => $conv->customerProfile?->channel_type,
                  'order_count' => (int) $stats->c,
                  'total_amount' => (float) $stats->total,
                  'last_order_at' => $stats->last,
                  'note_content' => $note['content'],
                  'note_source' => $note['source'] ?? 'vip_auto',
                  'bot_id' => $bot->id,
              ];
          }
  ```

  Replacement:
  ```php
          // First pass: collect unique VIP customers (dedup by customer_profile_id).
          $vipConvs = [];
          $seen = [];
          foreach ($conversations as $conv) {
              $note = $this->findVipNote($conv->memory_notes ?? []);
              if (! $note) {
                  continue;
              }
              $cpId = $conv->customer_profile_id;
              if (isset($seen[$cpId])) {
                  continue;
              }
              $seen[$cpId] = true;
              $vipConvs[] = ['conv' => $conv, 'note' => $note];
          }

          // Single grouped aggregate keyed by customer_profile_id — collapses the per-conversation N+1.
          // (Same filter/aggregate shape as VipDetectionService.)
          $statsByCustomer = Order::whereIn('customer_profile_id', array_keys($seen))
              ->where('status', 'completed')
              ->groupBy('customer_profile_id')
              ->selectRaw('customer_profile_id, COUNT(*) as c, COALESCE(SUM(total_amount), 0) as total, MAX(created_at) as last')
              ->get()
              ->keyBy('customer_profile_id');

          $rows = [];
          foreach ($vipConvs as ['conv' => $conv, 'note' => $note]) {
              $cpId = $conv->customer_profile_id;
              $stats = $statsByCustomer->get($cpId);

              $rows[] = [
                  'customer_profile_id' => $cpId,
                  'display_name' => $conv->customerProfile?->display_name,
                  'picture_url' => $conv->customerProfile?->picture_url,
                  'channel_type' => $conv->customerProfile?->channel_type,
                  'order_count' => (int) ($stats->c ?? 0),
                  'total_amount' => (float) ($stats->total ?? 0),
                  'last_order_at' => $stats->last ?? null,
                  'note_content' => $note['content'],
                  'note_source' => $note['source'] ?? 'vip_auto',
                  'bot_id' => $bot->id,
              ];
          }
  ```

  > NOTE: `whereIn(..., array_keys($seen))` with an empty `$seen` produces a `0 = 1` guard in Laravel and returns an empty result set — the no-VIP path stays correct (one harmless query, or zero rows). The `Order` import (line 9) and `?->` null-coalescing keep customers with zero completed orders rendering `order_count: 0`.

- [ ] **Step 4: Re-run the test — now passes (totals correct AND single query).**

  ```bash
  cd backend && php artisan test --filter=test_index_collapses_order_aggregate_into_single_query
  ```

  Expected: `Tests:  1 passed`.

- [ ] **Step 5: Run the full VipController suite to confirm no regression.**

  ```bash
  cd backend && php artisan test --filter=VipControllerTest
  ```

  Expected: `Tests:  8 passed` (7 existing + 1 new).

- [ ] **Step 6: Commit.**

  ```bash
  cd backend && git add app/Http/Controllers/Api/VipController.php tests/Feature/VipControllerTest.php && git commit -m "perf(vip): collapse N+1 order aggregate in VipController::index into one grouped query"
  ```

  Expected: one commit, 2 files changed.

### Task PR4-B3: Drop two unused (idx_scan=0) indexes on conversations

Index/ops change — TDD does not fit; verification is (a) migration boots cleanly on the SQLite test DB (guarded no-op) and (b) on a Neon dev branch the two indexes disappear while others remain. Mirrors the `public $withinTransaction = false;` + `DROP INDEX CONCURRENTLY IF EXISTS` style of `backend/database/migrations/2026_02_16_100003_drop_unused_indexes_on_active_tables.php` and `2026_04_27_110043_optimize_messages_indexes_for_dashboard.php`.

**Files:**
- Create: `backend/database/migrations/2026_06_10_120000_drop_unused_conversations_indexes.php`

- [ ] **Step 1: Create the migration.** down() recreates each index with the EXACT definition from its source migration (webhook_lookup is a partial composite `WHERE deleted_at IS NULL`; last_message_id is a plain B-tree). Create `backend/database/migrations/2026_06_10_120000_drop_unused_conversations_indexes.php`:

  ```php
  <?php

  use Illuminate\Database\Migrations\Migration;
  use Illuminate\Support\Facades\DB;

  /**
   * Drop two unused (idx_scan = 0) indexes on the hot conversations table.
   *
   * - idx_conversations_webhook_lookup (152 KB) — partial composite added
   *   2026-02-16; query planner never chose it (live idx_scan=0, 2026-06-10).
   * - conversations_last_message_id_index (128 KB) — plain B-tree from the
   *   2026-04-01 last_message_id migration; the FK does not require it and it
   *   has 0 scans.
   *
   * Reduces write amplification on the most-updated table. The last_message_id
   * FOREIGN KEY constraint stays intact (Postgres does not need an index on it).
   *
   * NOT dropped: the messages composite indexes (open question — needs EXPLAIN
   * before any drop; explicitly out of scope for this round).
   */
  return new class extends Migration
  {
      /**
       * Disable transaction wrapping — DROP INDEX CONCURRENTLY cannot run inside a transaction.
       */
      public $withinTransaction = false;

      public function up(): void
      {
          if (DB::connection()->getDriverName() !== 'pgsql') {
              return;
          }

          DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_conversations_webhook_lookup');
          DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_last_message_id_index');
      }

      public function down(): void
      {
          if (DB::connection()->getDriverName() !== 'pgsql') {
              return;
          }

          // Recreate with the exact original definitions.
          DB::statement('
              CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_conversations_webhook_lookup
              ON conversations (bot_id, external_customer_id, channel_type, status)
              WHERE deleted_at IS NULL
          ');
          DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_last_message_id_index ON conversations (last_message_id)');
      }
  };
  ```

- [ ] **Step 2: Verify the migration boots cleanly on the SQLite test DB (guarded no-op).** RefreshDatabase runs `migrate:fresh`, so any fast feature test exercises every migration file including the new one; the `pgsql` guard makes it a no-op on SQLite but a syntax/load error would still surface here.

  ```bash
  cd backend && php artisan test --filter=HealthCheckTest
  ```

  Expected: `Tests:  ... passed` with no migration error (no `SQLSTATE`/parse error from the new file).

- [ ] **Step 3: Apply + verify on a Neon dev branch (safe-migration practice — never first on production).** Create a throwaway branch off the prod branch, point `DATABASE_URL` at it, run the migration, then confirm the two indexes are gone and the rest of `conversations`' indexes remain. Use the Neon MCP `create_branch` (project `solitary-math-34010034`) to get a branch connection string, then:

  ```bash
  cd backend && DB_CONNECTION=pgsql DATABASE_URL="<neon-dev-branch-connection-string>" php artisan migrate --force
  ```

  Expected: output includes
  ```
  INFO  Running migrations.
  2026_06_10_120000_drop_unused_conversations_indexes ... DONE
  ```

- [ ] **Step 4: Confirm the drop in pg_catalog on the dev branch.** Run via Neon MCP `run_sql` (or `psql "<branch-conn>" -c`):

  ```sql
  SELECT indexname FROM pg_indexes
  WHERE tablename = 'conversations'
    AND indexname IN ('idx_conversations_webhook_lookup', 'conversations_last_message_id_index');
  ```

  Expected: **0 rows** (both dropped). Then sanity-check the table still has its other indexes:

  ```sql
  SELECT indexname FROM pg_indexes WHERE tablename = 'conversations' ORDER BY indexname;
  ```

  Expected: the primary key and remaining indexes are listed; the two dropped names are absent. (Optional `down()` check: `php artisan migrate:rollback --step=1 --force` on the dev branch, then re-run the first query → both names reappear; then re-`migrate` to leave it dropped. Delete the Neon dev branch when done.)

- [ ] **Step 5: Commit.**

  ```bash
  cd backend && git add database/migrations/2026_06_10_120000_drop_unused_conversations_indexes.php && git commit -m "perf(db): drop two unused idx_scan=0 indexes on conversations"
  ```

  Expected: one commit, 1 file added.

### Task PR4-LAST: Push branch and open PR

**Files:** none (git/gh only)

- [ ] **Step 1: Run the affected backend tests one final time before pushing.**

  ```bash
  cd backend && php artisan test --filter=ProcessAggregatedMessagesShouldGenerateTest && php artisan test --filter=VipControllerTest
  ```

  Expected: both runs green (3 passed, then 8 passed).

- [ ] **Step 2: Push the branch.**

  ```bash
  git push -u origin perf/backend-surgical
  ```

  Expected: branch published, remote tracking set.

- [ ] **Step 3: Open the PR.**

  ```bash
  gh pr create --base main --head perf/backend-surgical --title "perf(backend): surgical quick wins — drop read-only txn, fix VIP N+1, drop unused conversations indexes" --body "$(cat <<'EOF'
## Summary
PR-4 (backend surgical) from the 2026-06-10 cost+perf quick-wins spec. Three independent low-risk wins:

- **B1** Remove the `DB::transaction` wrapping the two read-only `->refresh()` calls in `ProcessAggregatedMessages::shouldGenerate()` — no writes/locks, so it only added per-job BEGIN/COMMIT round-trips. Behavior unchanged (characterization test added).
- **B2** Fix the N+1 in `VipController::index`: one `Order` aggregate per conversation → a single `whereIn(...)->groupBy(...)` keyed lookup. Feature test asserts correct totals AND a single grouped `orders` query.
- **B3** Migration to `DROP INDEX CONCURRENTLY` two `idx_scan=0` indexes on the hot `conversations` table (`idx_conversations_webhook_lookup`, `conversations_last_message_id_index`), reducing write amplification. `down()` recreates them with exact original definitions. Verified on a Neon dev branch.

Out of scope (per spec): messages composite indexes (need EXPLAIN first).

## Verification
- `php artisan test --filter=ProcessAggregatedMessagesShouldGenerateTest` → 3 passed
- `php artisan test --filter=VipControllerTest` → 8 passed
- B3 migration applied + confirmed on a Neon dev branch (two indexes gone, others intact)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
  ```

  Expected: `gh` prints the new PR URL.
