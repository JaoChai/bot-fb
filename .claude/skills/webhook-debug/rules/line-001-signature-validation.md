---
id: line-001-signature-validation
title: LINE Webhook Signature Validation Fails
impact: CRITICAL
impactDescription: "All LINE messages rejected, bot completely non-functional"
category: line
tags: [line, webhook, signature, security, hmac]
relatedRules: [line-002-reply-token-expiry, queue-001-failed-jobs]
---

## Symptom

- All LINE webhooks return 401/403
- Bot doesn't respond to any messages
- Log shows "Invalid signature" or "Signature mismatch"

## Root Cause

LINE validates webhooks using HMAC-SHA256 signature. Common causes:

1. Wrong channel secret in `.env`
2. Request body modified before validation
3. Middleware parsing body before signature check
4. Different encoding (UTF-8 issues)

## Diagnosis

### Quick Check

```bash
# Check Railway logs for signature errors
railway logs --filter "signature"

# Check channel secret is set
grep CHANNEL_SECRET .env
```

### Detailed Analysis

```php
// Debug in WebhookController
$signature = $request->header('X-Line-Signature');
$body = $request->getContent(); // Must be raw body!
$secret = config('services.line.channel_secret');

$expected = base64_encode(hash_hmac('sha256', $body, $secret, true));

Log::debug('Signature debug', [
    'received' => $signature,
    'expected' => $expected,
    'body_length' => strlen($body),
    'secret_length' => strlen($secret),
]);
```

## Solution

### Fix Steps

1. **Verify Channel Secret**
   - Go to LINE Developers Console
   - Copy Channel Secret exactly (no spaces)
   - Update `.env` and Railway env vars

2. **Ensure Raw Body Access**
```php
// In WebhookController - use raw body
public function handleLine(Request $request, $botId)
{
    $body = $request->getContent(); // NOT $request->all()
    $signature = $request->header('X-Line-Signature');

    if (!$this->validateSignature($body, $signature, $channelSecret)) {
        return response('Invalid signature', 401);
    }
}
```

3. **Check Middleware Order**
```php
// Exclude webhook routes from body parsing
// In app/Http/Middleware/VerifyCsrfToken.php
protected $except = [
    'api/webhook/*',
];
```

### Code Example

```php
// Good: Correct signature validation
private function validateLineSignature(string $body, string $signature, string $secret): bool
{
    $hash = base64_encode(
        hash_hmac('sha256', $body, $secret, true)
    );

    return hash_equals($hash, $signature);
}
```

## Prevention

- Store channel secret in encrypted env vars
- Add signature validation tests
- Monitor for 401 errors in Sentry
- Use `hash_equals()` for timing-safe comparison

## Debug Commands

```bash
# Test webhook locally with ngrok
ngrok http 8000

# Simulate LINE webhook
curl -X POST https://your-app.com/api/webhook/line/{bot_id} \
  -H "Content-Type: application/json" \
  -H "X-Line-Signature: {calculated_signature}" \
  -d '{"events":[]}'

# Calculate signature manually
echo -n '{"events":[]}' | openssl dgst -sha256 -hmac "YOUR_SECRET" -binary | base64
```

## Project-Specific Notes

**BotFacebook Context:**
- File: `app/Http/Controllers/Webhook/LineWebhookController.php`
- Service: `app/Services/LineService.php`
- Secret stored in: `config/services.php` under `line.channel_secret`
- Each bot has its own channel secret in `bots.channel_secret` column
