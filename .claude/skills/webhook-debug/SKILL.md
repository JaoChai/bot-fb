---
name: webhook-debug
description: |
  Webhook and messaging debugger for LINE, Telegram, and WebSocket.
  Triggers: 'webhook', 'bot not responding', 'message not arriving', 'queue failed', 'WebSocket', 'Echo'.
  Use when: messages don't arrive, webhooks fail, real-time features don't work, bot stops responding.
allowed-tools:
  - Bash(php artisan queue*)
  - Bash(curl*)
  - Read
  - Grep
context:
  - path: app/Http/Controllers/WebhookController.php
  - path: app/Jobs/ProcessIncomingMessage.php
  - path: config/broadcasting.php
---

# Webhook & Messaging Debugger

Debug LINE, Telegram webhooks และ real-time messaging.

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
Platform (LINE/Telegram)
    ↓
Webhook Endpoint (/api/webhook/{platform}/{bot_id})
    ↓
WebhookController → Validate signature
    ↓
ProcessIncomingMessage Job (queued)
    ↓
MessageProcessor Service
    ↓
AI Response (OpenRouter/Flow)
    ↓
Send Reply via Platform API
    ↓
Platform (LINE/Telegram)
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
| WebSocket | [WEBSOCKET_DEBUG.md](WEBSOCKET_DEBUG.md) | Reverb, Echo auth |

## Key Files

| File | Purpose |
|------|---------|
| `app/Http/Controllers/WebhookController.php` | Webhook handler |
| `app/Jobs/ProcessIncomingMessage.php` | Message processing job |
| `app/Services/LineService.php` | LINE API integration |
| `app/Services/TelegramService.php` | Telegram API integration |
| `config/broadcasting.php` | Reverb configuration |

## Debug Output Format

```
🔗 Webhook Debug Report
━━━━━━━━━━━━━━━━━━━━━━━
Bot ID: [bot_id]
Platform: [LINE/Telegram]
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

## Gotchas

| Problem | Solution |
|---------|----------|
| Webhook 500 | Check Sentry for stacktrace |
| Signature fails | Verify secret in LINE Console |
| Job stuck | `php artisan queue:work` |
| Messages duplicated | Implement idempotency |
