---
id: line-002-reply-token-expiry
title: LINE Reply Token Expired
impact: HIGH
impactDescription: "Bot processes message but cannot send reply"
category: line
tags: [line, reply-token, timeout, push-message]
relatedRules: [line-001-signature-validation, flow-004-response-timing]
---

## Symptom

- Message processed successfully
- AI generates response
- Reply fails with "Invalid reply token" error
- Works for some messages but not others

## Root Cause

LINE reply tokens expire after **30 seconds**. If processing takes longer:
- AI response generation too slow
- Queue processing delayed
- Multiple retries consuming time

## Diagnosis

### Quick Check

```bash
# Check for reply token errors in logs
railway logs --filter "reply token"

# Check processing time
railway logs --filter "ProcessIncomingMessage"
```

### Detailed Analysis

```sql
-- Check message processing times
SELECT
    id,
    created_at,
    processed_at,
    EXTRACT(EPOCH FROM (processed_at - created_at)) as processing_seconds
FROM messages
WHERE platform = 'line'
    AND processed_at IS NOT NULL
ORDER BY created_at DESC
LIMIT 20;
```

## Solution

### Fix Steps

1. **Optimize Processing Time**
```php
// Use faster model for simple queries
$model = $this->selectModel($message); // Use tier selection
```

2. **Use Push Message as Fallback**
```php
// In LineService
public function sendReply(string $replyToken, array $messages, string $userId): void
{
    try {
        $this->replyMessage($replyToken, $messages);
    } catch (InvalidReplyTokenException $e) {
        // Fallback to push message
        Log::warning('Reply token expired, using push message');
        $this->pushMessage($userId, $messages);
    }
}
```

3. **Send Typing Indicator First**
```php
// Acknowledge quickly, process async
public function handleWebhook(Request $request): Response
{
    // Dispatch job immediately
    dispatch(new ProcessLineMessage($event));

    // Return 200 fast (within 1 second)
    return response('OK', 200);
}
```

### Code Example

```php
// Good: Handle token expiry gracefully
class LineService
{
    public function sendMessage(LineEvent $event, string $content): void
    {
        $replyToken = $event->replyToken;
        $userId = $event->source->userId;

        try {
            // Try reply first (free)
            $this->client->replyMessage($replyToken, [
                new TextMessageBuilder($content)
            ]);
        } catch (LINEBotException $e) {
            if (str_contains($e->getMessage(), 'Invalid reply token')) {
                // Fallback to push (costs quota)
                $this->client->pushMessage($userId, [
                    new TextMessageBuilder($content)
                ]);
                Log::info('Used push message fallback', ['user_id' => $userId]);
            } else {
                throw $e;
            }
        }
    }
}
```

## Prevention

- Target <10 second processing time
- Use tiered models (Haiku for simple queries)
- Implement streaming response if supported
- Monitor processing time metrics
- Use push messages for async notifications

## Debug Commands

```bash
# Check average processing time
php artisan tinker
>>> Message::where('platform', 'line')
...   ->whereNotNull('processed_at')
...   ->selectRaw('AVG(EXTRACT(EPOCH FROM (processed_at - created_at))) as avg_time')
...   ->first();

# Check for slow AI responses
railway logs --filter "OpenRouter" | grep "took"
```

## Project-Specific Notes

**BotFacebook Context:**
- Reply token stored temporarily in `ProcessLINEWebhook` job
- Push message uses `LineService::pushMessage()`
- Quota tracked in `line_push_quota` table
- Free tier: 500 push messages/month
