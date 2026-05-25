# Refactor Sprint 1 — Foundation Quick Wins Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land 4 low-risk DB/perf quick wins (composite index, query coalescing, eager load, table compaction) in ~1 day with measurable Sentry latency improvement on `Jobs/ProcessLINEWebhook` and zero regression risk.

**Architecture:** Three code/migration changes (Task 1-3) committed independently for granular rollback. One operational task (Task 4 VACUUM FULL) run inside maintenance window. Final verification task (Task 5) gates the sprint as complete only after 24h Sentry watch.

**Tech Stack:** Laravel 12 + PHPUnit 11 (PHP 8.3), PostgreSQL 16 on Neon, `CREATE INDEX CONCURRENTLY` for zero-lock index creation, `DB::listen()` for query-count regression tests.

**Spec reference:** `docs/superpowers/specs/2026-05-25-refactor-initiative-roadmap.md` §4 Sprint 1.

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `backend/database/migrations/2026_05_25_120000_add_webhook_event_id_index_to_messages.php` | Add composite index for webhook dedup path |
| Modify | `backend/app/Jobs/ProcessLINEWebhook.php` (lines 340-423 area) | Extract `botAlreadyRespondedTo()` helper; replace 2 inline probes |
| Modify | `backend/app/Services/LeadRecoveryService.php` (line 43) | Add `bot` eager load |
| Create | `backend/tests/Unit/Services/LeadRecoveryServiceEagerLoadTest.php` | Assert `findEligibleConversations()` eager-loads `bot` |
| Create | `docs/superpowers/runbooks/2026-05-25-vacuum-full-bots.md` | Step-by-step runbook for VACUUM FULL operation |

**Not creating:** New service classes, new helpers in unrelated files, new test base classes. Each change is surgical.

---

## Task 1: Add composite index `messages(conversation_id, webhook_event_id)`

**Files:**
- Create: `backend/database/migrations/2026_05_25_120000_add_webhook_event_id_index_to_messages.php`
- Test: existing `backend/tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php` (regression check only)

**Pre-check rationale:** Audit identified `Message::where(conversation_id, X)->where(webhook_event_id, Y)->first()` runs at 8+ sites without a matching index — Postgres falls back to seq-scan on `webhook_event_id` after index-scanning `conversation_id`. The existing composite `messages(conversation_id, external_message_id)` (added in `2026_04_27_110043`) does not help because `webhook_event_id` is a different column.

- [ ] **Step 1: Verify index is not already present**

Run:
```bash
cd backend && php artisan tinker --execute "echo \DB::select(\"SELECT indexname FROM pg_indexes WHERE tablename = 'messages' AND indexdef ILIKE '%webhook_event_id%'\")[0]->indexname ?? 'NOT FOUND';"
```

Expected: `NOT FOUND`. If a matching index exists, STOP — re-validate the spec assumption before continuing.

- [ ] **Step 2: Create migration file**

```bash
cd backend && php artisan make:migration add_webhook_event_id_index_to_messages
```

This generates a timestamped file. Rename it to match the planned filename `2026_05_25_120000_add_webhook_event_id_index_to_messages.php` if the Artisan timestamp differs (or accept the generated timestamp — both fine).

- [ ] **Step 3: Write migration body**

