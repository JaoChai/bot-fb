# Native Chat — Phase 0: Infrastructure Audit Plan

> **For agentic workers:** This is a non-code audit. Use Railway MCP, Sentry MCP, and manual investigation.

**Goal:** ตรวจ infrastructure ของ Reverb/WebSocket, queue worker, broadcasting config บน Railway production เพื่อหา silent issues ก่อน implement Phase 1-6

**Output:** Audit doc at `docs/superpowers/audits/realtime-audit.md`

**Depends on:** nothing (can run anytime)

---

## Checklist

### 1. Railway Service Topology
- [ ] List all Railway services: `mcp__railway__list-services`
- [ ] Identify which service runs Reverb (WebSocket server) — same container as Laravel API or separate?
- [ ] Check number of Reverb instances — if >1, REVERB_SCALING_ENABLED must be true
- [ ] Check if Reverb has its own domain/port or shares with API

### 2. Environment Variables
- [ ] `mcp__railway__list-variables` for the backend service
- [ ] Verify: `BROADCAST_CONNECTION=reverb` (not `null`)
- [ ] Verify: `QUEUE_CONNECTION` — should be `database` (current) or `redis` (Phase 5 target)
- [ ] Verify: `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_APP_ID` are set
- [ ] Verify: `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` match frontend VITE_REVERB_* vars
- [ ] Check: `REVERB_SCALING_ENABLED` — if multiple instances, must be `true`
- [ ] Check: Redis URL available (for Phase 5 queue migration)

### 3. Queue Worker Health
- [ ] `mcp__railway__get-logs` for queue worker — is it running?
- [ ] Check for restart loops or OOM kills
- [ ] Check queue depth: `mcp__neon__run_sql` → `SELECT COUNT(*) FROM jobs WHERE available_at <= NOW()`
- [ ] Check failed jobs: `SELECT COUNT(*) FROM failed_jobs`
- [ ] Check job throughput: recent jobs processed per minute

### 4. Broadcasting Errors (Sentry)
- [ ] `mcp__sentry__search_issues` for "broadcast" or "subscription_error"
- [ ] Check error rate for MessageSent/ConversationUpdated broadcast failures
- [ ] Check any 403 errors on `/api/broadcasting/auth` endpoint

### 5. Smoke Test: End-to-End Latency
- [ ] Send a test message via LINE webhook (or use existing conversation)
- [ ] Measure time from webhook received → broadcast → appears in browser
- [ ] Target: <2 seconds end-to-end
- [ ] If >5 seconds: investigate queue processing time vs broadcast time

### 6. Reverb Connection Stability
- [ ] Check Reverb logs for connection count, ping/pong failures
- [ ] Check if Railway's reverse proxy has WebSocket timeout settings
- [ ] Verify Reverb's `ping_interval` (25s) and `activity_timeout` (120s server-side) in config

## Deliverable

Write findings to `docs/superpowers/audits/realtime-audit.md` with structure:
- Summary (1 paragraph)
- Findings table (check | status | notes)
- Recommendations (prioritized list)
- Action items for Phase 1-6 (what to change based on findings)

## Definition of Done
- [ ] Audit doc committed
- [ ] 3+ actionable recommendations
- [ ] No blocking issues found (or blocking issues documented with workarounds)
