---
name: webhook-debug
description: |
  Webhook and messaging debugger for LINE, Telegram, Facebook, and WebSocket. Includes concurrency patterns and race condition debugging.
  Triggers: 'webhook', 'bot not responding', 'message not arriving', 'queue failed', 'WebSocket', 'Echo', 'race condition', 'deadlock', 'locks', 'duplicate', 'concurrency'.
  Use when: messages don't arrive, webhooks fail, real-time features don't work, bot stops responding, duplicate messages, race conditions.
allowed-tools:
  - Bash(php artisan queue*)
  - Bash(curl*)
  - Read
  - Grep
context:
  - path: app/Http/Controllers/Webhook/LINEWebhookController.php
  - path: app/Http/Controllers/Webhook/TelegramWebhookController.php
  - path: app/Http/Controllers/Webhook/FacebookWebhookController.php
  - path: app/Jobs/ProcessLINEWebhook.php
  - path: app/Jobs/ProcessTelegramWebhook.php
  - path: app/Jobs/ProcessFacebookWebhook.php
  - path: config/broadcasting.php
---

# Webhook & Messaging Debugger

Debug LINE, Telegram, Facebook webhooks และ real-time messaging.

## Quick Start

เมื่อ bot ไม่ตอบ ให้ตรวจตามลำดับ:
1. **Webhook ถึง server ไหม?** → Check Railway logs
2. **Job ถูก dispatch ไหม?** → Check queue
3. **AI ตอบกลับไหม?** → Check OpenRouter/model
4. **ส่งกลับ platform ได้ไหม?** → Check API credentials

## MCP Tools Available

- **neon**: `run_sql` - Query messages, conversations
- **railway**: `get-logs` - Deployment and application logs
- **sentry**: `search_issues`, `analyze_issue_with_seer` - Error analysis
- **claude-mem**: `search`, `get_observations` - Search past webhook fixes

## Memory Search (Before Starting)

**Always search memory first** to find past webhook failures and platform-specific issues.

### Recommended Searches

```
# Search for past webhook issues
search(query="webhook failure", project="bot-fb", type="bugfix", limit=5)

# Find LINE-specific problems
search(query="LINE webhook", project="bot-fb", concepts=["gotcha", "problem-solution"], limit=5)

# Search for Telegram issues
search(query="Telegram bot", project="bot-fb", type="bugfix", limit=5)

# Search for Facebook issues
search(query="Facebook webhook", project="bot-fb", type="bugfix", limit=5)
```

### Search by Scenario

| Scenario | Search Query |
|----------|--------------|
| Bot not responding | `search(query="bot not responding", project="bot-fb", type="bugfix", limit=5)` |
| Webhook signature fails | `search(query="webhook signature validation", project="bot-fb", concepts=["gotcha"], limit=5)` |
| Queue/job failures | `search(query="job queue failed", project="bot-fb", type="bugfix", limit=5)` |
| WebSocket issues | `search(query="Reverb Echo WebSocket", project="bot-fb", concepts=["problem-solution"], limit=5)` |

### Using Search Results

1. Run relevant searches based on the webhook issue
2. Use `get_observations(ids=[...])` for full details on past failures
3. Check if similar platform issues occurred before
4. Apply learnings to current debugging

## Message Flow

```
Platform (LINE/Telegram/Facebook)
    ↓
Webhook Endpoint (/api/webhook/{platform}/{bot_id})
    ↓
Platform-specific Controller → Validate signature
  (LINEWebhookController / TelegramWebhookController / FacebookWebhookController)
    ↓
Platform-specific Job (queued)
  (ProcessLINEWebhook / ProcessTelegramWebhook / ProcessFacebookWebhook)
    ↓
MessageProcessor Service
  (LINE uses LINEEventRouter → LINEMessageProcessor for event routing)
    ↓
AI Response (OpenRouter/Flow)
    ↓
Send Reply via ChannelAdapterFactory → Platform Adapter
    ↓
Platform (LINE/Telegram/Facebook)
```

### Architecture Notes

- **Webhook controllers** are split per platform in `app/Http/Controllers/Webhook/`
  - `LINEWebhookController.php`, `TelegramWebhookController.php`, `FacebookWebhookController.php`
- **Jobs** are split per platform in `app/Jobs/`
  - `ProcessLINEWebhook.php`, `ProcessTelegramWebhook.php`, `ProcessFacebookWebhook.php`
- **LINE event routing** is handled by `app/Services/Webhook/LINE/LINEEventRouter.php` and `LINEMessageProcessor.php`
- **Platform abstraction** via `app/Services/Channel/ChannelAdapterFactory.php` provides a unified interface for sending replies

## ProcessLINEWebhook Pipeline

`ProcessLINEWebhook.php` (1,383 lines, most-changed file) is the central LINE message processing job.