Replace the generated file contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add composite index messages(conversation_id, webhook_event_id) to optimize
 * the LINE/Facebook webhook deduplication path. Existing composite index
 * messages(conversation_id, external_message_id) does NOT cover this query
 * because webhook_event_id is a different column.
 *
 * Affected sites (verified 2026-05-25 audit):
 *   app/Jobs/ProcessLINEWebhook.php:367, 373, 397, 402
 *   app/Jobs/ProcessFacebookWebhook.php (similar pattern)
 *
 * Uses CONCURRENTLY for zero-lock creation on production.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_messages_conv_webhook_event
            ON messages (conversation_id, webhook_event_id)
            WHERE webhook_event_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_messages_conv_webhook_event');
    }
};
```

Note on `WHERE webhook_event_id IS NOT NULL`: partial index. Most historical rows have NULL `webhook_event_id` (column added later), so partial index is smaller and faster.

- [ ] **Step 4: Run migration locally to verify it applies cleanly**

Run:
```bash
cd backend && php artisan migrate
```

Expected output includes a line like `2026_05_25_120000_add_webhook_event_id_index_to_messages .................... DONE` and no error.

- [ ] **Step 5: Verify index was created**

Run:
```bash
cd backend && php artisan tinker --execute "echo \DB::select(\"SELECT indexname, indexdef FROM pg_indexes WHERE indexname = 'idx_messages_conv_webhook_event'\")[0]->indexdef ?? 'NOT FOUND';"
```

Expected: `CREATE INDEX idx_messages_conv_webhook_event ON public.messages USING btree (conversation_id, webhook_event_id) WHERE (webhook_event_id IS NOT NULL)`

- [ ] **Step 6: Verify EXPLAIN shows index usage for the dedup query**

Run:
```bash
cd backend && php artisan tinker --execute "
\$plan = \DB::select('EXPLAIN SELECT * FROM messages WHERE conversation_id = 1 AND webhook_event_id = ?', ['test-event-id']);
foreach (\$plan as \$row) echo \$row->{'QUERY PLAN'} . PHP_EOL;
"
```

Expected: plan output contains `Index Scan using idx_messages_conv_webhook_event` (or `Bitmap Index Scan` on it). If `Seq Scan on messages` appears, the index is not being chosen — STOP, run `ANALYZE messages;` and retry.

- [ ] **Step 7: Verify migration rollback works**

Run:
```bash
cd backend && php artisan migrate:rollback --step=1
```

Expected: rollback succeeds. Then re-run `php artisan migrate` to put the index back.

- [ ] **Step 8: Run the full webhook test suite to confirm no regression**

Run:
```bash
cd backend && vendor/bin/phpunit tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php tests/Unit/Jobs/ProcessLINEWebhookOfflineTest.php
```

Expected: all tests pass (existing suite, no behavior change from this task).

- [ ] **Step 9: Commit**

```bash
git add backend/database/migrations/2026_05_25_120000_add_webhook_event_id_index_to_messages.php
git commit -m "perf(db): add partial index messages(conversation_id, webhook_event_id)

Webhook dedup query in ProcessLINEWebhook (8+ sites) was falling back to
seq-scan on webhook_event_id because existing composite index covers
external_message_id, not webhook_event_id. Partial WHERE NOT NULL keeps
the index small (most historical rows predate the column).

Verified Index Scan via EXPLAIN. Spec section 4 Sprint 1 #1."
```

---

## Task 2: Coalesce duplicate Message lookups in `ProcessLINEWebhook`

**Files:**
- Modify: `backend/app/Jobs/ProcessLINEWebhook.php` (lines 340-423 area)

**Smell:** Two dedup branches (primary by `webhook_event_id`, fallback by `external_message_id`). Each branch independently runs the SAME "has the bot already responded?" probe (lines 373-376 and 402-405 are byte-for-byte identical). The two `first()` queries are intentionally different (different columns), but the bot-responded check is a pure copy-paste.

**Honest framing:** This is a **DRY refactor**, not a perf win. At runtime each webhook enters at most one dedup branch (early `return`), so the duplicated *code* fires only once per attempt. The win is readability and reduced edit risk for the next person who changes that probe. **Existing pipeline tests are the regression guard** — no new test file is needed because behavior does not change.

- [ ] **Step 1: Confirm the existing pipeline test covers both dedup branches**

Run:
```bash
cd backend && grep -nE "webhook_event_id|external_message_id|dedup|duplicate" tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php
```

Expected: at least one match referencing each dedup column. If both branches are not covered, STOP and ask the operator whether to add coverage now (would inflate Sprint 1 scope) or defer (and accept lower regression confidence on Task 2).

- [ ] **Step 2: Run the existing pipeline tests to establish a green baseline**

Run:
```bash
cd backend && vendor/bin/phpunit tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php tests/Unit/Jobs/ProcessLINEWebhookOfflineTest.php
```

Expected: all tests pass. Record the count (e.g. "27 tests, 89 assertions") — must match exactly after refactor.

- [ ] **Step 3: Add the private helper method to `ProcessLINEWebhook.php`**

Edit `backend/app/Jobs/ProcessLINEWebhook.php`. Find the existing `processMessage` method (around line 340). After the closing brace of `processMessage` (before the next method definition), insert:

```php
    /**
     * Determine whether the bot has already produced a reply for the given
     * existing user message. Shared between the webhook_event_id and
     * external_message_id dedup branches.
     */
    private function botAlreadyRespondedTo(int $conversationId, \Illuminate\Support\Carbon $existingMessageCreatedAt): bool
    {
        return \App\Models\Message::where('conversation_id', $conversationId)
            ->where('sender', 'bot')
            ->where('created_at', '>=', $existingMessageCreatedAt)
            ->exists();
    }
