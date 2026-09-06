# Track 0 — Infra + Dependencies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Production PHP runs with OPcache and warmed framework caches, all high-severity dependency advisories are closed, CI blocks them from returning, and the stray `error_log` debug lines are gone.

**Architecture:** Five independent, individually revertible commits on one branch: (1) debug-log cleanup, (2) Dockerfile OPcache + cache warm-up, (3) backend `composer update`, (4) frontend `npm update` + `npm audit fix`, (5) CI audit gates. No application logic changes. Verification is the existing test suites plus a local Docker build.

**Tech Stack:** PHP 8.4 (`php:8.4-fpm-alpine`), Laravel 13, Composer 2.9, Node 22, npm, Vite 8, GitHub Actions, Docker 29 (local build check).

**Spec:** `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §4 (Track 0)

## Global Constraints

- Dependency policy: **minor/patch + security only** (spec D2). Do not change any `^major` constraint in `composer.json` or `package.json`. Never run `npm audit fix --force`.
- Keep `doctrine/annotations` (spec D7).
- Docker base image stays `php:8.4-fpm-alpine` (spec D7). CI stays PHP 8.4 / Node 22.
- Railway env vars are not touched (spec D3). This track needs none.
- Every commit: `composer test` green, `vendor/bin/pint --test` clean, `npm run lint` 0 errors, `npx tsc --noEmit` clean, `npm test` green.
- Branch: `chore/track0-infra-deps` (create via `superpowers:using-git-worktrees` at execution start).
- Commit message footer (required):
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH
  ```

## Baseline (2026-09-05, main @ `7eba4f6`)

| Check | Result |
|---|---|
| `php artisan test --parallel` | 1123 passed, 15 skipped, 91 notices |
| `npm test` | 30 files / 154 tests passed |
| `npm run lint` | 0 errors, 24 warnings |
| `composer audit` | 16 advisories (league/commonmark ×10, guzzlehttp/guzzle ×6) |
| `npm audit --omit=dev` | 4 (react-router high, nanoid high, postcss high, qs moderate) |
| `php artisan optimize` (local) | **FAILS** at `views` — `resources/views` does not exist (API-only app). Plan uses the three explicit cache commands instead; spec §4.1 is corrected in Task 2. |

---

### Task 1: Remove `PLUGIN DEBUG` `error_log` lines

**Files:**
- Modify: `backend/app/Services/FlowPluginService.php:26,33,37`
- Test (existing): `backend/tests/` — any test touching `FlowPluginService` (`grep -rl FlowPluginService backend/tests`)

**Interfaces:**
- Consumes: nothing
- Produces: nothing (log noise removal only; no test asserts on these lines — verified `grep -rn "PLUGIN DEBUG" backend/tests` → 0)

- [ ] **Step 1: Confirm no test depends on the lines**

Run: `grep -rn "PLUGIN DEBUG" backend/tests backend/app`
Expected: exactly 3 hits, all in `backend/app/Services/FlowPluginService.php`.

- [ ] **Step 2: Delete the three `error_log` calls**

Edit `backend/app/Services/FlowPluginService.php` so the top of `executePlugins()` reads:

```php
    public function executePlugins(Bot $bot, Conversation $conversation, Message $botMessage): void
    {
        $flow = $conversation->currentFlow ?? $bot->defaultFlow;
        if (! $flow) {
            return;
        }

        $plugins = $flow->plugins()->where('enabled', true)->get();
        if ($plugins->isEmpty()) {
            return;
        }

        // Eager load user.settings to avoid N+1 query during API key resolution
```

(Only the three `error_log(...)` statements are removed; the blank lines that separated them from `return;` are collapsed as shown.)

- [ ] **Step 3: Run the plugin tests and style check**

Run: `cd backend && php artisan test --filter=Plugin && vendor/bin/pint --test app/Services/FlowPluginService.php`
Expected: all tests PASS; Pint reports no style issues.

- [ ] **Step 4: Confirm the noise is gone from a full run**

Run: `cd backend && php artisan test --parallel --compact 2>&1 | grep -c "PLUGIN DEBUG"`
Expected: `0`

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/FlowPluginService.php
git commit -m "chore(plugins): ลบ error_log PLUGIN DEBUG ที่หลุดไป prod stderr

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 2: Enable OPcache and warm all framework caches in the production image

