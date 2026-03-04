# Railway Deployment Guide

## Overview

BotFacebook ใช้ Railway สำหรับ deployment ทั้ง 4 services (monorepo):

| Service | URL | Purpose |
|---------|-----|---------|
| Frontend | https://www.botjao.com | React SPA |
| Backend (web) | https://api.botjao.com | Laravel API |
| Backend (worker) | - | Queue processing |
| Backend (reverb) | wss://reverb.botjao.com | Real-time WebSocket |
| Backend (scheduler) | - | Cron/schedule tasks |

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

## Watch Patterns (Monorepo)

Railway uses watch patterns to detect which service to rebuild on push. This project is a monorepo with separate root directories.

| Service | Config File | Watch Pattern |
|---------|-------------|---------------|
| Backend (web, worker, reverb, scheduler) | `railway.toml` (root) | `["/backend/**"]` |
| Frontend | `frontend/railway.json` | `["/frontend/**"]` |

**Config locations:**

```toml
# railway.toml (root) - used by backend services
[build]
watchPatterns = ["/backend/**"]
```

```json
// frontend/railway.json - used by frontend service
{
  "build": {
    "builder": "NIXPACKS",
    "watchPatterns": ["/frontend/**"]
  }
}
```

**Key points:**
- Changes to `/backend/**` only trigger backend service rebuilds
- Changes to `/frontend/**` only trigger frontend service rebuilds
- Changes to root files (e.g. `railway.toml`) may trigger all services
- Each backend service (web, worker, reverb, scheduler) shares the same watch pattern

## Procfile (4 Services)

The backend runs 4 separate processes via `backend/Procfile`:

```procfile
web: php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=$PORT
worker: php artisan queue:work --queue=webhooks,default --sleep=3 --tries=3 --backoff=5 --max-jobs=1000 --max-time=3600
reverb: php artisan config:cache && php artisan reverb:start --host=0.0.0.0 --port=$PORT
scheduler: php artisan config:cache && php artisan schedule:work
```

| Process | Purpose | Notes |
|---------|---------|-------|
| `web` | HTTP API server | Caches config/routes/views, runs migrations, then serves |
| `worker` | Queue processing | Queue order: `webhooks,default` (webhooks prioritized) |
| `reverb` | WebSocket server | Laravel Reverb for real-time features |
| `scheduler` | Cron runner | Runs `schedule:work` for recurring tasks |

**Queue ordering:** The `--queue=webhooks,default` flag ensures webhook jobs (LINE/Telegram incoming messages) are processed before other background jobs. This prevents message delivery delays during high load.

**Worker limits:** `--max-jobs=1000 --max-time=3600` restarts the worker after 1000 jobs or 1 hour to prevent memory leaks.

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

See [Procfile (4 Services)](#procfile-4-services) section above for full details.

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

### Endpoints

Two health endpoints are available via `HealthController` (`app/Http/Controllers/Api/HealthController.php`):

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `GET /health` | Public | Load balancers, uptime monitors. Returns `healthy`/`unhealthy` + timestamp |
| `GET /health/detailed` | `auth:sanctum` | Debugging. Returns per-component status, latencies, circuit breaker info |

**Public `/health` response:**
```json
{ "status": "healthy", "timestamp": "2026-03-04T..." }
```

**Authenticated `/health/detailed` response:**
```json
{
  "status": "healthy",
  "timestamp": "2026-03-04T...",
  "checks": {
    "database": { "status": "up", "latency_ms": 2.5, "connection": "pgsql", "driver": "pgsql" },
    "cache": { "status": "up", "latency_ms": 1.2, "driver": "database" },
    "queue": { "status": "up", "pending_jobs": 3, "stuck_jobs": 0, "failed_jobs": 0 }
  },
  "circuit_breakers": { "...": "..." }
}
```

**Status codes:** 200 for `healthy`, 503 for `unhealthy` or `degraded`.

**Component checks:** database (SELECT 1), cache (put/get/forget cycle), queue (pending/stuck/failed job counts).

**Circuit breaker integration:** Failures in database or cache checks are recorded via `CircuitBreakerService`. The `/health/detailed` endpoint includes `circuit_breakers` with all breaker statuses.

### Health Check Settings

In Railway dashboard (for frontend service, configured in `frontend/railway.json`):
- Path: `/health`
- Timeout: 10s
- Restart policy: `ON_FAILURE` (max 10 retries)

For backend services, configure in Railway dashboard:
- Path: `/health`
- Timeout: 5s
- Interval: 30s

## Frontend Build

### Vite Chunking Strategy

The frontend uses manual chunk splitting in `frontend/vite.config.ts` to optimize bundle size:

| Chunk | Contents |
|-------|----------|
| `vendor-react` | react, react-dom, react-router |
| `vendor-radix` | All @radix-ui/* components (14 packages) |
| `vendor-query` | @tanstack/react-query, @tanstack/react-virtual, axios |
| `vendor-charts` | recharts |
| `vendor-icons` | lucide-react |
| `vendor-utils` | date-fns, clsx, tailwind-merge, class-variance-authority, zod |
| `vendor-state` | zustand, react-hook-form, @hookform/resolvers |

**Build settings:**
- `chunkSizeWarningLimit`: 500 KB
- `minify`: esbuild
- `sourcemap`: **false** (disabled in production)
- Builder: NIXPACKS (configured in `frontend/railway.json`)
- Start command: `node server.js` (SSR/static server)

### Adding New Dependencies

When adding new npm packages, consider whether they belong in an existing vendor chunk or need a new one. Large libraries (>50KB) should have their own chunk to allow independent caching.

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
