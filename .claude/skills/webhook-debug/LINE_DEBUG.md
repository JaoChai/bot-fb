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

### Flex Detection Flow

Flex detection happens on **full text before bubble splitting**. This is the critical path:

```
Bot response (full text)
    ↓
PaymentFlexService::tryConvertToFlex(fullText)
    ↓
┌── Flex detected (returns array) → send as single Flex message
│                                   (skip bubble splitting entirely)
└── No Flex (returns string) → MultipleBubblesService flow
                                    ↓
                              parseIntoBubbles()
                                    ↓
                              transformBubbles() → per-bubble tryConvertToFlex()
```

**Detection priority order** (in `PaymentFlexService::tryConvertToFlex`):
1. `isPaymentMessage()` - bank account number + total keyword ("รวมยอดโอน", "สรุปยอด")
2. `isTermsMessage()` - "ยอมรับ" + "ข้อตกลง" (exclusion: no bank account, no "เงินเข้าแล้ว")
3. `isVerifySuccessMessage()` - "[ยืนยันชำระเงิน]" tag + "เงินเข้าแล้ว" + amount
4. `isConfirmMessage()` - total pattern + "ยืนยัน" (exclusions: no bank account, no "เงินเข้าแล้ว", no "ข้อตกลง")

**Why detection order matters**: Each detector excludes the others' keywords. If Flex isn't triggering, check that the AI response contains the exact required keywords.

### Image Analysis Path

Image analysis (`handleImageAnalysis`) also runs Flex detection on the vision AI response:
- If bubbles enabled: `MultipleBubblesService` flow (includes per-bubble Flex)
- If bubbles disabled: direct `PaymentFlexService::tryConvertToFlex()` on full response
- Plugin execution also runs after image analysis (same as text path)

### Validate Flex JSON

```bash
# Use LINE Flex Message Simulator
# https://developers.line.biz/flex-simulator/
```

### Common Flex Errors

| Error | Cause | Fix |
|-------|-------|-----|
| Invalid flex | Malformed JSON | Validate in simulator |
| Too large | >30KB in code (MAX_FLEX_SIZE) | Reduce content |
| Missing altText | Required field | Add altText property |
| Flex not triggering | Missing keywords in AI response | Check detection conditions above |
| Wrong Flex type | Step overlap (e.g., confirm vs payment) | Check exclusion conditions |

### Size Limit

The project uses `MAX_FLEX_SIZE = 30000` bytes (30KB) in `PaymentFlexService`, which is more conservative than LINE's 50KB limit. If Flex exceeds this, `safeBuildFlex()` returns the original text as fallback.

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

## Plugin Debugging (FlowPluginService)

Plugins execute after the bot sends its reply. They are configured per-flow.

### Execution Flow

```
Bot message sent to LINE
    ↓
FlowPluginService::executePlugins(bot, conversation, botMessage)
    ↓
For each enabled plugin in the flow:
    1. Rate limit check (1 per plugin per conversation per 60s)
    2. Keyword pre-filter on botMessage.content (cheap, no API)
    3. AI evaluation via gpt-4o-mini (trigger condition check)
    4. Variable extraction from AI + regex fallback
    5. Template formatting with {variables}
    6. Send Telegram notification
    7. Record order via OrderService
```

### Keyword Pre-filter

- Configured via `plugin.config.trigger_keywords` (array of strings)
- If empty/not set: filter always passes (backward compatible)
- Checks against `mb_strtolower($botMessage->content)` using `mb_strpos`
- Any single keyword match = pass filter
- No match = skip AI evaluation entirely (saves API cost)

### Rate Limiting Behavior

Rate limit applies **only after successful trigger**:
- Cache key: `plugin_exec:{plugin_id}:{conversation_id}`, TTL 60s
- `Cache::has()` check runs before `evaluateAndExecute()`
- `Cache::put()` runs only when AI says `triggered: true`
- Failed keyword filter, AI rejection, or errors do NOT consume rate limit

### Plugin on Image Analysis Path

When a user sends an image:
1. Vision AI analyzes the image and generates a response
2. Response is sent back to LINE (with Flex detection)
3. Plugin execution runs on the bot's response message (same as text path)
4. This means slip verification responses can trigger order notification plugins

### Common Plugin Issues

| Issue | Debug Command | Fix |
|-------|--------------|-----|
| Plugin not firing | `railway logs --filter "PLUGIN DEBUG: Keyword filter"` | Check keywords match bot response |
| AI says not triggered | `railway logs --filter "PLUGIN DEBUG: AI said NOT"` | Adjust trigger_condition wording |
| Rate limited | `railway logs --filter "Plugin rate limited"` | Wait 60s or check conversation_id |
| Telegram send fails | `railway logs --filter "PLUGIN DEBUG: Telegram FAILED"` | Check token/chat_id in config |
| Plugin auto-disabled | Check `flow_plugins.enabled = false` | Re-enable after fixing token/chat_id |
| Variables not extracted | `railway logs --filter "Plugin unreplaced"` | Check template {variable} names |

### Plugin Config Dialog (Frontend)

The config UI is at `frontend/src/components/flow/PluginSection.tsx`.

**Prerequisites:**
- Flow must be saved first (flowId required)
- API: `GET/POST /bots/{botId}/flows/{flowId}/plugins`

**Common frontend issues:**
- "กรุณาบันทึก Flow ก่อน" error: Flow not yet saved, no flowId
- Keywords appear wrong after save: input is comma-separated, stored as array
- Toggle not working: optimistic update in state, check network for actual error

## Checklist

- [ ] Webhook URL set correctly in LINE Console
- [ ] Channel secret matches bot settings
- [ ] Channel access token is valid
- [ ] Auto-reply disabled in LINE Console
- [ ] Webhook events enabled (message, postback)
- [ ] Server can reach LINE API (no firewall)
- [ ] Reply within 30 seconds (or use Push)
- [ ] Flex detection keywords present in AI response (if expecting Flex)
- [ ] Plugin trigger_keywords match bot response content (if using plugins)
- [ ] Plugin Telegram token and chat_id valid (if plugin auto-disabled)