**Files:**
- Create: `backend/docker/php/opcache.ini`
- Modify: `backend/Dockerfile:15` (ext-install line), `backend/Dockerfile:88` (add COPY after www.conf), `backend/Dockerfile:180` (CMD)
- Modify: `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §4.1 (replace the `php artisan optimize` sentence)

**Interfaces:**
- Consumes: nothing
- Produces: image where `php -i` reports `opcache.enable => On` and the ini file is listed by `php --ini`

- [ ] **Step 1: Create the OPcache ini**

Create `backend/docker/php/opcache.ini`:

```ini
; Production OPcache — image is immutable per deploy, so timestamps are never re-validated.
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
; Required: Laravel attributes + l5-swagger docblocks are read via reflection.
opcache.save_comments=1
```

- [ ] **Step 2: Install the extension and copy the ini in the Dockerfile**

Edit `backend/Dockerfile` line 15 from:

```dockerfile
    && docker-php-ext-install pdo pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip
```

to:

```dockerfile
    && docker-php-ext-install pdo pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip opcache
```

Immediately after line 88 (`COPY docker/php-fpm/www.conf /usr/local/etc/php-fpm.d/www.conf`) add:

```dockerfile

# Production OPcache settings (extension installed above)
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
```

(`zz-` prefix guarantees it loads after `docker-php-ext-opcache.ini`, which only contains `zend_extension=opcache`.)

- [ ] **Step 3: Warm events in addition to config and routes at boot**

Edit `backend/Dockerfile` line 180 (the `CMD`) from:

```dockerfile
CMD ["sh", "-c", "envsubst '${PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf && php artisan config:cache && php artisan route:cache && php artisan migrate --force && supervisord -c /etc/supervisor/conf.d/laravel.conf"]
```

to:

```dockerfile
# Cache config, discovered events and routes before serving. `php artisan optimize` is NOT used:
# it also runs view:cache, which fails because this API has no resources/views directory.
CMD ["sh", "-c", "envsubst '${PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf && php artisan config:cache && php artisan event:cache && php artisan route:cache && php artisan migrate --force && supervisord -c /etc/supervisor/conf.d/laravel.conf"]
```

- [ ] **Step 4: Build the image locally and verify OPcache is loaded**

Run:

```bash
cd backend && docker build -t bot-fb-backend:track0 . \
  && docker run --rm --entrypoint php bot-fb-backend:track0 --ini | grep zz-opcache \
  && docker run --rm --entrypoint php bot-fb-backend:track0 -d opcache.enable_cli=1 -i | grep -E "^opcache\.(enable|validate_timestamps|memory_consumption|max_accelerated_files|save_comments) "
```

Expected output contains:

```
/usr/local/etc/php/conf.d/zz-opcache.ini,
opcache.enable => On => On
opcache.max_accelerated_files => 20000 => 20000
opcache.memory_consumption => 128 => 128
opcache.save_comments => On => On
opcache.validate_timestamps => Off => Off
```

(`-d opcache.enable_cli=1` is only so the CLI reports the values; FPM reads the same ini.)

- [ ] **Step 5: Verify the boot cache commands succeed inside the image**

Run:

```bash
docker run --rm --entrypoint sh -e APP_KEY=base64:$(head -c 32 /dev/urandom | base64) -e APP_ENV=production bot-fb-backend:track0 \
  -c "php artisan config:cache && php artisan event:cache && php artisan route:cache && echo BOOT_CACHE_OK"
```

Expected: three `DONE`/`cached successfully` lines followed by `BOOT_CACHE_OK`. (No DB access is required by these three commands.)

- [ ] **Step 6: Correct spec §4.1**

In `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` replace the bullet that begins `- CMD: replace \`php artisan config:cache && php artisan route:cache\` with \`php artisan optimize\`` with:

```markdown
- CMD: add `php artisan event:cache` between `config:cache` and `route:cache`. `php artisan optimize` is **not** used because it also runs `view:cache`, which fails on this API-only app (no `resources/views`; verified locally 2026-09-05). `migrate --force` stays.
```

- [ ] **Step 7: Commit**

```bash
git add backend/docker/php/opcache.ini backend/Dockerfile docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md
git commit -m "perf(docker): เปิด OPcache ใน production image และ cache events ตอน boot

php:8.4-fpm-alpine ไม่เปิด opcache ให้เอง ทุก request จึงคอมไพล์ PHP ใหม่
validate_timestamps=0 เพราะ image immutable ต่อ deploy

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 3: Backend `composer update` (minor/patch only) to close 16 advisories

**Files:**
- Modify: `backend/composer.lock` (only; `composer.json` constraints untouched)

**Interfaces:**
- Consumes: nothing
- Produces: `composer audit` exits 0 with no high advisories; `laravel/framework` at 13.30.x

- [ ] **Step 1: Record the current advisory list (for the PR description)**

Run: `cd backend && composer audit --format=summary`
Expected: `Found 16 security vulnerability advisories affecting 2 packages.`

- [ ] **Step 2: Update within existing constraints**

Run: `cd backend && composer update --no-interaction --prefer-dist 2>&1 | tail -40`
Expected: ends with `Package manifest generated successfully` / `Generating optimized autoload files` and no `Your requirements could not be resolved` error. `composer.json` must show **no diff** (`git diff --stat backend/composer.json` → empty).

- [ ] **Step 3: Confirm the advisories are closed and majors are unchanged**

Run:

```bash
cd backend && composer audit --ignore-severity=low --ignore-severity=medium --abandoned=report; echo "audit exit=$?" \
  && composer show laravel/framework pestphp/pest phpunit/phpunit league/commonmark guzzlehttp/guzzle | grep -E "^(name|versions)"
