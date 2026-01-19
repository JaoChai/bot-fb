---
id: telegram-002-bot-token-invalid
title: Telegram Bot Token Invalid
impact: HIGH
impactDescription: "All API calls fail, bot completely non-functional"
category: telegram
tags: [telegram, token, authentication, api]
relatedRules: [telegram-001-webhook-setup, queue-001-failed-jobs]
---

## Symptom

- All Telegram API calls return 401 Unauthorized
- Error: "Not Found" or "Unauthorized"
- Webhook receives updates but can't reply
- Bot info returns error

## Root Cause

1. Token revoked via @BotFather
2. Token copied incorrectly (spaces, newlines)
3. Token stored in wrong format
4. Environment variable not set
5. Token regenerated but not updated

## Diagnosis

### Quick Check

```bash
# Test token validity
curl "https://api.telegram.org/bot{TOKEN}/getMe"

# Expected: {"ok":true,"result":{"id":123456,"is_bot":true,"first_name":"MyBot"...}}
# Error: {"ok":false,"error_code":401,"description":"Unauthorized"}
```

### Detailed Analysis

```sql
-- Check bot token in database (should be encrypted)
SELECT
    id,
    name,
    platform,
    CASE
        WHEN telegram_token IS NOT NULL THEN 'SET'
        ELSE 'MISSING'
    END as token_status,
    updated_at
FROM bots
WHERE id = {bot_id} AND platform = 'telegram';
```

## Solution

### Fix Steps

1. **Get New Token from BotFather**
   - Open Telegram, search @BotFather
   - Send `/mybots`
   - Select your bot
   - Click "API Token" or `/token` to regenerate

2. **Update Token in Database**
```php
// Via tinker
$bot = Bot::find($botId);
$bot->telegram_token = 'new_token_here';
$bot->save();

// Token is automatically encrypted via cast
```

3. **Update Environment Variable**
```bash
# In Railway dashboard or .env
TELEGRAM_BOT_TOKEN=123456789:ABCdefGHIjklMNOpqrsTUVwxyz

# No quotes, no spaces
```

### Code Example

```php
// Good: Token validation on startup
class TelegramService
{
    public function validateToken(string $token): bool
    {
        try {
            $response = Http::timeout(5)->get(
                "https://api.telegram.org/bot{$token}/getMe"
            );

            if (!$response->json('ok')) {
                Log::error('Invalid Telegram token', [
                    'error' => $response->json('description'),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Telegram API unreachable', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendMessage(Bot $bot, int $chatId, string $text): bool
    {
        $token = $bot->telegram_token;

        if (!$token) {
            throw new TelegramException('Bot token not configured');
        }

        $response = Http::post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]
        );

        if (!$response->json('ok')) {
            $error = $response->json('description');

            if (str_contains($error, 'Unauthorized')) {
                Log::error('Telegram token invalid for bot', ['bot_id' => $bot->id]);
                // Notify admin
                event(new BotTokenInvalid($bot));
            }

            throw new TelegramException($error);
        }

        return true;
    }
}
```

## Prevention

- Validate token on bot creation
- Periodic token health checks
- Alert on consecutive 401 errors
- Store tokens encrypted
- Document token rotation process

## Debug Commands

```bash
# Full token validation
TOKEN="your_token"

echo "=== Validate Token ==="
curl -s "https://api.telegram.org/bot$TOKEN/getMe" | jq .

echo "=== Check Bot Permissions ==="
curl -s "https://api.telegram.org/bot$TOKEN/getMyCommands" | jq .

echo "=== Test Send Message ==="
curl -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" \
  -d "chat_id=YOUR_CHAT_ID" \
  -d "text=Test message"
```

## Project-Specific Notes

**BotFacebook Context:**
- Token stored in `bots.telegram_token` (encrypted via cast)
- Validation in `TelegramService::validateCredentials()`
- Token shown masked in admin UI
- Use `php artisan bot:validate-tokens` to check all bots
