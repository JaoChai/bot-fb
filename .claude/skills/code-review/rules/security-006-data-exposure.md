---
id: security-006-data-exposure
title: Sensitive Data Exposure Prevention
impact: HIGH
impactDescription: "API keys, passwords, or PII leaked to unauthorized parties"
category: security
tags: [security, data-exposure, owasp, pii]
relatedRules: [backend-004-api-resource]
---

## Why This Matters

Exposing sensitive data through API responses, logs, or error messages can lead to credential theft, privacy violations, and regulatory non-compliance (GDPR, CCPA).

## Bad Example

```php
// Returning full model (includes sensitive fields)
public function show(User $user)
{
    return $user; // Exposes password hash, api_token, etc.
}

// Logging sensitive data
Log::info('User login', ['password' => $request->password]);

// Error reveals internal details
throw new \Exception("DB connection failed: $connectionString");

// Hardcoded secrets
$apiKey = 'sk-1234567890abcdef';
```

**Why it's wrong:**
- Password hashes in response
- Credentials in logs
- Connection strings exposed
- Secrets in source code

## Good Example

```php
// Use API Resource with explicit fields
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Omit: password, api_token, remember_token
        ];
    }
}

// Sanitize logs
Log::info('User login attempt', [
    'email' => $request->email,
    // Never log password
]);

// Generic error messages
throw new \Exception('Database connection failed');

// Environment variables for secrets
$apiKey = config('services.openai.key');
```

**Why it's better:**
- Explicit field selection
- No sensitive data in logs
- Generic error messages
- Secrets in environment

## Review Checklist

- [ ] API Resources used (not raw models)
- [ ] Model `$hidden` array includes sensitive fields
- [ ] No secrets hardcoded in code
- [ ] Logs don't contain passwords/tokens
- [ ] Error messages don't reveal internals
- [ ] `.env` not committed to git

## Detection

```bash
# Potential hardcoded secrets
grep -rn "api_key\|secret\|password\|token" --include="*.php" app/ config/ | grep "="

# Raw model returns
grep -rn "return \$this->\|return \$user\|return \$bot" --include="*.php" app/Http/Controllers/

# Sensitive fields in logs
grep -rn "Log::\|log(" --include="*.php" app/ | grep -i "password\|token\|key"
```

## Project-Specific Notes

**BotFacebook Data Protection:**

```php
// Bot model - hide sensitive fields
class Bot extends Model
{
    protected $hidden = [
        'encrypted_api_key',
        'openrouter_key',
        'line_channel_secret',
    ];

    // Encrypt API keys at rest
    protected $casts = [
        'encrypted_api_key' => 'encrypted',
    ];
}

// BotResource - explicit safe fields
class BotResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'platform' => $this->platform,
            'is_active' => $this->is_active,
            // Never expose:
            // - encrypted_api_key
            // - line_channel_secret
            // - telegram_bot_token
        ];
    }
}

// Safe logging
Log::info('Bot API call', [
    'bot_id' => $bot->id,
    'model' => $model,
    'tokens' => $usage['total_tokens'],
    // Never log: API keys, user messages
]);
```
