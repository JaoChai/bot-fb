---
id: railway-002-build-error
title: Build Phase Errors
impact: HIGH
impactDescription: "Build fails, deployment cannot proceed"
category: railway
tags: [railway, build, composer, npm, error]
relatedRules: [railway-001-deploy-failure, env-001-required-vars]
---

## Symptom

- "Build failed" error in Railway
- Composer or npm install errors
- Memory exceeded during build
- Syntax errors prevent compilation

## Root Cause

1. Dependency version conflicts
2. Missing environment variables at build time
3. Memory limit exceeded
4. Lock file out of sync
5. PHP/Node version mismatch

## Diagnosis

### Quick Check

```bash
# View build logs
railway logs --type build --lines 100

# Look for specific errors
railway logs --type build --filter "error|failed|cannot"
```

### Detailed Analysis

```bash
# Test build locally
composer install --no-interaction
npm ci
npm run build

# Check for dependency issues
composer validate
npm audit
```

## Solution

### Fix Steps

1. **Composer errors**
```bash
# Clear cache and reinstall
rm -rf vendor composer.lock
composer install

# If memory issue
COMPOSER_MEMORY_LIMIT=-1 composer install

# Push updated lock file
git add composer.lock
git commit -m "fix: update composer.lock"
```

2. **NPM errors**
```bash
# Clear and reinstall
rm -rf node_modules package-lock.json
npm install

# Use ci for cleaner install
npm ci

# Push updated lock file
git add package-lock.json
git commit -m "fix: update package-lock.json"
```

3. **Memory issues**
```bash
# In nixpacks.toml or railway.toml
[build]
buildCommand = "COMPOSER_MEMORY_LIMIT=-1 composer install && npm ci && npm run build"
```

4. **Version conflicts**
```bash
# Check PHP version
php -v  # Local
# Compare with Railway PHP version

# Specify version in composer.json
"require": {
    "php": "^8.2"
}
```

### Runbook

```bash
# 1. Get build error details
railway logs --type build --filter "error" --lines 100

# 2. Identify error type
# Composer? NPM? Memory? Syntax?

# 3. Fix locally
composer install  # Test composer
npm ci && npm run build  # Test npm/vite

# 4. Clear caches if needed
composer clear-cache
npm cache clean --force

# 5. Push fix
git add -A
git commit -m "fix: build error - [description]"
git push

# 6. Redeploy
railway up --ci
```

## Verification

```bash
# Test full build locally
composer install --no-interaction
npm ci
npm run build

# Verify no errors
echo $?  # Should be 0

# Check Railway build
railway logs --type build --lines 20
```

## Prevention

- Keep lock files in version control
- Test builds locally before pushing
- Pin major dependency versions
- Set up build notifications
- Document build requirements

## Project-Specific Notes

**BotFacebook Context:**
- PHP 8.2+ required
- Node 20+ for frontend
- Build command in `railway.toml` or Nixpacks
- Memory: Composer may need `-1` limit
