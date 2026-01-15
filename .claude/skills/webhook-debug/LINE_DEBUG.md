# LINE Webhook Debugging

## Webhook Flow

```
LINE Platform → Webhook URL → Signature Validation → Process Message → Reply
```

## Setup Verification

### 1. Check Webhook URL

```bash
# Verify webhook is accessible
curl -X POST https://api.botjao.com/api/webhook/line/{bot_id} \
  -H "Content-Type: application/json" \
  -d '{"events":[]}'
```

Expected: 200 OK (signature validation may fail, but endpoint responds)

### 2. Verify Credentials

```sql
-- Check bot has LINE credentials
SELECT id, name, platform,
       CASE WHEN channel_access_token IS NOT NULL THEN 'SET' ELSE 'MISSING' END as token,
       CASE WHEN channel_secret IS NOT NULL THEN 'SET' ELSE 'MISSING' END as secret
FROM bots
WHERE id = {bot_id} AND platform = 'line';
```

### 3. LINE Console Settings

- Webhook URL: `https://api.botjao.com/api/webhook/line/{bot_id}`
- Use webhook: ✅ Enabled
- Auto-reply messages: ❌ Disabled (we handle replies)
- Greeting messages: ❌ Disabled

## Signature Validation

### How It Works

```php
// LINE sends X-Line-Signature header
$signature = $request->header('X-Line-Signature');

// Compute expected signature
$body = $request->getContent();
$hash = base64_encode(hash_hmac('sha256', $body, $channelSecret, true));

// Compare
if ($signature !== $hash) {
    return response('Invalid signature', 403);
}
```

### Common Signature Issues

| Issue | Cause | Fix |
|-------|-------|-----|
| Always invalid | Wrong channel secret | Update in bot settings |
| Intermittent | Request body modified | Check middleware order |
| Works locally, fails prod | Environment variable | Check Railway env vars |

### Debug Signature

```php
// Log for debugging (remove in production!)
Log::debug('LINE Signature Debug', [
    'received' => $signature,
    'computed' => $hash,
    'body_length' => strlen($body),
    'secret_set' => !empty($channelSecret),
]);
```

## Message Types

### Text Message

```json
{
  "type": "message",
  "message": {
    "type": "text",
    "id": "123456789",
    "text": "สวัสดีครับ"
  },
  "replyToken": "abc123...",
  "source": {
    "type": "user",
    "userId": "U1234..."
  }
}
```

### Image Message

```json
{
  "type": "message",
  "message": {
    "type": "image",
    "id": "123456789",
    "contentProvider": {
      "type": "line"
    }
  }
}
```

### Postback (from Flex/Rich Menu)

```json
{
  "type": "postback",
  "postback": {
    "data": "action=buy&itemId=123"
  },
  "replyToken": "abc123..."
}
```

## Sending Replies

### Reply Token Limitations

- Valid for ~30 seconds only
- Can be used only once
- If expired, use Push Message instead

### Text Reply

```php
$client->replyMessage([
    'replyToken' => $replyToken,
    'messages' => [
        ['type' => 'text', 'text' => 'สวัสดีครับ!']
    ]
]);
```

### Flex Message Reply

```php
$client->replyMessage([
    'replyToken' => $replyToken,
    'messages' => [
        [
            'type' => 'flex',
            'altText' => 'Product Information',
            'contents' => $flexJson
        ]
    ]
]);
```

## Common Errors

### 401 Unauthorized

```json
{"message": "Authentication failed"}
```

**Causes:**
- Invalid channel access token
- Token expired

**Fix:**
1. Go to LINE Developers Console
2. Messaging API > Channel access token
3. Issue new token
4. Update in bot settings

### 400 Bad Request

```json
{"message": "Invalid reply token"}
```

**Causes:**
- Reply token expired (>30s)
- Reply token already used

**Fix:**
- Use Push Message API instead
- Process messages faster

### 403 Forbidden

```json
{"message": "Not authorized to use this API"}
```

**Causes:**
- Push API not enabled
- Plan limitations

**Fix:**
- Check LINE Official Account plan
- Enable Push API in settings

### 429 Rate Limited

```json
{"message": "Rate limit exceeded"}
```

**Limits:**
- Reply: Unlimited
- Push: 500/min per user
- Multicast: 500/min

**Fix:**
- Implement rate limiting
- Queue messages
- Use Reply instead of Push when possible

## Debugging Commands

### Check Recent Webhooks

```sql
-- Recent LINE messages
SELECT m.id, m.content, m.direction, m.created_at,
       c.platform_user_id
FROM messages m
JOIN conversations c ON m.conversation_id = c.id
WHERE c.bot_id = {bot_id}
  AND c.platform = 'line'
ORDER BY m.created_at DESC
LIMIT 20;
```

### Check Failed Jobs

```bash
# View failed webhook processing jobs
php artisan queue:failed --queue=webhooks

# Retry specific job
php artisan queue:retry {job_id}
```

### Live Log Monitoring

```bash
# Railway logs for LINE webhooks
railway logs --filter "line" --lines 100
```

## Flex Message Debugging

### Validate Flex JSON

```bash
# Use LINE Flex Message Simulator
# https://developers.line.biz/flex-simulator/
```

### Common Flex Errors

| Error | Cause | Fix |
|-------|-------|-----|
| Invalid flex | Malformed JSON | Validate in simulator |
| Too large | >50KB message | Reduce content |
| Missing altText | Required field | Add altText property |

### Flex Template

```json
{
  "type": "bubble",
  "body": {
    "type": "box",
    "layout": "vertical",
    "contents": [
      {
        "type": "text",
        "text": "Hello",
        "weight": "bold",
        "size": "xl"
      }
    ]
  },
  "footer": {
    "type": "box",
    "layout": "horizontal",
    "contents": [
      {
        "type": "button",
        "action": {
          "type": "postback",
          "label": "Buy",
          "data": "action=buy"
        }
      }
    ]
  }
}
```

## Checklist

- [ ] Webhook URL set correctly in LINE Console
- [ ] Channel secret matches bot settings
- [ ] Channel access token is valid
- [ ] Auto-reply disabled in LINE Console
- [ ] Webhook events enabled (message, postback)
- [ ] Server can reach LINE API (no firewall)
- [ ] Reply within 30 seconds (or use Push)