### Execution Flow

```
handle()
  → CircuitBreakerService.execute(processEvent, sendFallback)
    → TX1: lockForUpdate + save message (~50ms)
    → AI response generation (OUTSIDE transaction, 2-3s)
    → Flex detection + bubble splitting
    → Send reply via LINEService
    → Plugin execution (FlowPluginService)
```

### Dedup Stack

| Layer | Method | Purpose |
|-------|--------|---------|
| 1 | `webhook_event_id` | LINE best practice, first check |
| 2 | `external_message_id` | Fallback dedup per conversation |
| 3 | Retry recovery | If user msg exists but no bot response → retry AI |

### Transaction Splitting

Critical pattern: DB transaction holds lock for ~50ms only. AI response generation happens OUTSIDE the transaction to avoid blocking other webhook jobs for the same conversation.

```php
// TX1: Save message (fast, ~50ms)
DB::transaction(function () {
    $conversation = Conversation::lockForUpdate()->first();
    $userMessage = Message::create([...]);
});
// AI response (slow, outside lock)
$response = $aiService->generate(...);
```

## Message Aggregation Pipeline

When multiple messages arrive rapidly, they're batched before AI responds.

```
User sends msg1 → MessageAggregationService: start group, set timer
User sends msg2 → Reset timer (new UUID invalidates old delayed job)
User sends msg3 → Reset timer again
Timer expires   → ProcessAggregatedMessages job: merge & respond
```

### Key Services

| Service | Purpose |
|---------|---------|
| `SmartAggregationAnalyzer` | Adaptive wait time, early trigger detection (!, ?, ...) |
| `MessageAggregationService` | Cache-based grouping, timer reset via new UUID |
| `ProcessAggregatedMessages` | group_id verification, content merge, AI response |

### Early Trigger Detection

SmartAggregationAnalyzer detects message-ending signals to skip the wait:
- Complete sentences with Thai/English punctuation
- Single characters like `!`, `?`, `ครับ`, `ค่ะ`
- Configurable per-bot with min/max wait time bounds

## Vision & Sticker Routing

```
Image message → handleImageAnalysis()
  → Model capability gate (does current model support vision?)
    → YES: Vision API call with image
    → NO: Default text response
  → Same Flex detection + bubble splitting on response

Sticker message → handleStickerReply()
  → StickerReplyService
  → Friendly sticker-appropriate response
```

## Debug Steps

### 1. Check Webhook Arrival

```bash
# Check Railway logs for webhook hits
railway logs --filter "webhook"
```

```sql
-- Check recent messages in DB
SELECT * FROM messages
WHERE bot_id = $bot_id
ORDER BY created_at DESC
LIMIT 10;
```

### 2. Check Queue Status

```bash
# Check failed jobs
php artisan queue:failed

# Retry failed job
php artisan queue:retry {job_id}

# Check queue size
php artisan queue:monitor
```

### 3. Check Platform Credentials

```sql
-- Check bot credentials (masked)
SELECT id, platform,
       CASE WHEN access_token IS NOT NULL THEN '***set***' ELSE 'MISSING' END as token,
       CASE WHEN channel_secret IS NOT NULL THEN '***set***' ELSE 'MISSING' END as secret
FROM bots WHERE id = $bot_id;
```

## Platform-Specific Guides

| Platform | Guide | Key Issues |
|----------|-------|------------|
| LINE | [LINE_DEBUG.md](LINE_DEBUG.md) | Signature, tokens, Flex |
| Telegram | [TELEGRAM_DEBUG.md](TELEGRAM_DEBUG.md) | Webhook setup, bot token |
| Facebook | N/A | X-Hub-Signature-256, page token, app secret |
| WebSocket | [WEBSOCKET_DEBUG.md](WEBSOCKET_DEBUG.md) | Reverb, Echo auth |

## Flex Message Detection

Flex detection runs on the **full text before bubble splitting**. This is critical to understand when debugging missing Flex messages.

```
AI Response (full text)
    ↓
PaymentFlexService::tryConvertToFlex(fullText)  ← checks full text first
    ↓
If Flex detected → send as single Flex message (skip bubble splitting)
If no Flex match → MultipleBubblesService::parseIntoBubbles → transformBubbles (per-bubble Flex check)
```

**Key behavior** (`ProcessLINEWebhook` line ~497):
- Full text is checked first via `PaymentFlexService::tryConvertToFlex()`
- If it returns an array (Flex detected), the entire response is sent as a single Flex message
- If it returns a string (no Flex), normal bubble splitting takes over
- When bubbles are enabled, each individual bubble is also checked for Flex via `transformBubbles()`

