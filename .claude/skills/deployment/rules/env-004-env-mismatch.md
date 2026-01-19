---
id: env-004-env-mismatch
title: Environment Mismatch Issues
impact: MEDIUM
impactDescription: "Production behaves differently from local development"
category: env
tags: [environment, mismatch, local, production, debugging]
relatedRules: [env-003-config-sync, troubleshoot-001-500-errors]
---

## Symptom

- "Works on my machine" syndrome
- Tests pass locally, fail in production
- Different data formats or responses
- Debug info leaking in production

## Root Cause

1. APP_ENV or APP_DEBUG wrong
2. Different PHP/Node versions
3. Different database state
4. Missing production services
5. Different file system behavior

## Diagnosis

### Quick Check

```bash
# Verify APP_ENV and APP_DEBUG
railway variables | grep -E "APP_ENV|APP_DEBUG"
# Should show: APP_ENV=production, APP_DEBUG=false

# Compare versions
php -v  # Local
node -v # Local
# Compare with Railway build logs
```

### Detailed Analysis

```bash
# Check Laravel environment
railway exec "php artisan env"

# Compare config values
railway exec "php artisan config:show app.env"
railway exec "php artisan config:show app.debug"

# Check for debug mode issues
railway logs --filter "debug\|stack trace" --lines 50
```

## Solution

### Fix Steps

1. **Ensure production settings**
```bash
# Critical production settings
railway variables set APP_ENV=production
railway variables set APP_DEBUG=false
railway variables set LOG_LEVEL=warning
```

2. **Match PHP versions**
```toml
# nixpacks.toml
[phases.setup]
nixPkgs = ["php82"]  # Match local version

# Or Dockerfile
FROM php:8.2-fpm
```

3. **Database state sync**
```bash
# Run migrations
railway exec "php artisan migrate --force"

# Verify migration status
railway exec "php artisan migrate:status"
```

4. **Handle environment-specific code**
```php
// Only in development
if (app()->environment('local', 'development')) {
    // Debug code
}

// Only in production
if (app()->environment('production')) {
    // Production-only code
}
```

5. **Simulate production locally**
```bash
# Run with production settings
APP_ENV=production APP_DEBUG=false php artisan serve

# Or use Docker to match production
docker-compose -f docker-compose.prod.yml up
```

### Common Mismatches

| Issue | Local | Production | Fix |
|-------|-------|------------|-----|
| Debug mode | true | false | Set APP_DEBUG=false |
| Error display | detailed | generic | Expected behavior |
| Cache | disabled | enabled | Use same caching |
| PHP version | 8.3 | 8.2 | Match versions |
| Database | MySQL | PostgreSQL | Test with PostgreSQL |

## Verification

```bash
# Verify production config
railway exec "php artisan env"
# Should show: production

# Verify no debug info in response
curl -s https://api.botjao.com/api/nonexistent | jq .
# Should NOT show stack trace

# Compare local and production response
curl -s http://localhost:8000/api/health | jq .
curl -s https://api.botjao.com/api/health | jq .
# Should be identical format
```

## Prevention

- Always test with APP_ENV=production locally
- Use Docker for consistent environments
- Document version requirements
- CI/CD should match production
- Regular environment audits

## Project-Specific Notes

**BotFacebook Context:**
- Production: APP_ENV=production, APP_DEBUG=false
- PHP: 8.2+ required
- Database: PostgreSQL (Neon) in all environments
- Local development: Use .env with same structure
