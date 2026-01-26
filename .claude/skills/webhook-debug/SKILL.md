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

## LINE Specific

### Webhook Signature Validation

```php
// Signature must match
$signature = $request->header('X-Line-Signature');
$body = $request->getContent();
$hash = base64_encode(hash_hmac('sha256', $body, $channelSecret, true));
```

### Common LINE Errors

| Error | Cause | Fix |
|-------|-------|-----|
| 401 Unauthorized | Invalid channel access token | Refresh token in LINE Console |
| 403 Forbidden | IP not whitelisted | Add server IP to LINE whitelist |
| 429 Rate Limited | Too many requests | Implement rate limiting |

### Flex Message Debugging

```php
// Validate Flex JSON before sending
$validator = new FlexMessageValidator();
$errors = $validator->validate($flexJson);
```

## Telegram Specific

### Webhook Setup

```bash
# Set webhook URL
curl -X POST "https://api.telegram.org/bot{TOKEN}/setWebhook" \
  -d "url=https://api.botjao.com/api/webhook/telegram/{bot_id}"

# Check webhook info
curl "https://api.telegram.org/bot{TOKEN}/getWebhookInfo"
```

### Common Telegram Errors

| Error | Cause | Fix |
|-------|-------|-----|
| 401 Unauthorized | Invalid bot token | Check TELEGRAM_BOT_TOKEN |
| 409 Conflict | Multiple webhook handlers | Only one webhook URL allowed |
| 400 Bad Request | Invalid message format | Validate message structure |

## WebSocket (Reverb/Echo)

### Check Connection

```javascript
// In browser console
Echo.connector.pusher.connection.state // Should be 'connected'
```

### Common Issues

| Issue | Cause | Fix |
|-------|-------|-----|
| Connection drops | Server timeout | Increase `ping_interval` |
| Events not received | Wrong channel name | Check channel format |
| Auth failure | Invalid token | Refresh auth token |

### Debug Broadcast

```php
// Force broadcast for testing
broadcast(new MessageReceived($message))->toOthers();

// Check if event fired
Log::info('Broadcasting message', ['message_id' => $message->id]);
```

## Detailed Guides

- **LINE Debugging**: See [LINE_DEBUG.md](LINE_DEBUG.md)
- **Telegram Debugging**: See [TELEGRAM_DEBUG.md](TELEGRAM_DEBUG.md)
- **WebSocket Debugging**: See [WEBSOCKET_DEBUG.md](WEBSOCKET_DEBUG.md)

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

## Common Tasks

### Debug Bot Not Responding

```markdown
1. Check Railway logs for webhook hits
2. Check failed jobs: `php artisan queue:failed`
3. Verify bot credentials in database
4. Test webhook manually with curl
5. Check AI service response
6. Verify reply API call
```

### Verify LINE Webhook

```markdown
1. Get webhook URL: `https://api.botjao.com/api/webhook/line/{bot_id}`
2. Set URL in LINE Console
3. Test with LINE verification request
4. Check signature validation in logs
5. Send test message from LINE app
```

### Debug WebSocket Connection

```markdown
1. Check Reverb is running: `php artisan reverb:start`
2. Check browser console for connection state
3. Verify auth token is valid
4. Check channel subscription
5. Test broadcast manually
```

## Gotchas

| Problem | Cause | Solution |
|---------|-------|----------|
| Webhook returns 500 | Unhandled exception | Check Sentry/logs for stacktrace |
| Signature validation fails | Wrong channel secret | Verify secret matches LINE Console |
| Job stuck in queue | Worker not running | Start worker: `php artisan queue:work` |
| Telegram webhook not set | Missing setWebhook call | Run `curl` to set webhook URL |
| Echo not connecting | CORS or auth issue | Check broadcasting.php config |
| Messages duplicated | Webhook retry | Implement idempotency check |
| Slow response | Job queue backed up | Scale workers or increase timeout |

## Utility Scripts

- `scripts/trace_webhook.sh` - Trace webhook from logs
