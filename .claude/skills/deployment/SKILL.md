---
name: deployment
description: |
  Railway deployment specialist for production operations. Handles deployments, environment variables, logs, troubleshooting production issues, rollbacks.
  Triggers: 'deploy', 'production', 'Railway', 'environment', 'rollback', 'logs'.
  Use when: deploying to Railway, debugging production errors, managing environment configuration.
allowed-tools:
  - Bash(railway*)
  - Bash(git*)
  - Read
  - Grep
context:
  - path: railway.json
  - path: .env.example
---

# Deployment

Railway deployment specialist for BotFacebook production.

## Quick Start

```bash
# Deploy current code
railway up

# Check deployment status
railway status

# View logs
railway logs
```

## MCP Tools Available

- **railway**: Full deployment access
  - `deploy` - Deploy code
  - `get-logs` - View logs (build/deploy)
  - `list-deployments` - Deployment history
  - `list-variables` - Environment variables
  - `set-variables` - Update env vars
  - `generate-domain` - Create domain
  - `list-services` - Service list
- **sentry**: `find_releases`, `search_issues` - Release tracking and errors

## Deployment Workflow

### 1. Pre-Deploy Checks
```bash
# Run tests
php artisan test

# Check for errors
npm run build

# Verify env vars
railway variables
```

### 2. Deploy
```bash
# Deploy with logs
railway up

# Or use CI mode
railway up --ci
```

### 3. Post-Deploy Verification
```bash
# Check logs for errors
railway logs --filter "error"

# Verify health endpoint
curl https://api.botjao.com/health

# Check Sentry for new errors
```

## Environment Variables

### Required Variables

| Variable | Description |
|----------|-------------|
| `APP_ENV` | production |
| `APP_KEY` | Laravel app key |
| `APP_URL` | https://api.botjao.com |
| `DATABASE_URL` | Neon connection string |
| `OPENROUTER_API_KEY` | AI model access |
| `LINE_CHANNEL_SECRET` | LINE webhook |
| `LINE_CHANNEL_ACCESS_TOKEN` | LINE API |
| `REVERB_APP_ID` | WebSocket |
| `REVERB_APP_KEY` | WebSocket |
| `REVERB_APP_SECRET` | WebSocket |

### Update Variables

```bash
# Set single variable
railway variables set APP_DEBUG=false

# Set multiple
railway variables set KEY1=value1 KEY2=value2
```

## Troubleshooting

### Common Issues

| Issue | Check | Fix |
|-------|-------|-----|
| Build fails | Build logs | Fix code errors |
| Deploy fails | Deploy logs | Check health endpoint |
| 500 errors | App logs, Sentry | Fix code, check env vars |
| DB connection | DATABASE_URL | Verify Neon connection |
| Queue not working | Queue logs | Check QUEUE_CONNECTION |

### Debug Commands

```bash
# View recent logs
railway logs --lines 100

# Filter by severity
railway logs --filter "error|warning"

# Check specific service
railway logs --service api
```

### Rollback

```bash
# List deployments
railway deployments

# Rollback to previous
railway rollback
```

## Production Configuration

### Caching
```bash
# Clear all caches
php artisan optimize:clear

# Rebuild caches
php artisan optimize
```

### Queue Management
```bash
# Restart queue workers
railway exec "php artisan queue:restart"

# Check failed jobs
railway exec "php artisan queue:failed"

# Retry failed
railway exec "php artisan queue:retry all"
```

## Health Checks

### Endpoints

| Endpoint | Purpose |
|----------|---------|
| `/health` | Basic health |
| `/api/health` | API health with DB check |

### Health Response
```json
{
  "status": "ok",
  "database": "connected",
  "cache": "connected",
  "queue": "connected"
}
```

## Detailed Guides

- **Railway Guide**: See [RAILWAY_GUIDE.md](RAILWAY_GUIDE.md)
- **Troubleshooting**: See [TROUBLESHOOT.md](TROUBLESHOOT.md)

## Service URLs

| Service | URL |
|---------|-----|
| Frontend | https://www.botjao.com |
| Backend API | https://api.botjao.com |
| WebSocket | wss://reverb.botjao.com |

## Deployment Checklist

- [ ] All tests pass
- [ ] Build succeeds locally
- [ ] Environment variables updated
- [ ] Database migrations ready
- [ ] Changelog updated
- [ ] Sentry release created

## Utility Scripts

- `scripts/deploy.sh` - Full deploy with checks
