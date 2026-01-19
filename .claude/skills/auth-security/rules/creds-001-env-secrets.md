---
id: creds-001-env-secrets
title: Store Secrets in Environment Variables
impact: CRITICAL
impactDescription: "Hardcoded secrets get committed to git"
category: creds
tags: [credentials, env, secrets, configuration]
relatedRules: [owasp-003-sensitive-data, creds-002-encrypt-credentials]
---

## Why This Matters

Hardcoded secrets in code get committed to git, exposed in error messages, and shared when code is shared. Environment variables keep secrets separate.

## Threat Model

**Attack Vector:** Git history, code sharing, error logs
**Impact:** Full system compromise
**Likelihood:** Very High - most common credential leak

## Bad Example

```php
// Hardcoded in code
$openrouterKey = 'sk-or-v1-abc123...';
$lineSecret = 'a1b2c3d4e5f6...';

// Config files with secrets
// config/services.php
'openrouter' => [
    'key' => 'sk-or-v1-abc123...', // In git!
],

// Using env() in cached config
// config/llm.php
'key' => env('OPENROUTER_KEY'), // Won't work after config:cache!
```

**Why it's vulnerable:**
- Secrets in git history forever
- Can't rotate without code change
- Shared when code shared
- env() fails after config cache

## Good Example

```php
// .env (never committed)
OPENROUTER_KEY=sk-or-v1-abc123...
LINE_CHANNEL_SECRET=a1b2c3d4e5f6...

// config/services.php
'openrouter' => [
    'key' => env('OPENROUTER_KEY'),
],

// Access via config()
$key = config('services.openrouter.key');

// .env.example (committed, no real values)
OPENROUTER_KEY=your-api-key-here
LINE_CHANNEL_SECRET=your-channel-secret-here

// .gitignore
.env
.env.local
.env.*.local
```

**Why it's secure:**
- Secrets never in git
- Easy to rotate
- Different values per environment
- Works with config cache

## Audit Command

```bash
# Check for hardcoded secrets
grep -rn "sk-or\|sk_live\|api_key\s*=" --include="*.php" app/ config/

# Verify .env not in git
git ls-files | grep -E "^\.env$"  # Should return nothing

# Check .gitignore
grep ".env" .gitignore
```

## Project-Specific Notes

**BotFacebook Environment Setup:**

```bash
# .env (Railway sets these)
APP_KEY=base64:...
DB_URL=postgres://...
OPENROUTER_KEY=sk-or-v1-...
LINE_CHANNEL_SECRET=...
TELEGRAM_BOT_TOKEN=...

# .env.example (committed)
APP_KEY=
DB_URL=postgres://user:pass@host:5432/db
OPENROUTER_KEY=your-openrouter-api-key
LINE_CHANNEL_SECRET=your-line-channel-secret
TELEGRAM_BOT_TOKEN=your-telegram-bot-token
```

```php
// config/services.php
'openrouter' => [
    'key' => env('OPENROUTER_KEY'),
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
],

'line' => [
    'channel_secret' => env('LINE_CHANNEL_SECRET'),
    'channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN'),
],
```