```

Expected: `audit exit=0`; `laravel/framework` `v13.30.x`, `pestphp/pest` `v4.x`, `phpunit/phpunit` `12.x`, commonmark and guzzle at versions newer than 2026-09-05 baselines (audit exit 0 is the authoritative check).

- [ ] **Step 4: Run the full backend suite and style check**

Run: `cd backend && php artisan test --parallel --compact 2>&1 | tail -4 && vendor/bin/pint --test`
Expected: `1123 passed` (or more), `0 failed`; Pint clean. If a test fails because of a Laravel 13.x patch behavior change, fix the **test** only if the new framework behavior is documented in the 13.x changelog; otherwise stop and report.

- [ ] **Step 5: Commit**

```bash
git add backend/composer.lock
git commit -m "chore(deps): composer update minor/patch — ปิด 16 advisories (commonmark, guzzle), Laravel 13.30

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 4: Frontend `npm update` + `npm audit fix` (no `--force`)

**Files:**
- Modify: `frontend/package-lock.json`; `frontend/package.json` only if `npm audit fix` bumps a caret floor (allowed; majors must not change)

**Interfaces:**
- Consumes: nothing
- Produces: `npm audit --omit=dev --audit-level=high` exits 0; `react-router` ≥ 8.3.1

- [ ] **Step 1: Record the current advisories**

Run: `cd frontend && npm audit --omit=dev 2>&1 | tail -3`
Expected: `4 vulnerabilities (1 moderate, 3 high)`.

- [ ] **Step 2: Update within caret ranges, then apply non-breaking audit fixes**

Run: `cd frontend && npm update && npm audit fix`
Expected: no `npm ERR!`. Then confirm no major changed:

```bash
cd frontend && git diff package.json | grep -E '^[-+]\s+"' || echo "package.json unchanged"
```

Expected: either `package.json unchanged` or only lines where the leading major digit is identical between `-` and `+`. If any major changed, run `git checkout package.json package-lock.json` and stop — report which package `npm audit fix` tried to major-bump.

- [ ] **Step 3: Verify the audit gate and key versions**

Run:

```bash
cd frontend && npm audit --omit=dev --audit-level=high; echo "audit exit=$?" \
  && npm ls react-router react react-dom vite vitest typescript --depth=0
```

Expected: `audit exit=0`; `react-router@8.3.x`, `react@19.2.x`, `vite@8.2.x`, `vitest@4.1.x`, `typescript@6.0.x`.

- [ ] **Step 4: Run lint, types, tests, build**

Run: `cd frontend && npm run lint && npx tsc --noEmit && npm test && npm run build 2>&1 | tail -3`
Expected: lint `0 errors` (warnings ≤ 24), tsc silent, `154 passed` (or more), build `✓ built`.

- [ ] **Step 5: Commit**

```bash
git add frontend/package.json frontend/package-lock.json
git commit -m "chore(deps): npm update minor/patch + audit fix — react-router 8.3, nanoid, postcss, qs

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 5: CI audit gates

**Files:**
- Modify: `.github/workflows/ci.yml:33-36` (backend) and `:54-57` (frontend)

**Interfaces:**
- Consumes: Tasks 3–4 (audits must already pass locally, otherwise this task turns CI red)
- Produces: CI fails on any new high-severity advisory in either lockfile

- [ ] **Step 1: Add the backend audit step**

In `.github/workflows/ci.yml`, after the backend `Install dependencies` step (line 33–35) and before `Run tests`, insert:

```yaml
      - name: Audit dependencies
        working-directory: backend
        run: composer audit --ignore-severity=low --ignore-severity=medium --abandoned=report
```

- [ ] **Step 2: Add the frontend audit step**

After the frontend `Install dependencies` step (`npm ci`, lines 54–56) and before `Run linter`, insert:

```yaml
      - name: Audit dependencies
        working-directory: frontend
        run: npm audit --omit=dev --audit-level=high
