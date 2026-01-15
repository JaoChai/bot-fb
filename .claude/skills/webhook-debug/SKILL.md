---
name: webhook-debug
description: Webhook and messaging debugger for LINE, Telegram, and WebSocket. Traces message flow from platform to bot response, diagnoses job failures, queue issues, bot not responding. Use when messages don't arrive, webhooks fail with errors, real-time features (Reverb/Laravel Echo) don't work, or bot stops responding.
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

## Utility Scripts

- `scripts/trace_webhook.sh` - Trace webhook from logs