**Detection order in PaymentFlexService** (most specific first):
1. Payment message (`isPaymentMessage`) - requires bank account + total keyword
2. SupportDelay (`isSupportDelayMessage`) - requires `[แจ้งเตือน Support]` tag
3. Terms message (`isTermsMessage`) - requires "ยอมรับ" + "ข้อตกลง"
4. Verify success (`isVerifySuccessMessage`) - requires "[ยืนยันชำระเงิน]" tag + amount
5. Confirm message (`isConfirmMessage`) - requires total + "ยืนยัน" (least specific, checked last)

**Note**: Markdown stripping (`str_replace('**', '', $text)`) runs before all regex detection.

**Image analysis path**: After vision AI responds, the same Flex detection applies to the response content. If bubbles are enabled, it uses `MultipleBubblesService`; otherwise, `PaymentFlexService::tryConvertToFlex()` runs directly on the response (`ProcessLINEWebhook` line ~1124).

## FlowPluginService Debugging

Plugins execute **after** bot message is sent. The flow is:

```
Bot sends reply → FlowPluginService::executePlugins()
                      ↓
                  Load enabled plugins for current flow
                      ↓
                  For each plugin:
                    1. Keyword pre-filter (cheap, no API call)
                    2. AI evaluation via gpt-4o-mini (triggered check)
                    3. Variable extraction + template formatting
                    4. Send Telegram notification
                    5. Record order from extracted data
```

### Keyword Pre-filter

The keyword pre-filter (`passesKeywordFilter`) is a cost-saving mechanism:
- Checks `plugin.config.trigger_keywords` array against bot message content
- If no keywords configured (empty array) → filter passes (backward compatible)
- If keywords exist → at least one must appear in `$botMessage->content` (case-insensitive, `mb_strtolower`)
- Only if keywords match does the system call the AI evaluation (saves API costs)

### Rate Limiting

Rate limiting applies **only on successful trigger**, not on all attempts:
- Cache key: `plugin_exec:{plugin_id}:{conversation_id}`
- Duration: 60 seconds
- Rate limit check happens **before** `evaluateAndExecute()`
- `Cache::put()` only runs when `$triggered === true` (after AI confirms)
- This means: keyword filter fail, AI "not triggered", or execution error do NOT consume rate limit

### Plugin Auto-Disable

Telegram plugins auto-disable on auth errors:
- HTTP 401, 403, 404 from Telegram API → `plugin.update(['enabled' => false])`
- Check `flow_plugins.enabled` column if plugin suddenly stops working

### Common Plugin Debug Patterns

```bash
# Check plugin execution logs
railway logs --filter "PLUGIN DEBUG"

# Check if keywords are filtering correctly
railway logs --filter "Plugin skipped: no keyword match"

# Check AI evaluation results
railway logs --filter "PLUGIN DEBUG: AI said"

# Check Telegram send status
railway logs --filter "PLUGIN DEBUG: Telegram"
```

```sql
-- List all plugins for a bot's flows
SELECT fp.id, fp.type, fp.name, fp.enabled, fp.trigger_condition,
       fp.config->>'trigger_keywords' as keywords,
       f.name as flow_name
FROM flow_plugins fp
JOIN flows f ON fp.flow_id = f.id
WHERE f.bot_id = {bot_id};

-- Check if plugin was auto-disabled
SELECT id, name, enabled, updated_at
FROM flow_plugins
WHERE enabled = false
ORDER BY updated_at DESC
LIMIT 10;
```

## Plugin Config Dialog Issues

The plugin config UI is in `frontend/src/components/flow/PluginSection.tsx`.

**Common issues:**
- Plugin requires `flowId` to exist first (flow must be saved before adding plugins)
- API endpoints: `GET/POST /bots/{botId}/flows/{flowId}/plugins`
- Trigger keywords are stored as comma-separated in the UI, converted to array on save
- `trigger_condition` is required (natural language description for AI evaluation)
- `config.access_token` and `config.chat_id` are required for Telegram type

**Config dialog fields:**
| Field | Required | Notes |
|-------|----------|-------|
| name | No | Display name for the plugin |
| trigger_condition | Yes | Natural language condition for AI |
| trigger_keywords | No | Comma-separated pre-filter keywords |
| access_token | Yes | Telegram Bot token |
| chat_id | Yes | Telegram user/group ID |
| message_template | Yes | Template with `{variable}` placeholders |

## Key Files

