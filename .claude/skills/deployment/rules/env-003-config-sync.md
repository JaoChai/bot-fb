---
id: env-003-config-sync
title: Environment Configuration Sync Issues
impact: MEDIUM
impactDescription: "Local and production configs out of sync"
category: env
tags: [environment, config, sync, local, production]
relatedRules: [env-001-required-vars, env-004-env-mismatch]
---

## Symptom

- Feature works locally but not in production
- Different behavior between environments
- Missing config keys in production
- Cache issues after deployment

## Root Cause

1. .env.example not updated
2. Config cache stale
3. New vars added but not deployed
4. Default values differ from production
5. Laravel config caching issues

## Diagnosis

### Quick Check

```bash
# Compare local vs production
cat .env.example | grep -v "^#" | cut -d= -f1 | sort > local-keys.txt
railway variables --kv | cut -d= -f1 | sort > railway-keys.txt
diff local-keys.txt railway-keys.txt
```

### Detailed Analysis

```bash
# Check for new config values in code
git log --oneline -20 --name-only | grep "config/"

# Find config usages
grep -r "config\(" --include="*.php" | grep -v "^vendor" | head -20

# Check cached config
railway exec "php artisan config:show"
```

## Solution

### Fix Steps

1. **Update .env.example**
```bash
# When adding new env var
# 1. Add to .env (local)
# 2. Add to .env.example (template)
# 3. Set in Railway

echo "NEW_FEATURE_ENABLED=true" >> .env.example
git add .env.example
git commit -m "docs: add NEW_FEATURE_ENABLED to env example"
```

2. **Clear config cache**
```bash
# After changing configs in production
railway exec "php artisan config:clear"
railway exec "php artisan cache:clear"

# Or redeploy (clears automatically)
railway up
```

3. **Sync missing variables**
```bash
# Find missing in Railway
comm -23 <(cat .env.example | grep -v "^#" | cut -d= -f1 | sort) \
         <(railway variables --kv | cut -d= -f1 | sort)

# Add missing
railway variables set MISSING_VAR=value
```

4. **Use sensible defaults**
```php
// config/features.php
return [
    // Has safe default
    'new_feature' => env('NEW_FEATURE_ENABLED', false),

    // Requires explicit setting (no default)
    'api_key' => env('REQUIRED_API_KEY'),
];
```

### Sync Script

```bash
#!/bin/bash
# scripts/sync-env-check.sh

echo "Checking env sync..."

# Get keys from .env.example
LOCAL_KEYS=$(cat .env.example | grep -v "^#" | grep "=" | cut -d= -f1 | sort)

# Get keys from Railway
RAILWAY_KEYS=$(railway variables --kv | cut -d= -f1 | sort)

echo "Missing in Railway:"
comm -23 <(echo "$LOCAL_KEYS") <(echo "$RAILWAY_KEYS")

echo ""
echo "Extra in Railway (probably OK):"
comm -13 <(echo "$LOCAL_KEYS") <(echo "$RAILWAY_KEYS")
```

## Verification

```bash
# Run sync check
./scripts/sync-env-check.sh

# Verify app works
curl -s https://api.botjao.com/health | jq .

# Check for config errors
railway logs --filter "config" --lines 50
```

## Prevention

- Always update .env.example with new vars
- Include env changes in PR descriptions
- Document env vars in code comments
- Set up CI check for .env.example sync
- Clear caches after config changes

## Project-Specific Notes

**BotFacebook Context:**
- .env.example maintained in Git
- Config cache cleared on deploy
- New features behind feature flags
- Sync check script available
