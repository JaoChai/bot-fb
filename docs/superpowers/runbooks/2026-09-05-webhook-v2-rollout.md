# Runbook — Webhook pipeline v2 rollout (LINE bot 26 → all)

Owner-operated. Claude does not touch Railway env (spec D3).

## Pre-flight
- PR-1 (#254) and PR-2 merged and deployed (Railway shows the new deployment healthy; `/up` = 200).
- `php artisan config:show webhook_pipeline_v2` in the backend shell prints `enabled => false`.

## Step 1 — LINE bot 26 only
Railway backend service → Variables:
- `WEBHOOK_PIPELINE_V2_ENABLED=true`
- `WEBHOOK_PIPELINE_V2_BOT_IDS=26`

Redeploy (config is cached at boot by `config:cache`).

Verify within 5 minutes: send a LINE text message to bot 26 from a test account → reply arrives; in logs (`railway logs`) the line `LINE webhook pipeline.start` still appears (same LineWebhook pipeline), no `Unsupported channel type` and no new exception class.

## Soak: 7 days
Check daily:
1. Sentry backend project — filter `Jobs\ProcessLINEWebhook`: **no new issue** since the flag flip. Any new issue → rollback.
2. Circuit breaker — `railway logs | grep "Circuit breaker for"`: state changes ≤ the week before the flip.
3. Queue depth — Railway metrics for the backend service: no sustained growth on `llm`/`webhooks`.

## Step 2 — all bots (48 h)
Clear `WEBHOOK_PIPELINE_V2_BOT_IDS` (empty) → v2 for every bot on every channel. Facebook/Telegram bots have no customers (spec D4); this step only proves nothing crashes for them. Watch the same three signals for 48 h.

Note for LINE bots other than 26: today they run the legacy `processEvent()` path, where an **image** message goes to `NonTextHandler` (saved, no AI). On v2, images go through the full `LineWebhook` pipeline (slip verification / vision), exactly as bot 26 already does. This is the intended end state (PR-3 makes it the only path), but it is a behavior change for those bots — if any of them starts serving customers before Step 2, review their image handling first.

## Rollback (any time before PR-3)
Set `WEBHOOK_PIPELINE_V2_ENABLED=false` and redeploy. Legacy paths resume with zero code change.

## Exit → PR-3
After Step 2 is clean for 48 h, tell Claude "soak ผ่าน" → PR-3 deletes the legacy paths and both flags (plan Task 8, `docs/superpowers/plans/2026-09-05-track2-webhook-v2.md`).
