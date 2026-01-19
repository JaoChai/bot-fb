---
id: telegram-001-webhook-setup
title: Telegram Webhook Not Receiving Updates
impact: CRITICAL
impactDescription: "Bot receives no messages at all"
category: telegram
tags: [telegram, webhook, setup, configuration]
relatedRules: [telegram-002-bot-token-invalid, flow-001-message-tracing]
---

## Symptom

- Bot doesn't respond to any messages
- `getWebhookInfo` shows no pending updates
- Webhook URL not set or incorrect
- SSL certificate errors

## Root Cause

1. Webhook URL not registered with Telegram
2. HTTPS not properly configured
3. Self-signed certificate not uploaded
4. Webhook URL returns non-200 status
5. Port not allowed (only 443, 80, 88, 8443)

## Diagnosis

### Quick Check

```bash
# Check current webhook info
curl "https://api.telegram.org/bot{TOKEN}/getWebhookInfo"

# Expected response:
# {
#   "ok": true,
#   "result": {
#     "url": "https://api.botjao.com/api/webhook/telegram/{bot_id}",
#     "has_custom_certificate": false,
#     "pending_update_count": 0,
#     "last_error_date": null,
#     "last_error_message": null
#   }
# }
```

### Detailed Analysis

```bash
# Check for webhook errors
curl "https://api.telegram.org/bot{TOKEN}/getWebhookInfo" | jq '.result | {url, pending_update_count, last_error_date, last_error_message}'

# If last_error_message exists, it tells you exactly what's wrong
```

## Solution

### Fix Steps

1. **Set Webhook URL**
```bash
curl -X POST "https://api.telegram.org/bot{TOKEN}/setWebhook" \
  -d "url=https://api.botjao.com/api/webhook/telegram/{bot_id}"
```

2. **Verify SSL Certificate**
```bash
# Check SSL is valid
openssl s_client -connect api.botjao.com:443 -servername api.botjao.com

# Railway handles SSL automatically, but verify:
curl -I https://api.botjao.com/api/webhook/telegram/test
```

3. **Delete and Re-set Webhook**
```bash
# Delete existing webhook
curl -X POST "https://api.telegram.org/bot{TOKEN}/deleteWebhook"

# Set new webhook
curl -X POST "https://api.telegram.org/bot{TOKEN}/setWebhook" \
  -d "url=https://api.botjao.com/api/webhook/telegram/{bot_id}" \
  -d "drop_pending_updates=true"
```

### Code Example

```php
// Good: Programmatic webhook setup
class TelegramWebhookService
{
    public function setupWebhook(Bot $bot): bool
    {
        $webhookUrl = config('app.url') . "/api/webhook/telegram/{$bot->id}";

        $response = Http::post(
            "https://api.telegram.org/bot{$bot->telegram_token}/setWebhook",
            [
                'url' => $webhookUrl,
                'allowed_updates' => ['message', 'callback_query', 'inline_query'],
                'drop_pending_updates' => false,
            ]
        );

        if (!$response->json('ok')) {
            Log::error('Failed to set Telegram webhook', [
                'bot_id' => $bot->id,
                'error' => $response->json('description'),
            ]);
            return false;
        }

        $bot->update(['webhook_url' => $webhookUrl]);
        return true;
    }

    public function getWebhookInfo(Bot $bot): array
    {
        $response = Http::get(
            "https://api.telegram.org/bot{$bot->telegram_token}/getWebhookInfo"
        );

        return $response->json('result', []);
    }
}
```

## Prevention

- Set webhook on bot creation
- Verify webhook after deployment
- Monitor `pending_update_count`
- Set up health check for webhook endpoint
- Use `drop_pending_updates=true` if queue is stuck

## Debug Commands

```bash
# Full webhook diagnostic
TOKEN="your_bot_token"
BOT_ID="your_bot_id"

echo "=== Webhook Info ==="
curl -s "https://api.telegram.org/bot$TOKEN/getWebhookInfo" | jq .

echo "=== Test Endpoint ==="
curl -I "https://api.botjao.com/api/webhook/telegram/$BOT_ID"

echo "=== Bot Info ==="
curl -s "https://api.telegram.org/bot$TOKEN/getMe" | jq .

# Clear stuck updates
curl -X POST "https://api.telegram.org/bot$TOKEN/setWebhook" \
  -d "url=https://api.botjao.com/api/webhook/telegram/$BOT_ID" \
  -d "drop_pending_updates=true"
```

## Project-Specific Notes

**BotFacebook Context:**
- Webhook endpoint: `app/Http/Controllers/Webhook/TelegramWebhookController.php`
- Webhook setup in `app/Services/TelegramService.php`
- Token stored encrypted in `bots.telegram_token`
- Auto-setup webhook on bot creation via `BotObserver`