```

- [ ] **Step 4: Replace the two duplicated inline checks**

In the same file, the following 4-line block appears TWICE (once at lines 373-376, once at lines 402-405):

```php
                $botAlreadyResponded = Message::where('conversation_id', $conversation->id)
                    ->where('sender', 'bot')
                    ->where('created_at', '>=', $existingMsg->created_at)
                    ->exists();
```

Replace BOTH occurrences with:

```php
                $botAlreadyResponded = $this->botAlreadyRespondedTo($conversation->id, $existingMsg->created_at);
```

Use the Edit tool with `replace_all: true` — the snippet is byte-identical in both places.

- [ ] **Step 5: Run the same existing tests to confirm zero behavior change**

Run:
```bash
cd backend && vendor/bin/phpunit tests/Unit/Jobs/ProcessLINEWebhookPipelineTest.php tests/Unit/Jobs/ProcessLINEWebhookOfflineTest.php
```

Expected: identical pass count to Step 2 baseline. If any test fails or count changes, the helper extraction broke behavior — revert and investigate (likely a typo in helper signature or accidentally replaced something else).

- [ ] **Step 6: Verify with `git diff` that ONLY the two probe blocks and the new helper changed**

Run:
```bash
cd backend && git diff app/Jobs/ProcessLINEWebhook.php
```

Expected: diff shows exactly (a) the new `botAlreadyRespondedTo` method added, (b) two 4-line inline probes replaced with 1-line helper calls, and nothing else. If extra hunks appear, revert the unrelated changes — Sprint 1 stays surgical (CLAUDE.md rule).

- [ ] **Step 7: Run Pint**

Run:
```bash
cd backend && vendor/bin/pint app/Jobs/ProcessLINEWebhook.php
```

Expected: `1 file formatted` or `Nothing to fix`.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Jobs/ProcessLINEWebhook.php
git commit -m "refactor(line-webhook): extract botAlreadyRespondedTo() helper

Two dedup branches (by webhook_event_id and external_message_id) ran a
byte-identical 'has bot already responded?' probe inline. Extract to a
private helper. Pure DRY refactor — behavior unchanged, existing pipeline
test suite is the regression guard.

Spec section 4 Sprint 1 #2."
```

---

## Task 3: Add `bot` eager load in `LeadRecoveryService::findEligibleConversations()`

**Files:**
- Modify: `backend/app/Services/LeadRecoveryService.php` (line 43)
- Create: `backend/tests/Unit/Services/LeadRecoveryServiceEagerLoadTest.php`

**Smell:** `findEligibleConversations()` eager-loads `customerProfile` but not `bot`. The caller (`processRecovery()` line 55) immediately accesses `$conversation->bot`, triggering one extra query per conversation. For a bot with N eligible conversations, that's N extra round trips.

- [ ] **Step 1: Write the failing eager-load assertion test**

