---
id: env-001-required-vars
title: Missing Required Environment Variables
impact: CRITICAL
impactDescription: "Application fails to start or function without required vars"
category: env
tags: [environment, variables, config, production]
relatedRules: [railway-001-deploy-failure, env-002-secrets-management]
---

## Symptom

- Application crashes on startup
- "Key not found" or "undefined" errors
- Features silently failing
- Database connection errors

## Root Cause

1. Environment variable not set in Railway
2. Variable name misspelled
3. Variable set in wrong environment
4. Variable contains invalid value
5. Variable not propagated after update

## Diagnosis

### Quick Check

```bash
# List all variables
railway variables

# Check specific variable
railway variables | grep DATABASE_URL

# Compare with .env.example
diff .env.example <(railway variables --kv)
```

### Detailed Analysis

```bash
# Export and compare
railway variables --kv > railway-vars.txt
cat .env.example | grep -v "^#" | sort > required-vars.txt
comm -23 required-vars.txt <(cat railway-vars.txt | cut -d= -f1 | sort)
```

## Solution

### Fix Steps

1. **Set missing variable**
```bash
# Single variable
railway variables set APP_KEY=base64:xxxxx

# Multiple variables
railway variables set KEY1=value1 KEY2=value2
```

2. **Required variables checklist**
```bash
# Core Laravel
railway variables set APP_ENV=production
railway variables set APP_KEY=base64:xxxxx  # Generate: php artisan key:generate --show
railway variables set APP_DEBUG=false
railway variables set APP_URL=https://api.botjao.com

# Database
railway variables set DATABASE_URL="postgresql://user:pass@host:5432/db?sslmode=require"

# AI/APIs
railway variables set OPENROUTER_API_KEY=xxxxx
railway variables set JINA_API_KEY=xxxxx

# Messaging Platforms
railway variables set LINE_CHANNEL_SECRET=xxxxx
railway variables set LINE_CHANNEL_ACCESS_TOKEN=xxxxx
railway variables set TELEGRAM_BOT_TOKEN=xxxxx

# WebSocket
railway variables set REVERB_APP_ID=xxxxx
railway variables set REVERB_APP_KEY=xxxxx
railway variables set REVERB_APP_SECRET=xxxxx

# Monitoring
railway variables set SENTRY_DSN=xxxxx
```

3. **Verify after setting**
```bash
# List to confirm
railway variables | grep {VAR_NAME}

# Redeploy to apply
railway up
```

### MCP Tool Usage

```
# List variables
Use list-variables with:
- workspacePath: current directory

# Set variables
Use set-variables with:
- workspacePath: current directory
- variables: ["APP_DEBUG=false", "LOG_LEVEL=warning"]
```

## Verification

```bash
# Check all required vars are set
for var in APP_KEY DATABASE_URL OPENROUTER_API_KEY; do
  railway variables | grep -q "^$var=" && echo "$var: OK" || echo "$var: MISSING"
done

# Test application
curl -s https://api.botjao.com/health | jq .

# Check for startup errors
railway logs --filter "undefined|not found|missing" --lines 50
```

## Prevention

- Maintain .env.example with all required vars
- Use Railway secrets for sensitive data
- Document all environment variables
- Set up deployment checks for required vars
- Use config validation in Laravel boot

## Project-Specific Notes

**BotFacebook Context:**
- Required vars documented in `.env.example`
- Sensitive vars: API keys, tokens, secrets
- Database: Neon PostgreSQL connection string
- All vars must be set in Railway dashboard or CLI