| File | Purpose |
|------|---------|
| `app/Http/Controllers/Webhook/LINEWebhookController.php` | LINE webhook handler |
| `app/Http/Controllers/Webhook/TelegramWebhookController.php` | Telegram webhook handler |
| `app/Http/Controllers/Webhook/FacebookWebhookController.php` | Facebook webhook handler |
| `app/Jobs/ProcessLINEWebhook.php` | LINE message processing job |
| `app/Jobs/ProcessTelegramWebhook.php` | Telegram message processing job |
| `app/Jobs/ProcessFacebookWebhook.php` | Facebook message processing job |
| `app/Services/Webhook/LINE/LINEEventRouter.php` | LINE event routing |
| `app/Services/Webhook/LINE/LINEMessageProcessor.php` | LINE message processing |
| `app/Services/Channel/ChannelAdapterFactory.php` | Platform abstraction layer |
| `app/Services/PaymentFlexService.php` | Flex message detection & building |
| `app/Services/MultipleBubblesService.php` | Bubble splitting & per-bubble Flex |
| `app/Services/FlowPluginService.php` | Plugin execution (keyword + AI eval) |
| `app/Services/LINEService.php` | LINE API integration |
| `app/Services/TelegramService.php` | Telegram API integration |
| `app/Services/FacebookService.php` | Facebook API integration |
| `config/broadcasting.php` | Reverb configuration |
| `frontend/src/components/flow/PluginSection.tsx` | Plugin config UI |

## Debug Output Format

```
🔗 Webhook Debug Report
━━━━━━━━━━━━━━━━━━━━━━━
Bot ID: [bot_id]
Platform: [LINE/Telegram/Facebook]
Message ID: [message_id]

📊 Flow Analysis:
1. Webhook: ✅/❌ [received at HH:MM:SS]
2. Signature: ✅/❌ [valid/invalid]
3. Job Dispatch: ✅/❌ [job_id]
4. AI Response: ✅/❌ [model used, tokens]
5. Reply Send: ✅/❌ [platform response]

🎯 Issue: [identified problem]

💡 Fix:
- [specific action]
```

## Quick Debug Checklist

| Issue | First Check | Command |
|-------|-------------|---------|
| Bot not responding | Railway logs | `railway logs --filter webhook` |
| Job stuck | Failed jobs | `php artisan queue:failed` |
| Signature fails | Channel secret | Check bot settings in DB |
| WebSocket fails | Reverb running | `php artisan reverb:start` |

## Concurrency Patterns

Race condition and locking patterns used in webhook processing.

### Decision Tree

```
Race Condition Type?
├─ DB State → lockForUpdate() in DB::transaction
├─ Unique Violation → catch UniqueConstraintViolationException + retry
├─ Cache/Memory State → Cache::lock with block timeout
├─ Long-Held Lock → Move API calls OUTSIDE DB::transaction
├─ Webhook Dedup → webhook_event_id → external_message_id
├─ Duplicate AI Response → Response cache lock ("ai_response:{conversation_id}")
├─ Service Failing → CircuitBreakerService (closed/open/half-open)
└─ API Rate Limited → HTTP retry with linear backoff
```

### Patterns Quick Reference

| # | Pattern | Use When |
|---|---------|----------|
| 1 | Pessimistic Lock (`lockForUpdate`) | Multiple jobs may create same record |
| 2 | Response Cache Lock | Multiple webhooks trigger AI for same conversation |
| 3 | Message Aggregation Lock (`Cache::lock`) | Rapid-fire messages need batching |
| 4 | Unique Constraint + Catch | INSERT may conflict with concurrent INSERT |
| 5 | Split Transactions | API call inside transaction (move outside) |
| 6 | Circuit Breaker (3-state) | External service unreliable |
| 7 | WebSocket Dedup (`X-Socket-ID`) | Frontend sends + listens for same message |
| 8 | SmartAggregation | Fine-tuning message batching behavior |

### Unique Constraints

| Table | Constraint | Purpose |
|-------|-----------|---------|
| customer_profiles | (external_id, channel_type) | Per-platform unique |
| messages | webhook_event_id (app-level) | Webhook dedup |
| messages | (conversation_id, external_message_id) | Message dedup |
| quick_replies | (user_id, shortcut) | Unique shortcuts |
| bot_settings | bot_id | One settings per bot |

### Critical Gotchas

| Gotcha | Detail |
|--------|--------|
| Lock duration | Keep transactions < 100ms, move API calls outside |
| Null vs empty | `hitl_dangerous_actions: null` = defaults, `[]` = allow all |
| Cache driver | Must use Redis/Memcached for Cache::lock (file driver won't work) |

## Gotchas

| Problem | Solution |
|---------|----------|
| Webhook 500 | Check Sentry for stacktrace |
| Signature fails | Verify secret in LINE Console |
| Job stuck | `php artisan queue:work` |
| Messages duplicated | Implement idempotency |
| Flex not showing | Check full text has required keywords before bubble split |
| Plugin not firing | Check keyword pre-filter matches bot response, not user message |
| Plugin rate limited | Rate limit is per plugin+conversation, 60s, only on success |
| Plugin auto-disabled | Telegram 401/403/404 auto-disables; fix token then re-enable |