Create `backend/tests/Unit/Services/LeadRecoveryServiceEagerLoadTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\LeadRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression test for Sprint 1 #11 — findEligibleConversations must eager-load
 * the bot relation, otherwise processRecovery triggers a lazy-load per row.
 */
class LeadRecoveryServiceEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_eligible_conversations_eager_loads_bot(): void
    {
        $bot = Bot::factory()->create();
        // Create 3 conversations eligible for recovery (rely on factory defaults
        // for needsRecovery; if factory requires explicit setup, supply
        // last_message_at and recovery_attempts as needed).
        $conversations = Conversation::factory()->count(3)->create([
            'bot_id' => $bot->id,
        ]);

        $service = app(LeadRecoveryService::class);

        // Collect queries fired AFTER findEligibleConversations() returns,
        // while iterating $conv->bot — that loop must produce ZERO new queries.
        $results = $service->findEligibleConversations($bot);

        $queriesAfterFetch = 0;
        DB::listen(function () use (&$queriesAfterFetch) {
            $queriesAfterFetch++;
        });

        foreach ($results as $conversation) {
            // Touch the relation — must be already loaded.
            $_ = $conversation->bot?->id;
        }

        $this->assertSame(
            0,
            $queriesAfterFetch,
            "Expected zero queries when iterating eager-loaded bot relation, got $queriesAfterFetch"
        );
    }
}
```

Note: if `Conversation::factory()` does not produce rows that satisfy the `needsRecovery()` scope, the result set will be empty and the test will trivially pass. Verify by `dd($results->count())` once before committing, or extend the factory call with explicit `last_message_at` / `recovery_attempts` values matching the scope's predicate.

- [ ] **Step 2: Run the test to verify it currently fails**

Run:
```bash
cd backend && vendor/bin/phpunit tests/Unit/Services/LeadRecoveryServiceEagerLoadTest.php
```

Expected: test FAILS with `Expected zero queries when iterating eager-loaded bot relation, got 3` (or similar — one lazy-load per conversation).

If it passes with `got 0` because the result set is empty, fix the factory setup first (see note above) and re-run — the test must fail with N>0.

- [ ] **Step 3: Add the eager load**

Edit `backend/app/Services/LeadRecoveryService.php`. Find line 43:

```php
            ->with('customerProfile')
```

Replace with:

```php
            ->with(['customerProfile', 'bot'])
```

- [ ] **Step 4: Run the test to verify it passes**

Run:
```bash
cd backend && vendor/bin/phpunit tests/Unit/Services/LeadRecoveryServiceEagerLoadTest.php
```

Expected: test PASSES.

- [ ] **Step 5: Run existing LeadRecovery test suites to confirm no regression**

Run:
```bash
cd backend && vendor/bin/phpunit tests/Feature/LeadRecoveryTest.php tests/Unit/Services/LeadRecoveryServiceTest.php tests/Unit/Services/LeadRecoveryServiceEagerLoadTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Run Pint**

Run:
```bash
cd backend && vendor/bin/pint app/Services/LeadRecoveryService.php tests/Unit/Services/LeadRecoveryServiceEagerLoadTest.php
```

Expected: `Nothing to fix` or auto-formatted.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Services/LeadRecoveryService.php backend/tests/Unit/Services/LeadRecoveryServiceEagerLoadTest.php
git commit -m "perf(lead-recovery): eager-load bot relation in findEligibleConversations

processRecovery() accesses \$conversation->bot immediately, so the missing
eager-load triggered one lazy query per eligible conversation. Add regression
test that fails when iterating N conversations would fire N queries.

Spec section 4 Sprint 1 #11."
```

---

## Task 4: VACUUM FULL `bots` table (operational, runbook-driven)

**Files:**
- Create: `docs/superpowers/runbooks/2026-05-25-vacuum-full-bots.md`

**Why a runbook, not code:** VACUUM FULL is a one-shot ops command. Production safety requires (a) running inside the 02:00-08:00 +07 maintenance window per spec C1, (b) verifying dead-tuple ratio before AND after, (c) recording the operation in deploy notes. The runbook documents this so the next person can repeat or reverse the decision.

