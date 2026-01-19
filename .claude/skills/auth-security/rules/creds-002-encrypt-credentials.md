---
id: creds-002-encrypt-credentials
title: Encrypt Credentials at Rest
impact: CRITICAL
impactDescription: "Plain text credentials exposed in database breach"
category: creds
tags: [credentials, encryption, database, security]
relatedRules: [creds-001-env-secrets, owasp-003-sensitive-data]
---

## Why This Matters

API keys and tokens stored in plain text are exposed if database is compromised. Laravel's encrypted casting provides automatic encryption/decryption.

## Threat Model

**Attack Vector:** Database breach, SQL injection, backup exposure
**Impact:** All platform credentials compromised
**Likelihood:** Medium - but catastrophic when it happens

## Bad Example

```php
// Plain text storage
class Bot extends Model
{
    protected $fillable = [
        'name',
        'access_token',      // Plain text!
        'channel_secret',    // Plain text!
        'openrouter_key',    // Plain text!
    ];
}

// Visible in database
// bots table: access_token = "xoxb-123456789-abcdefg"
```

**Why it's vulnerable:**
- Database dump exposes all tokens
- Backups contain plain text secrets
- DB admins can see credentials
- SQL injection = full compromise

## Good Example

```php
class Bot extends Model
{
    protected $fillable = [
        'name',
        'access_token',
        'channel_secret',
        'openrouter_key',
    ];

    // Automatic encryption/decryption
    protected $casts = [
        'access_token' => 'encrypted',
        'channel_secret' => 'encrypted',
        'openrouter_key' => 'encrypted',
    ];

    // Hide from serialization
    protected $hidden = [
        'access_token',
        'channel_secret',
        'openrouter_key',
    ];
}

// In database: access_token = "eyJpdiI6Ik1uQ..."
// In code: $bot->access_token returns decrypted value
```

**Why it's secure:**
- AES-256-GCM encryption at rest
- Automatic decrypt on access
- DB breach doesn't expose secrets
- Hidden from JSON/array output

## Audit Command

```bash
# Find models with potential credentials
grep -rn "token\|secret\|key\|password" app/Models/ --include="*.php"

# Check for encrypted casts
grep -rn "'encrypted'" app/Models/ --include="*.php"

# Verify hidden attributes
grep -rn "protected \$hidden" app/Models/ --include="*.php"
```

## Project-Specific Notes

**BotFacebook Encrypted Fields:**

```php
// Bot model - all platform credentials encrypted
class Bot extends Model
{
    protected $casts = [
        'access_token' => 'encrypted',      // LINE/Telegram token
        'channel_secret' => 'encrypted',    // LINE channel secret
        'openrouter_key' => 'encrypted',    // Custom API key
        'webhook_secret' => 'encrypted',    // Webhook verification
    ];

    protected $hidden = [
        'access_token',
        'channel_secret',
        'openrouter_key',
        'webhook_secret',
    ];
}

// User model - sensitive fields
class User extends Model
{
    protected $hidden = [
        'password',
        'remember_token',
    ];
}
```

**Important:** APP_KEY must be set and never changed after encryption starts.
