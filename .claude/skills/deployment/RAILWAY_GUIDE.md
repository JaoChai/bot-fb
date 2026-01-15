# Railway Deployment Guide

## Overview

BotFacebook ใช้ Railway สำหรับ deployment ทั้ง 3 services:

| Service | URL | Purpose |
|---------|-----|---------|
| Frontend | https://www.botjao.com | React SPA |
| Backend | https://api.botjao.com | Laravel API |
| WebSocket | wss://reverb.botjao.com | Real-time |

## Railway CLI

### Installation

```bash
# macOS
brew install railway

# npm (all platforms)
npm install -g @railway/cli
```

### Authentication

```bash
railway login
```

### Linking Project

```bash
# Link to existing project
railway link

# Link specific service
railway link --service api
```

## Deployment

### Quick Deploy

```bash
# Deploy current directory
railway up

# Deploy with build logs
railway up --ci
```

### Service-Specific Deploy

```bash
# Deploy backend
cd backend && railway up

# Deploy frontend
cd frontend && railway up
```

### Via Git Push

```bash
# Push to trigger auto-deploy
git push origin main
```

## Environment Variables

### View Variables

```bash
# List all variables
railway variables

# JSON format
railway variables --json
```

### Set Variables

```bash
# Single variable
railway variables set APP_DEBUG=false

# Multiple variables
railway variables set KEY1=value1 KEY2=value2
```

### Required Variables

| Variable | Service | Description |
|----------|---------|-------------|
| APP_ENV | Backend | production |
| APP_KEY | Backend | Laravel app key |
| APP_URL | Backend | https://api.botjao.com |
| DATABASE_URL | Backend | Neon connection string |
| OPENROUTER_API_KEY | Backend | AI model access |
| LINE_CHANNEL_SECRET | Backend | LINE webhook |
| LINE_CHANNEL_ACCESS_TOKEN | Backend | LINE API |
| REVERB_APP_ID | Backend | WebSocket |
| REVERB_APP_KEY | Backend/Frontend | WebSocket |
| REVERB_APP_SECRET | Backend | WebSocket |

## Monitoring

### View Logs

```bash
# Recent logs
railway logs

# Filter logs
railway logs --filter "error"

# Specific lines
railway logs --lines 100

# Follow logs
railway logs -f
```

### Build Logs

```bash
railway logs --type build
```

### Deployment Logs

```bash
railway logs --type deploy
```

## Deployments

### List Deployments

```bash
railway deployments
```

### View Deployment Status

```bash
railway status
```

### Rollback

```bash
# Rollback to previous
railway rollback

# Rollback to specific deployment
railway rollback --deployment-id <id>
```

## Domains

### Generate Domain

```bash
railway domain
```

### Custom Domain

1. Add custom domain in Railway dashboard
2. Configure DNS:
   - Type: CNAME
   - Name: @ or subdomain
   - Value: Railway provided value

## Database (Neon)

Railway connects to Neon via DATABASE_URL:

```bash
# Check connection
railway run php artisan db:monitor

# Run migrations
railway run php artisan migrate
```

## Cron Jobs

### Setup via railway.toml

```toml
[cron]
[[cron.jobs]]
name = "queue-work"
schedule = "* * * * *"
command = "php artisan schedule:run"
```

### Or via Procfile

```procfile
web: php artisan serve --host=0.0.0.0 --port=$PORT
worker: php artisan queue:work --sleep=3 --tries=3
scheduler: php artisan schedule:work
```

## Troubleshooting

### Build Failures

```bash
# Check build logs
railway logs --type build --lines 200

# Common issues:
# - Missing dependencies: Check package.json/composer.json
# - Build script error: Check npm run build / composer install
# - Memory issue: Upgrade plan or optimize build
```

### Deploy Failures

```bash
# Check deploy logs
railway logs --type deploy --lines 200

# Common issues:
# - Health check fails: Ensure /health endpoint works
# - Port binding: Use $PORT environment variable
# - Database connection: Check DATABASE_URL
```

### Runtime Errors

```bash
# Check application logs
railway logs --filter "error|exception" --lines 100

# Check Sentry for detailed errors
```

## Health Checks

### Endpoint Configuration

Railway expects a health endpoint that returns 200 OK.

```php
// routes/api.php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'database' => DB::connection()->getDatabaseName() ? 'connected' : 'error',
        'timestamp' => now()->toISOString(),
    ]);
});
```

### Health Check Settings

In Railway dashboard:
- Path: `/health` or `/api/health`
- Timeout: 5s
- Interval: 30s

## Deployment Checklist

### Pre-Deploy

- [ ] All tests pass locally
- [ ] Build succeeds locally
- [ ] Environment variables set in Railway
- [ ] Database migrations tested

### Deploy

- [ ] Push code or run `railway up`
- [ ] Monitor build logs
- [ ] Monitor deploy logs

### Post-Deploy

- [ ] Verify health endpoint
- [ ] Check application logs for errors
- [ ] Test critical functionality
- [ ] Monitor Sentry for new errors

## Quick Reference

```bash
# Deploy
railway up

# Logs
railway logs

# Variables
railway variables
railway variables set KEY=value

# Deployments
railway deployments
railway rollback

# Status
railway status

# Run commands
railway run <command>
```