`VACUUM FULL` takes an `ACCESS EXCLUSIVE` lock on the table for the duration (rewrites the entire table). For `bots` (low row count, admin table — not on hot path), expect <30 seconds. Production reads/writes on `bots` are blocked during this window.

- [ ] **Step 1: Create the runbook**

Create `docs/superpowers/runbooks/2026-05-25-vacuum-full-bots.md`:

```markdown
# Runbook — VACUUM FULL on `bots` Table (Sprint 1 #12)

**Date executed:** TBD (record at run time)
**Operator:** TBD
**Window:** 02:00-08:00 +07 maintenance window
**Estimated duration:** 10-30 seconds (bots table is small)
**Lock impact:** ACCESS EXCLUSIVE on `bots` for the duration — all reads/writes blocked

## Why
Perf audit (2026-05-15) measured 52% dead tuples on `bots`. VACUUM (regular) cannot reclaim space without rewrites, so `VACUUM FULL` is required.

## Pre-flight checks (run from neon mcp or psql)

### Check 1 — confirm dead tuple ratio is still high
```sql
SELECT
    relname,
    n_live_tup,
    n_dead_tup,
    ROUND(100.0 * n_dead_tup / NULLIF(n_live_tup + n_dead_tup, 0), 2) AS dead_pct
FROM pg_stat_user_tables
WHERE relname = 'bots';
```
- If `dead_pct < 20`, skip this task — autovacuum already cleaned up.
- If `dead_pct >= 20`, proceed.

### Check 2 — confirm no long-running transactions are holding locks on `bots`
```sql
SELECT pid, state, query_start, query
FROM pg_stat_activity
WHERE query ILIKE '%bots%' AND state != 'idle';
```
- Expected: empty result. If anything is running, wait or coordinate.

## Operation

```sql
VACUUM FULL ANALYZE bots;
```

(`ANALYZE` is bundled so statistics get refreshed after the rewrite.)

## Post-flight verification

### Check 1 — dead tuple ratio collapsed
```sql
SELECT
    relname,
    n_live_tup,
    n_dead_tup,
    ROUND(100.0 * n_dead_tup / NULLIF(n_live_tup + n_dead_tup, 0), 2) AS dead_pct
FROM pg_stat_user_tables
WHERE relname = 'bots';
```
- Expected: `dead_pct < 5` (target < 10 per spec).

### Check 2 — table is still queryable
```sql
SELECT COUNT(*) FROM bots;
```
- Expected: matches `n_live_tup` from pre-flight check.

## Rollback
Not applicable. VACUUM FULL is non-destructive (only reclaims dead space). The "rollback" is doing nothing — the table is fully functional after the operation.

## Result (fill in after running)
- Pre-run dead_pct: ____
- Post-run dead_pct: ____
- Duration: ____ seconds
- Issues encountered: ____
```

- [ ] **Step 2: Commit the runbook**

```bash
git add docs/superpowers/runbooks/2026-05-25-vacuum-full-bots.md
git commit -m "docs(runbook): VACUUM FULL bots table operational procedure

Documents pre-flight checks (still-high dead tuple ratio, no long-running
locks), the actual command, post-flight verification, and result template.
Operation itself must run inside the maintenance window before Sprint 1
verification (Task 5).

Spec section 4 Sprint 1 #12."
```

- [ ] **Step 3: Execute the runbook against production**

This step is performed by the operator (Claude or jaochai) at the maintenance window. Follow the runbook end-to-end:
1. Run pre-flight Check 1. Record `dead_pct`.
2. Run pre-flight Check 2. Verify no blocking activity.
3. Run `VACUUM FULL ANALYZE bots;`.
4. Run post-flight Check 1. Record new `dead_pct`.
5. Run post-flight Check 2. Confirm count matches.
6. Edit the runbook's "Result" section with actual numbers + duration.
7. Commit the filled-in runbook:

