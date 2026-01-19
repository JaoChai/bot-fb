# Deployment Decision Trees and Runbooks

## Decision Tree 1: Deployment Failure Diagnosis

```
Deploy failed?
├── Build phase failed
│   ├── Composer/npm errors → Check dependencies, lock files
│   ├── Memory exceeded → Increase build memory
│   └── Syntax error → Fix code, run local build first
│
├── Deploy phase failed
│   ├── Health check failed → Check /health endpoint
│   ├── Port binding error → Verify PORT env var
│   └── Process crashed → Check startup logs
│
└── Post-deploy issues
    ├── 500 errors → Check app logs, Sentry
    ├── 502/503 → Service not responding, restart
    └── Slow response → Check resource usage
```

## Decision Tree 2: Environment Variable Issues

```
Env var problem?
├── Missing variable
│   ├── New deployment → Add via railway variables
│   ├── After update → Redeploy to pick up changes
│   └── Secret → Use Railway secrets
│
├── Wrong value
│   ├── Database URL → Verify Neon connection string
│   ├── API keys → Check for whitespace/newlines
│   └── URLs → Ensure https://, no trailing slash
│
└── Not being read
    ├── Config cached → Clear config cache
    ├── Wrong name → Check case sensitivity
    └── Overridden → Check .env vs Railway precedence
```

## Decision Tree 3: Rollback Decision

```
Need to rollback?
├── Is it urgent?
│   ├── Yes (production down) → Immediate rollback
│   │   └── railway rollback --to {deployment-id}
│   └── No (degraded but working) → Investigate first
│
├── Type of issue?
│   ├── Code bug → Rollback + hotfix
│   ├── Config issue → Fix config, no rollback
│   └── Database migration → Consider migration rollback
│
└── Side effects?
    ├── New migrations ran → May need DB rollback
    ├── New env vars needed → Remove after rollback
    └── Cache changes → Clear caches after rollback
```

## Decision Tree 4: Log Analysis

```
Reading logs?
├── Which logs?
│   ├── Build errors → railway logs --type build
│   ├── Runtime errors → railway logs --type deploy
│   └── Specific time → railway logs --since "1 hour ago"
│
├── Finding issues?
│   ├── Filter errors → --filter "error|exception"
│   ├── Filter warnings → --filter "warning"
│   └── Filter by module → --filter "queue|database"
│
└── Too much noise?
    ├── Reduce lines → --lines 50
    ├── JSON format → --json for parsing
    └── Time range → --since --until
```

## Decision Tree 5: Health Check Failures

```
Health check failing?
├── Which component?
│   ├── Database
│   │   ├── Connection refused → Check DATABASE_URL
│   │   ├── Timeout → Network issue, check Neon
│   │   └── Auth failed → Check credentials
│   │
│   ├── Cache (Redis)
│   │   ├── Not configured → Use array driver
│   │   ├── Connection failed → Check REDIS_URL
│   │   └── Memory full → Flush cache
│   │
│   └── Queue
│       ├── Not processing → Restart worker
│       ├── Failed jobs → Check queue:failed
│       └── Connection issue → Check QUEUE_CONNECTION
│
└── All components OK but failing?
    ├── App error → Check error logs
    ├── Timeout → Increase health check timeout
    └── Wrong endpoint → Verify /health route exists
```

## Runbook: Complete Deployment

```bash
# 1. Pre-deploy checks
git status
php artisan test
npm run build

# 2. Check current state
railway status
railway logs --lines 10

# 3. Deploy
railway up --ci

# 4. Monitor deployment
# Watch for: "Build completed", "Deploy completed"

# 5. Verify
curl -s https://api.botjao.com/health | jq .
railway logs --filter "error" --lines 50

# 6. If issues
railway rollback  # Rollback to previous
# OR
railway logs --lines 200  # Investigate
```

## Runbook: Emergency Rollback

```bash
# 1. Identify current bad deployment
railway deployments --json | head -1

# 2. Find last good deployment
railway deployments --limit 5

# 3. Rollback
railway rollback --to {good-deployment-id}

# 4. Verify rollback
curl -s https://api.botjao.com/health
railway logs --filter "error" --lines 20

# 5. Communicate
# - Notify team
# - Create incident ticket
# - Plan hotfix
```

## Runbook: Database Migration

```bash
# 1. Check pending migrations
railway exec "php artisan migrate:status"

# 2. Backup (if needed)
# Via Neon dashboard or pg_dump

# 3. Run migrations
railway exec "php artisan migrate --force"

# 4. Verify
railway exec "php artisan migrate:status"

# 5. If rollback needed
railway exec "php artisan migrate:rollback --step=1"
```

## Environment Variable Reference

### Required Variables
```
APP_ENV=production
APP_KEY=base64:xxx
APP_URL=https://api.botjao.com
APP_DEBUG=false

DATABASE_URL=postgresql://user:pass@host:5432/db?sslmode=require

OPENROUTER_API_KEY=xxx
JINA_API_KEY=xxx

LINE_CHANNEL_SECRET=xxx
LINE_CHANNEL_ACCESS_TOKEN=xxx
TELEGRAM_BOT_TOKEN=xxx

REVERB_APP_ID=xxx
REVERB_APP_KEY=xxx
REVERB_APP_SECRET=xxx

SENTRY_DSN=xxx
```

### Optional Variables
```
LOG_LEVEL=warning
QUEUE_CONNECTION=database
CACHE_DRIVER=database
SESSION_DRIVER=database
```

## MCP Tool Reference

### Railway Tools
| Tool | Description |
|------|-------------|
| `deploy` | Deploy current code |
| `get-logs` | View build/deploy logs |
| `list-deployments` | Show deployment history |
| `list-variables` | Show environment variables |
| `set-variables` | Update environment variables |
| `generate-domain` | Create Railway domain |
| `list-services` | List all services |

### Sentry Tools
| Tool | Description |
|------|-------------|
| `find_releases` | Find recent releases |
| `search_issues` | Search for errors |
| `get_issue_details` | Get error details |
