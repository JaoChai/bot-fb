---
id: security-004-sensitive-data
title: Sensitive Data Handling
impact: HIGH
impactDescription: "Prevents accidental exposure of secrets and sensitive information"
category: security
tags: [security, secrets, logging, encryption]
relatedRules: [security-001-input-validation]
---

## Why This Matters

Sensitive data (passwords, API keys, tokens) must never appear in logs, responses, or error messages. Exposure can lead to account compromises, data breaches, and compliance violations.

## Bad Example

```php
// Problem: Logging sensitive data
Log::info('User login', [
    'email' => $email,
    'password' => $password, // NEVER!
]);

Log::debug('API call', [
    'request' => $request->all(), // May contain tokens
]);

// Problem: Returning secrets in response
return response()->json([
    'user' => $user,
    'api_key' => $user->api_key, // Exposed!
]);

// Problem: Secrets in error messages
throw new \Exception("Invalid API key: {$apiKey}"); // Key in logs!
```

**Why it's wrong:**
- Secrets in logs
- Credentials exposed
- Compliance violation
- Attack surface increased

## Good Example

```php
// Never log sensitive data
Log::info('User login attempt', [
    'email' => $email,
    'ip' => $request->ip(),
    // No password!
]);

Log::debug('API call', [
    'endpoint' => $endpoint,
    'user_id' => auth()->id(),
    // Sanitize request data
    'request' => Arr::except($request->all(), ['password', 'token', 'api_key']),
]);

// Use $hidden on models
class User extends Model
{
    protected $hidden = [
        'password',
        'remember_token',
        'api_key',
        'webhook_secret',
    ];
}

// Encrypt sensitive data at rest
class Bot extends Model
{
    protected $casts = [
        'webhook_secret' => 'encrypted',
        'api_credentials' => 'encrypted:array',
    ];
}

// Mask in error messages
Log::error('API authentication failed', [
    'api_key_prefix' => substr($apiKey, 0, 8) . '***',
]);

// Secure comparison for tokens
if (!hash_equals($storedToken, $providedToken)) {
    throw new AuthenticationException('Invalid token');
}
```

**Why it's better:**
- No secrets in logs
- Models hide sensitive fields
- Data encrypted at rest
- Safe error messages

## Project-Specific Notes

**BotFacebook Hidden Fields:**

```php
// User model
protected $hidden = [
    'password',
    'remember_token',
];

// Bot model
protected $hidden = [
    'webhook_secret',
    'platform_credentials',
];

// API key - show only prefix in responses
protected $appends = ['api_key_preview'];

public function getApiKeyPreviewAttribute(): string
{
    return $this->api_key
        ? substr($this->api_key, 0, 8) . '...'
        : null;
}
```

**Audit Logging (safe):**
```php
Log::channel('security')->info('Bot credentials updated', [
    'bot_id' => $bot->id,
    'user_id' => auth()->id(),
    'ip' => request()->ip(),
    // Never log the actual credentials
]);
```

**Environment Variables:**
```bash
# .env - never commit
OPENROUTER_API_KEY=sk-...
LINE_CHANNEL_SECRET=...
```

## References

- [OWASP Sensitive Data Exposure](https://owasp.org/Top10/A02_2021-Cryptographic_Failures/)
- [Laravel Encryption](https://laravel.com/docs/encryption)
