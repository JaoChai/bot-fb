# Telegram Webhook Debugging

## Webhook Flow

```
Telegram → Webhook URL → Process Update → Send Response
```

## Setup Verification

### 1. Set Webhook

```bash
# Set webhook URL
curl -X POST "https://api.telegram.org/bot{TOKEN}/setWebhook" \
  -d "url=https://api.botjao.com/api/webhook/telegram/{bot_id}"
```

### 2. Check Webhook Status

```bash
curl "https://api.telegram.org/bot{TOKEN}/getWebhookInfo"
```

Expected response:
```json
{
  "ok": true,
  "result": {
    "url": "https://api.botjao.com/api/webhook/telegram/{bot_id}",
    "has_custom_certificate": false,
    "pending_update_count": 0,
    "last_error_date": null,
    "last_error_message": null,
    "max_connections": 40
  }
}
```

### 3. Verify Bot Token

```bash
curl "https://api.telegram.org/bot{TOKEN}/getMe"
```

## Update Types

### Text Message

```json
{
  "update_id": 123456789,
  "message": {
    "message_id": 1,
    "from": {
      "id": 123456,
      "first_name": "John",
      "username": "john_doe"
    },
    "chat": {
      "id": 123456,
      "type": "private"
    },
    "date": 1705234567,
    "text": "Hello"
  }
}
```

### Callback Query (Button Click)

```json
{
  "update_id": 123456790,
  "callback_query": {
    "id": "abc123",
    "from": { "id": 123456 },
    "message": { "message_id": 1 },
    "data": "action=buy&item=123"
  }
}
```

## Sending Messages

### Text Message

```php
$telegram->sendMessage([
    'chat_id' => $chatId,
    'text' => 'สวัสดีครับ!',
    'parse_mode' => 'HTML',
]);
```

### With Inline Keyboard

```php
$telegram->sendMessage([
    'chat_id' => $chatId,
    'text' => 'เลือกสินค้า:',
    'reply_markup' => json_encode([
        'inline_keyboard' => [
            [
                ['text' => 'สินค้า A', 'callback_data' => 'item=a'],
                ['text' => 'สินค้า B', 'callback_data' => 'item=b'],
            ]
        ]
    ])
]);
```

### Photo with Caption

```php
$telegram->sendPhoto([
    'chat_id' => $chatId,
    'photo' => 'https://example.com/image.jpg',
    'caption' => 'Product Image',
]);
```

## Common Errors

### 401 Unauthorized

```json
{"ok": false, "error_code": 401, "description": "Unauthorized"}
```

**Cause:** Invalid bot token

**Fix:**
1. Check token in BotFather
2. Update in bot settings

### 400 Bad Request

```json
{"ok": false, "error_code": 400, "description": "Bad Request: chat not found"}
```

**Causes:**
- User blocked the bot
- Invalid chat_id
- User hasn't started conversation

**Fix:**
- Verify chat_id
- User must /start first

### 409 Conflict

```json
{"ok": false, "error_code": 409, "description": "Conflict: terminated by other getUpdates request"}
```

**Cause:** Multiple webhook handlers or polling conflict

**Fix:**
- Remove duplicate webhooks
- Stop any polling scripts
- Delete webhook and re-set

### 429 Too Many Requests

```json
{"ok": false, "error_code": 429, "parameters": {"retry_after": 60}}
```

**Limits:**
- 30 messages/second overall
- 1 message/second per chat
- 20 messages/minute in groups

**Fix:**
- Implement rate limiting
- Queue messages
- Respect retry_after

## Webhook vs Polling

| Feature | Webhook | Polling |
|---------|---------|---------|
| Latency | Low | Depends on interval |
| Server load | Lower | Higher |
| Setup | HTTPS required | Simple |
| Reliability | Depends on server | More control |

### Switch to Polling (Debug)

```bash
# Delete webhook
curl "https://api.telegram.org/bot{TOKEN}/deleteWebhook"

# Start polling
while true; do
  curl "https://api.telegram.org/bot{TOKEN}/getUpdates?offset=$OFFSET"
  sleep 1
done
```

### Switch Back to Webhook

```bash
curl -X POST "https://api.telegram.org/bot{TOKEN}/setWebhook" \
  -d "url=https://api.botjao.com/api/webhook/telegram/{bot_id}"
```

## Debugging Commands

### Check Recent Messages

```sql
SELECT m.id, m.content, m.direction, m.created_at,
       c.platform_user_id
FROM messages m
JOIN conversations c ON m.conversation_id = c.id
WHERE c.bot_id = {bot_id}
  AND c.platform = 'telegram'
ORDER BY m.created_at DESC
LIMIT 20;
```

### Test Send Message

```bash
curl -X POST "https://api.telegram.org/bot{TOKEN}/sendMessage" \
  -H "Content-Type: application/json" \
  -d '{"chat_id": "123456", "text": "Test message"}'
```

### Get Chat Info

```bash
curl "https://api.telegram.org/bot{TOKEN}/getChat?chat_id=123456"
```

## Error Handling

### Graceful Degradation

```php
public function sendMessage(int $chatId, string $text): bool
{
    try {
        $response = $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
        return $response->isOk();
    } catch (\Exception $e) {
        Log::error('Telegram send failed', [
            'chat_id' => $chatId,
            'error' => $e->getMessage(),
        ]);

        // Handle specific errors
        if (str_contains($e->getMessage(), 'blocked')) {
            // Mark user as blocked
            $this->markUserBlocked($chatId);
        }

        return false;
    }
}
```

## Checklist

- [ ] Bot token is valid (test with getMe)
- [ ] Webhook URL uses HTTPS
- [ ] Webhook set correctly (check getWebhookInfo)
- [ ] No pending_update_count buildup
- [ ] No last_error_message
- [ ] Server responds within 60 seconds
- [ ] Rate limiting implemented
