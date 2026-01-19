---
id: railway-004-service-config
title: Service Configuration Issues
impact: MEDIUM
impactDescription: "Service misconfigured, suboptimal performance"
category: railway
tags: [railway, config, service, settings]
relatedRules: [env-001-required-vars, health-001-endpoint-config]
---

## Symptom

- Service not starting correctly
- Wrong number of instances
- Resource limits being hit
- Sleep mode activating unexpectedly

## Root Cause

1. Start command incorrect
2. Resource limits too low
3. Sleep mode settings
4. Replica configuration
5. Region mismatch

## Diagnosis

### Quick Check

```bash
# Check service status
railway status

# List services
railway services

# Check current config
railway show
```

### Detailed Analysis

```bash
# View service details via API
railway services --json | jq .

# Check resource usage
railway metrics

# Verify start command
railway show | grep -i command
```

## Solution

### Fix Steps

1. **Configure start command**
```toml
# railway.toml
[deploy]
startCommand = "php artisan serve --host=0.0.0.0 --port=$PORT"
# Or for Octane:
startCommand = "php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=$PORT"
```

2. **Set resource limits**
```bash
# Via Railway dashboard or API
# Recommended for Laravel:
# - Memory: 512MB minimum, 1GB recommended
# - CPU: 0.5 vCPU minimum
```

3. **Configure sleep settings**
```bash
# Disable sleep for production
# Railway Dashboard → Service → Settings → Sleep → Disable
```

4. **Set replicas**
```bash
# For high availability
# Railway Dashboard → Service → Settings → Instances → 2+
```

### Nixpacks Configuration

```toml
# nixpacks.toml
[phases.setup]
nixPkgs = ["php82", "php82Packages.composer"]

[phases.build]
cmds = [
    "composer install --no-dev --optimize-autoloader",
    "npm ci",
    "npm run build",
    "php artisan optimize"
]

[start]
cmd = "php artisan serve --host=0.0.0.0 --port=$PORT"
```

## Verification

```bash
# Verify service is running
railway status

# Check health
curl -s https://api.botjao.com/health

# Check logs for startup
railway logs --filter "starting|listening" --lines 20
```

## Prevention

- Document service configuration
- Version control railway.toml
- Test config changes in staging
- Monitor resource usage
- Set up alerts for resource limits

## Project-Specific Notes

**BotFacebook Context:**
- Backend: PHP/Laravel with standard artisan serve
- Frontend: Vite static build
- WebSocket: Reverb as separate service
- Region: Use Asia for lower latency
