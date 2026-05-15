# Audit Context — Resolved Blockers

Reference data for all unit subagents. Read this before starting your unit task.

## Sentry

- Organization slug: `adsvance`
- Region URL: `https://us.sentry.io`
- Projects:
  - Backend (Laravel): `php-laravel`
  - Frontend: `botjao-frontend`

Use these params when calling `mcp__sentry__*` tools:
```
organizationSlug: adsvance
regionUrl: https://us.sentry.io
projectSlug: php-laravel  # for backend queries
projectSlug: botjao-frontend  # for frontend queries
```

## Neon

- Project ID: `solitary-math-34010034`

Use with `mcp__neon__run_sql`:
```
projectId: solitary-math-34010034
databaseName: neondb  # default if not specified
```

## Railway

- Project: `bot-facebook` (ID: `ba714504-2721-4535-9fc7-6b3d903c481a`)
- Services: `Redis`, `reverb`, `backend`, `frontend`, `scheduler`
- Workspace path for Railway MCP: `/Users/jaochai/Code/bot-fb/.claude/worktrees/perf-audit-2026-05-15`

## Production URLs

- Frontend: `https://www.botjao.com`
- Frontend (Railway): `https://frontend-production-9fe8.up.railway.app`

Use `https://www.botjao.com` for Lighthouse runs (canonical).

## Output Path

All unit reports go to:
```
docs/superpowers/audits/2026-05-15-perf-audit/1-detail/{N}-{name}.md
```

## Time Window

All "7 days" queries: `NOW() - INTERVAL '7 days'` or equivalent for Sentry (`statsPeriod=7d`).

## Backend / Frontend Working Dirs

When running shell commands:
- Backend commands: `cd backend && <cmd>`
- Frontend commands: `cd frontend && <cmd>`
- Worktree root: `/Users/jaochai/Code/bot-fb/.claude/worktrees/perf-audit-2026-05-15`
