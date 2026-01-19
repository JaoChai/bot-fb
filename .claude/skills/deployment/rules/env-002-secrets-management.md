---
id: env-002-secrets-management
title: Secrets Management Best Practices
impact: HIGH
impactDescription: "Exposed secrets can lead to security breaches"
category: env
tags: [secrets, security, api-keys, credentials]
relatedRules: [env-001-required-vars, env-003-config-sync]
---

## Symptom

- Secrets visible in logs
- API keys exposed in Git history
- Credentials shared insecurely
- Unauthorized access to services

## Root Cause

1. Secrets committed to repository
2. Secrets logged in plain text
3. Secrets shared via insecure channels
4. No rotation policy
5. Too many people have access

## Diagnosis

### Quick Check

```bash
# Check for secrets in git history
git log -p | grep -i "api_key\|secret\|password" | head -20

# Check .env not committed
git ls-files | grep -E "^\.env$"
# Should return nothing

# Verify .gitignore
cat .gitignore | grep ".env"
```

### Detailed Analysis

```bash
# Scan for secrets in codebase
grep -r "sk-\|key=\|secret=" --include="*.php" --include="*.ts" | head -20

# Check Railway for exposed secrets
railway variables | grep -i "key\|secret\|token"
```

## Solution

### Fix Steps

1. **Use Railway for secrets**
```bash
# Set secrets via CLI (not in .env file)
railway variables set OPENROUTER_API_KEY=sk-or-v1-xxxxx
railway variables set LINE_CHANNEL_SECRET=xxxxx

# Secrets are encrypted at rest in Railway
```

2. **Mask secrets in logs**
```php
// config/logging.php - don't log these
'mask' => [
    'password',
    'api_key',
    'secret',
    'token',
    'authorization',
],
```

3. **Never hardcode secrets**
```php
// Bad
$apiKey = 'sk-or-v1-xxxxx';

// Good
$apiKey = config('services.openrouter.api_key');
```

4. **Rotate compromised secrets**
```bash
# 1. Generate new secret from provider
# 2. Update in Railway
railway variables set OPENROUTER_API_KEY=new-secret

# 3. Redeploy
railway up

# 4. Invalidate old secret at provider
```

### Secret Categories

```bash
# Category: AI/LLM APIs
OPENROUTER_API_KEY=  # Rotate monthly
JINA_API_KEY=        # Rotate monthly

# Category: Platform APIs
LINE_CHANNEL_SECRET=
LINE_CHANNEL_ACCESS_TOKEN=
TELEGRAM_BOT_TOKEN=

# Category: Infrastructure
DATABASE_URL=        # Contains password
REVERB_APP_SECRET=

# Category: Monitoring
SENTRY_DSN=          # Less sensitive
```

## Verification

```bash
# Verify secrets not in code
grep -r "sk-or-v1\|xoxb-\|ghp_" . --include="*.php" --include="*.ts" --include="*.env"
# Should return nothing

# Verify .env not tracked
git status .env
# Should show untracked or not exist

# Check logs don't expose secrets
railway logs --lines 100 | grep -i "api_key\|secret\|token"
# Should return nothing meaningful
```

## Prevention

- Use Railway variables, never commit secrets
- Add secrets to .gitignore
- Enable secret scanning in GitHub
- Implement secret rotation schedule
- Use service accounts with minimal permissions

## Project-Specific Notes

**BotFacebook Context:**
- All API keys stored in Railway variables
- .env files are gitignored
- Sensitive logs masked in production
- Secret rotation: Recommended quarterly
