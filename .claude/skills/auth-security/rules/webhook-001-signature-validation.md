---
id: webhook-001-signature-validation
title: Validate Webhook Signatures
impact: CRITICAL
impactDescription: "Attackers can forge webhook requests"
category: webhook
tags: [webhook, signature, line, telegram, security]
relatedRules: [webhook-002-ip-whitelist, webhook-003-replay-prevention]
---

## Why This Matters

Without signature validation, anyone can send fake webhook requests to your endpoint, impersonating LINE/Telegram and injecting malicious messages.

## Threat Model

**Attack Vector:** Forged webhook requests
**Impact:** Fake messages processed, bot impersonation, data injection
**Likelihood:** High - webhook URLs are often discoverable

## Bad Example

```php
// No signature validation!
public function handleLineWebhook(Request $request, Bot $bot)
{
    $events = $request->input('events');

    foreach ($events as $event) {
        // Processing without verification!
        $this->processEvent($event, $bot);
    }

    return response('OK');
}

// Signature check but wrong implementation
public function handleWebhook(Request $request, Bot $bot)
{
    $signature = $request->header('X-Line-Signature');

    // Using == instead of hash_equals (timing attack)
    if ($signature == $this->calculateSignature($request, $bot)) {
        $this->process($request);
    }
}
```

**Why it's vulnerable:**
- No verification = anyone can send events
- Timing attacks on string comparison
- Bot could process malicious commands
- Data integrity compromised

## Good Example

```php
// Middleware for signature validation
class ValidateLINESignature
{
    public function handle(Request $request, Closure $next, string $botId)
    {
        $bot = Bot::findOrFail($botId);

        $signature = $request->header('X-Line-Signature');
        $body = $request->getContent();

        if (!$this->isValidSignature($signature, $body, $bot->channel_secret)) {
            Log::warning('Invalid LINE signature', [
                'bot_id' => $bot->id,
                'ip' => $request->ip(),
            ]);

            // Return 200 to not reveal failure
            return response('OK', 200);
        }

        return $next($request);
    }

    private function isValidSignature(?string $signature, string $body, string $secret): bool
    {
        if (empty($signature)) {
            return false;
        }

        $expectedSignature = base64_encode(
            hash_hmac('sha256', $body, $secret, true)
        );

        // Timing-safe comparison
        return hash_equals($expectedSignature, $signature);
    }
}

// Controller
class LINEWebhookController extends Controller
{
    public function handle(Request $request, Bot $bot)
    {
        // Signature already validated by middleware

        $events = $request->input('events', []);

        foreach ($events as $event) {
            ProcessLINEWebhook::dispatch($bot, $event);
        }

        return response('OK', 200);
    }
}

// Telegram signature validation
class ValidateTelegramSignature
{
    public function handle(Request $request, Closure $next, string $botId)
    {
        $bot = Bot::findOrFail($botId);

        // Telegram uses secret_token header
        $token = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (!hash_equals($bot->webhook_secret ?? '', $token ?? '')) {
            Log::warning('Invalid Telegram signature', [
                'bot_id' => $bot->id,
            ]);

            return response('OK', 200);
        }

        return $next($request);
    }
}

// routes/api.php
Route::post('/webhook/line/{bot}', [LINEWebhookController::class, 'handle'])
    ->middleware(['webhook.line']);

Route::post('/webhook/telegram/{bot}', [TelegramWebhookController::class, 'handle'])
    ->middleware(['webhook.telegram']);
```

**Why it's secure:**
- HMAC signature verification
- Timing-safe comparison
- Failed attempts logged
- Silent failure (200 response)

## Audit Command

```bash
# Check for signature validation
grep -rn "X-Line-Signature\|hash_hmac\|hash_equals" app/ --include="*.php"

# Check webhook routes for middleware
php artisan route:list | grep webhook

# Check for timing-safe comparison
grep -rn "hash_equals" app/ --include="*.php"
```

## Project-Specific Notes

**BotFacebook Webhook Security:**

```php
// app/Http/Middleware/ValidateLINESignature.php
class ValidateLINESignature
{
    public function handle(Request $request, Closure $next)
    {
        $botId = $request->route('bot');
        $bot = Bot::find($botId);

        if (!$bot || !$this->validateSignature($request, $bot)) {
            // Log but return 200 to not reveal failure
            Log::warning('LINE webhook signature validation failed', [
                'bot_id' => $botId,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response('OK', 200);
        }

        return $next($request);
    }

    private function validateSignature(Request $request, Bot $bot): bool
    {
        $signature = $request->header('X-Line-Signature');
        $body = $request->getContent();

        if (!$signature || !$bot->channel_secret) {
            return false;
        }

        $expected = base64_encode(
            hash_hmac('sha256', $body, $bot->channel_secret, true)
        );

        return hash_equals($expected, $signature);
    }
}

// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'webhook.line' => ValidateLINESignature::class,
        'webhook.telegram' => ValidateTelegramSignature::class,
    ]);
})
```
