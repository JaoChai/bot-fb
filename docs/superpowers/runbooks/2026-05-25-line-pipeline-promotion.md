# Runbook — LINE Webhook Pipeline Promotion Sequence (Sprint 2)

**Created:** 2026-05-25
**Owner:** jaochai
**Scope:** Operate the dark-launch sequence for the LineWebhook pipeline that has been live on bot 26 since 2026-05-16.

## Background

PR #163 (2026-05-16) merged the LineWebhook pipeline refactor with a feature flag controlling rollout:

- `PROCESS_LINE_PIPELINE_ENABLED` (env, bool) — master switch
- `PROCESS_LINE_PIPELINE_BOT_IDS` (env, csv) — bot whitelist; empty = all bots

Current production state (verified 2026-05-25):
- `PROCESS_LINE_PIPELINE_ENABLED=true`
- `PROCESS_LINE_PIPELINE_BOT_IDS=26`
- Bot 26 has been on the pipeline for 9 days with no observed errors in Railway logs.

The plan called for a 3-step sequence: bot 26 → bot 28 → all bots. Step 1 is done. This runbook covers Step 2 (add bot 28) and Step 3 (remove whitelist).

## Important constraints

- This is a **production env var change** — every flip should follow the pre-flight, change, post-flight pattern.
- The pipeline only runs for **text messages**. Sticker/image events fall back to the legacy `processEvent()` path regardless of the flag. This is by design (per PR #163 commit). Not in scope for this runbook.
- Rollback = remove the bot ID from the whitelist (no code deploy required).

---

## Step 2 — Promote bot 28

### Pre-flight (run before flipping)

#### 2.1 Verify bot 26 has had ≥24h on the pipeline with no Sentry regressions
- Open Sentry: https://o4510638628995072.ingest.us.sentry.io (or the project dashboard linked from the `SENTRY_LARAVEL_DSN` env)
- Filter: time range = last 24 hours; project = backend; query = `transaction:"Jobs/ProcessLINEWebhook"` OR `level:error` containing `LineWebhook`
- Record:
  - Pre-promotion error count on `Jobs/ProcessLINEWebhook`: ____
  - Pre-promotion p95 latency on `Jobs/ProcessLINEWebhook`: ____ ms
  - Any new error classes since 2026-05-16: ____ (if non-empty, STOP)

#### 2.2 Verify bot 28 is an active LINE bot
- Run: `php artisan tinker --execute "echo App\Models\Bot::find(28)?->status . ' - ' . App\Models\Bot::find(28)?->channel_type;"`
- Expected: `active - line` (or whatever the LINE channel string is in this codebase). If anything else, STOP.

#### 2.3 Verify both bots have similar shape (sanity check)
- Conversation volume / message volume in last 24h. Run:
  ```sql
  SELECT bot_id, COUNT(*) AS msg_count
  FROM messages
  WHERE conversation_id IN (
      SELECT id FROM conversations WHERE bot_id IN (26, 28)
  )
  AND created_at >= now() - interval '24 hours'
  GROUP BY bot_id;
  ```
- If bot 28's volume is wildly different from bot 26, note it. Not a blocker, but expect different scale of any latent regression.

### Operation

Run via Railway MCP (or Railway dashboard):

```
mcp__plugin_railway_railway__set_variables
  project_id: ba714504-2721-4535-9fc7-6b3d903c481a
  service_id: 36066744-e919-4084-ab57-a31e76694a9a (backend)
  variables: { "PROCESS_LINE_PIPELINE_BOT_IDS": "26,28" }
```

Railway will redeploy backend (~1 min). Wait for deploy SUCCESS.

### Post-flight (run after deploy)

#### 2.4 Confirm env applied
```
mcp__plugin_railway_railway__list_variables
  project_id: ba714504-2721-4535-9fc7-6b3d903c481a
  service_id: 36066744-e919-4084-ab57-a31e76694a9a
```
- Expected: `PROCESS_LINE_PIPELINE_BOT_IDS=26,28`

#### 2.5 Smoke test bot 28
- Send a real LINE message to bot 28's LINE channel.
- Tail logs:
  ```
  mcp__plugin_railway_railway__get_logs
    log_type: deploy
    service_id: 36066744-e919-4084-ab57-a31e76694a9a
    lines: 100
  ```
- Expect: pipeline path executes (search for `runPipeline` or stage service log lines). Confirm bot 28 replied.

#### 2.6 Start 24h watch
- Set a 24h reminder. Watch criteria:
  - New error issue count on `Jobs/ProcessLINEWebhook` ≤ 1
  - p95 latency does NOT increase >20% vs pre-promotion baseline
  - No customer complaints about bot 28

Rollback trigger (any one):
- New error class observed on `Jobs/ProcessLINEWebhook` correlated to bot 28
- Bot 28 reply rate drops vs pre-promotion baseline
- Customer report of broken bot 28

Rollback procedure:
```
mcp__plugin_railway_railway__set_variables
  variables: { "PROCESS_LINE_PIPELINE_BOT_IDS": "26" }
```
(Removes bot 28; bot 26 stays on pipeline.)

### Result (fill in after Step 2 24h watch)
- Promoted at: ____
- Pre-promo error count: ____
- Post-promo error count after 24h: ____
- p95 latency delta: ____
- Any rollback needed? Yes / No
- Notes: ____

---

## Step 3 — Full enable (remove whitelist)

### Pre-flight

#### 3.1 Verify Step 2 24h watch passed with GO decision

If Step 2 result table records a GO, proceed. Otherwise STOP — investigate first.

#### 3.2 Verify all active LINE bots can tolerate pipeline path
```sql
SELECT id, name, status, channel_type
FROM bots
WHERE channel_type = 'line' AND status = 'active'
ORDER BY id;
```
- For each bot not in the whitelist, ask: any reason this bot would behave differently from bot 26 / bot 28? (custom flow logic, special integrations, etc.)
- If any bot has special handling that the pipeline path might not cover, STOP and add them to the whitelist explicitly instead of clearing it.

### Operation

```
mcp__plugin_railway_railway__set_variables
  variables: { "PROCESS_LINE_PIPELINE_BOT_IDS": "" }
```

This makes the whitelist empty → with `PROCESS_LINE_PIPELINE_ENABLED=true`, all bots use the pipeline.

### Post-flight

#### 3.3 Confirm env applied + smoke test 2-3 random bots

#### 3.4 Start 48h watch (higher threshold than Step 2 because blast radius is bigger)

Rollback procedure:
```
mcp__plugin_railway_railway__set_variables
  variables: { "PROCESS_LINE_PIPELINE_BOT_IDS": "26,28" }
```
(Back to the proven dark-launch state.)

### Result (fill in after Step 3 48h watch)
- Enabled all at: ____
- Total LINE bot count moved to pipeline: ____
- New error classes in 48h: ____
- p95 latency change: ____
- Decision: keep enabled / roll back / partial
- Notes: ____

---

## Out of Scope (do NOT touch in this runbook)

- Non-text event coverage (sticker/image) — current pipeline doesn't handle these even when enabled. Separate ticket.
- Removing the legacy `processEvent()` path — only after Step 3 has baked for 7+ days clean.
- Removing the feature flag entirely — same gate as legacy path removal.
- Channel consolidation (Facebook/Telegram → same pipeline pattern) — that's Sprint 4 in the master roadmap.

## References
- Master roadmap: `docs/superpowers/specs/2026-05-25-refactor-initiative-roadmap.md`
- Pipeline implementation: PR #163, commit `f44791a` (2026-05-16)
- Pipeline spec: `docs/superpowers/specs/2026-05-16-process-line-webhook-refactor-design.md`
- Pipeline plan (historical): `docs/superpowers/plans/2026-05-16-process-line-webhook-refactor.md`
- Sprint 2 pre-flight drift report (this session, 2026-05-25): in master roadmap §11
