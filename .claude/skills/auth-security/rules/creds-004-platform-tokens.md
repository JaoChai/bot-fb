---
id: creds-004-platform-tokens
title: Secure Platform Token Handling
impact: HIGH
impactDescription: "LINE/Telegram tokens leaked or mishandled"
category: creds
tags: [credentials, line, telegram, platform, tokens]
relatedRules: [creds-002-encrypt-credentials, webhook-001-signature-validation]
---

## Why This Matters

Platform tokens (LINE, Telegram) provide full access to bot functionality. Leaked tokens allow attackers to send messages as your bot and access user data.

## Threat Model

**Attack Vector:** Token in logs, error messages, or API responses
**Impact:** Full bot impersonation, user data access
**Likelihood:** Medium - tokens often accidentally logged

## Bad Example

```php
// Token in error message
public function sendMessage(Bot $bot, string $message)
{
    try {
        $this->lineClient->send($bot->access_token, $message);
    } catch (\Exception $e) {
        // Token leaked in error!
        throw new \Exception("Failed with token: {$bot->access_token}");
    }
}

// Token in logs
Log::info('Sending to LINE', [
    'bot_id' => $bot->id,
    'token' => $bot->access_token,  // Leaked!
]);

// Token in API response
return response()->json([
    'bot' => $bot,  // Includes access_token if not hidden
]);
```

**Why it's vulnerable:**
- Tokens in error tracking (Sentry)
- Tokens in log files
- Tokens in API responses
- Anyone with access can impersonate bot

## Good Example

```php
class LINEService
{
    public function sendMessage(Bot $bot, string $message): void
    {
        try {
            $this->client->withToken($bot->access_token)
                ->post('/message/push', [...]);
        } catch (\Exception $e) {
            // Never include token in error
            Log::error('LINE API failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
                // NO token here
            ]);

            throw new LINEException(
                "Failed to send message to bot {$bot->id}",
                $e->getCode(),
                $e
            );
        }
    }
}

// Bot model - tokens hidden
class Bot extends Model
{
    protected $hidden = [
        'access_token',
        'channel_secret',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'channel_secret' => 'encrypted',
    ];
}

// API Resource - explicit fields
class BotResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'platform' => $this->platform,
            'is_active' => $this->is_active,
            // Never include: access_token, channel_secret
        ];
    }
}

// Logging sanitizer
// config/logging.php
'tap' => [App\Logging\SanitizeSecrets::class],

// app/Logging/SanitizeSecrets.php
class SanitizeSecrets
{
    public function __invoke($logger): void
    {
        $logger->pushProcessor(function ($record) {
            $record['context'] = $this->sanitize($record['context']);
            return $record;
        });
    }

    private function sanitize(array $context): array
    {
        $sensitive = ['token', 'secret', 'password', 'key'];

        foreach ($context as $key => $value) {
            if (Str::contains(strtolower($key), $sensitive)) {
                $context[$key] = '[REDACTED]';
            }
        }

        return $context;
    }
}
```

**Why it's secure:**
- Tokens never in errors
- Tokens never in logs
- Tokens never in API responses
- Automatic sanitization

## Audit Command

```bash
# Check for token logging
grep -rn "access_token\|channel_secret" app/ --include="*.php" | grep -i "log\|error\|exception"

# Check Bot model hidden
grep -A 10 "protected \$hidden" app/Models/Bot.php

# Check API Resources
grep -rn "access_token\|channel_secret" app/Http/Resources/ --include="*.php"
```

## Project-Specific Notes

**BotFacebook Platform Services:**

```php
// LINEService - token handling
class LINEService
{
    public function __construct(
        private HttpClient $client,
        private Bot $bot
    ) {
        // Token only used internally
        $this->client->withToken($bot->access_token);
    }

    // All public methods never expose token
}

// TelegramService - same pattern
class TelegramService
{
    private string $token;

    public function __construct(Bot $bot)
    {
        $this->token = $bot->access_token;
        // Token stored privately
    }
}

// Error handling - no tokens
class LINEWebhookController extends Controller
{
    public function handle(Request $request, Bot $bot)
    {
        try {
            // Process...
        } catch (\Exception $e) {
            report($e);  // Sentry won't have token

            return response()->json([
                'error' => 'Webhook processing failed',
                // NO details that could leak
            ], 500);
        }
    }
}
```