```

- [ ] **Step 3: Validate the YAML and run both gates locally exactly as CI will**

Run:

```bash
python3 -c "import yaml,sys; d=yaml.safe_load(open('.github/workflows/ci.yml')); print([s['name'] for s in d['jobs']['backend-tests']['steps']]); print([s['name'] for s in d['jobs']['frontend-checks']['steps']])" \
  && (cd backend && composer audit --ignore-severity=low --ignore-severity=medium --abandoned=report) \
  && (cd frontend && npm audit --omit=dev --audit-level=high) && echo GATES_OK
```

Expected: both step lists contain `Audit dependencies` immediately after `Install dependencies`; output ends with `GATES_OK`. (If `python3 -c "import yaml"` fails with ModuleNotFoundError, use `ruby -ryaml -e 'p YAML.load_file(".github/workflows/ci.yml")["jobs"].keys'` instead — it only needs to parse.)

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: เพิ่ม composer audit + npm audit เป็น gate กัน advisory ระดับ high กลับมา

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH"
```

---

### Task 6: Final verification, PR, and post-deploy checklist

**Files:**
- Create: none (PR body only)

**Interfaces:**
- Consumes: Tasks 1–5 commits
- Produces: open PR against `main`; post-deploy verification commands for the user

- [ ] **Step 1: Full green run on the branch**

Run:

```bash
(cd backend && php artisan test --parallel --compact 2>&1 | tail -3 && vendor/bin/pint --test) \
  && (cd frontend && npm run lint && npx tsc --noEmit && npm test 2>&1 | tail -3 && npm run build 2>&1 | tail -2) \
  && git status --short && echo ALL_GREEN
```

Expected: backend `0 failed`, Pint clean, lint 0 errors, tsc silent, vitest all passed, build OK, `git status --short` empty, `ALL_GREEN`.

- [ ] **Step 2: Push and open the PR**

```bash
git push -u origin chore/track0-infra-deps
gh pr create --base main --title "chore: Track 0 — OPcache, dependency security updates, CI audit gates" --body "$(cat <<'EOF'
## Summary
- Enable OPcache in the production image (`php:8.4-fpm-alpine` ships without it) and cache events at boot
- `composer update` (minor/patch): closes 16 advisories (league/commonmark ×10, guzzlehttp/guzzle ×6), Laravel 13.30
- `npm update` + `npm audit fix`: react-router 8.3, nanoid, postcss, qs
- CI: `composer audit` / `npm audit` gates at severity high
- Remove `error_log("PLUGIN DEBUG …")` from `FlowPluginService`

Spec: `docs/superpowers/specs/2026-09-05-refactor-perf-initiative-design.md` §4
Plan: `docs/superpowers/plans/2026-09-05-track0-infra-deps.md`

## Test plan
- [x] backend suite + Pint
- [x] frontend lint / tsc / vitest / build
- [x] `docker build` locally; `php --ini` lists `zz-opcache.ini`, `opcache.enable => On`
- [ ] Post-deploy (user): see checklist in plan Task 6 Step 3

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01RZjU9XVsVAq14bdEbWdfSH
EOF
)"
```

Expected: PR URL printed; CI runs `Audit dependencies` steps and is green.

- [ ] **Step 3: Hand the user the post-deploy checklist (paste into the chat reply)**

After the user merges and Railway deploys, they run (from the backend service shell, e.g. `railway ssh` or the Railway web shell):

```bash
php -d opcache.enable_cli=1 -i | grep -E "^opcache\.(enable|validate_timestamps) "
ls /var/www/html/bootstrap/cache/   # expect config.php, events.php, routes-v7.php
curl -s -o /dev/null -w "%{http_code}\n" https://api.botjao.com/up          # 200
curl -s -o /dev/null -w "%{http_code}\n" https://api.botjao.com/api/health  # 200
```

Then a 24 h Sentry watch: rollback trigger is any **new** error class on the backend project (revert the PR and redeploy; no data or schema changes were made).

---

## Self-review

- **Spec coverage (§4):** 4.1 OPcache + boot caches → Task 2 (with the `optimize` correction folded in); 4.2 backend/frontend deps + keep doctrine/annotations → Tasks 3–4 (constraints untouched); 4.3 CI hardening → Task 5; 4.4 PLUGIN DEBUG cleanup → Task 1; 4.5 verification + rollback → Tasks 2 (docker), 3–4 (audits), 6 (post-deploy). No gaps.
- **Placeholders:** none. Every command has an expected output.
- **Consistency:** ini filename `zz-opcache.ini` and source path `backend/docker/php/opcache.ini` are identical in Task 2 Steps 1, 2, 4 and Task 6 Step 3; audit commands in Task 5 match those used in Tasks 3–4 Step 3.