```bash
git add docs/superpowers/runbooks/2026-05-25-vacuum-full-bots.md
git commit -m "ops(runbook): record VACUUM FULL bots execution results"
```

If executing via Neon MCP, the SQL goes through `mcp__plugin_neon_neon__run_sql` against the production project. Confirm `applyChanges: true` only after the pre-flight checks pass.

---

## Task 5: Post-deploy verification + 24h Sentry watch

**Files:**
- Modify: `docs/superpowers/runbooks/2026-05-25-vacuum-full-bots.md` (append production verification section if separate from VACUUM)

**Goal:** Confirm the spec's Sprint 1 acceptance criteria are satisfied before declaring the sprint done. No code changes — this is verification.

- [ ] **Step 1: Confirm production migration ran**

Run (against production via MCP or `railway run`):
```bash
php artisan migrate:status | grep webhook_event_id
```

Expected: shows the migration with status `Ran`. If `Pending`, run `php artisan migrate --force` (still inside maintenance window).

- [ ] **Step 2: Confirm production EXPLAIN uses the new index**

Run via Neon MCP or psql against production:
```sql
EXPLAIN (ANALYZE, BUFFERS)
SELECT * FROM messages
WHERE conversation_id = <a-real-conversation-id>
  AND webhook_event_id = 'any-string';
```

Expected: plan contains `Index Scan using idx_messages_conv_webhook_event` (or bitmap scan on it). Record the plan in a comment on the migration PR for evidence.

- [ ] **Step 3: Start the 24h Sentry watch**

Open the Sentry release dashboard for the production deploy that includes commits from Tasks 1-3. Set a 24h reminder.

Watch criteria (rollback trigger = any one fails):
- New error issue count on `Jobs/Process*Webhook*` ≤ 1 (zero ideally; existing flakes allowed)
- p95 latency on `Jobs/ProcessLINEWebhook` does not increase
- p95 latency target: reduce by ≥5% vs the previous 7-day baseline (the win we are claiming)

- [ ] **Step 4: After 24h, record results and close the sprint**

Append to the master roadmap spec (`docs/superpowers/specs/2026-05-25-refactor-initiative-roadmap.md`) under a new section "Sprint 1 Result":

```markdown
## Sprint 1 Result (recorded YYYY-MM-DD)
- Migration applied: idx_messages_conv_webhook_event ✅
- EXPLAIN confirms Index Scan: ✅
- p95 latency on ProcessLINEWebhook: <before> ms → <after> ms (<delta>%)
- New Sentry error classes in 24h: <count>
- VACUUM FULL bots: dead_pct <before>% → <after>%
- Decision: GO / NO-GO for Sprint 2
```

- [ ] **Step 5: Commit the results record**

```bash
git add docs/superpowers/specs/2026-05-25-refactor-initiative-roadmap.md
git commit -m "docs(spec): record Sprint 1 actual results

Captures measured latency delta, EXPLAIN evidence, VACUUM stats, and
GO/NO-GO decision for Sprint 2."
```

---

## Rollback Reference

| Task | What to revert |
|------|----------------|
| 1 | `cd backend && php artisan migrate:rollback --step=1` — drops the index via CONCURRENTLY. Non-destructive. |
| 2 | `git revert <commit>` on the helper extraction. Behavior is unchanged so revert is purely cosmetic. |
| 3 | `git revert <commit>` on the eager-load change. Reverts back to lazy-load (perf-only impact). |
| 4 | Not applicable. VACUUM FULL cannot be undone (and does not need to be — only reclaims space). |
| 5 | N/A — verification task only. |

All rollbacks are independent; reverting one task does not require reverting others.

---

## Definition of Done (Sprint 1)

- [ ] All 5 tasks above checked off
- [ ] All commits pushed to feature branch
- [ ] CI green on the branch
- [ ] Migration ran in production
- [ ] 24h Sentry watch completed, results recorded in roadmap spec
- [ ] GO decision logged before Sprint 2 begins
