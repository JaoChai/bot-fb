# CLAUDE.md - Autonomous Mode

## Core Rules

| # | Rule | Action |
|---|------|--------|
| 1 | ห้ามเดา | ดูจริงก่อนแก้ (เปิดไฟล์/curl/logs) |
| 2 | ห้ามแก้มัว | 2 รอบไม่หาย → หยุด ถาม User |
| 3 | ใช้ Memory | `mem-search` ก่อนทำทุกครั้ง |
| 4 | เรียนรู้ | ผิด → บันทึก LESSONS.md ทันที |

## Work Loop

```
RECEIVE → RESEARCH → SPEC → PLAN → TASKS → ISSUE → EXECUTE → VERIFY → COMMIT → LEARN
```

## Safety Stops (ต้องถาม User)

Destructive | Cost | Security | Ambiguous | Failed 2x

## Tool Selection Guide

### เมื่อไหร่ใช้อะไร

| Situation | Tool |
|-----------|------|
| **System health/logs** | `diagnose` (MCP) |
| **Clear cache/migrate** | `fix` (MCP) |
| **Bot/Flow/KB ops** | `bot_manage` (MCP) |
| **Railway deploy/logs** | `execute` (MCP) |
| **Database query** | `mcp__neon__run_sql` |
| **Error tracking** | `mcp__sentry__*` |
| **UI testing** | `mcp__playwright__*` |
| **ค้นหา codebase** | Task → `Explore` agent |
| **วางแผน implementation** | Task → `Plan` agent |
| **Review code** | Task → `code-reviewer` agent |
| **Debug Laravel 500** | Skill → `laravel-debugging` |
| **E2E testing** | Skill → `e2e-test` |
| **Design UI** | Skill → `ui-ux-pro-max` |
| **Debug LINE** | Skill → `line-expert` |
| **Check migration safety** | Skill → `migration-validator` |
| **Feature spec** | `/speckit.specify` |
| **Technical plan** | `/speckit.plan` |
| **Task breakdown** | `/speckit.tasks` |
| **Execute plan** | `/speckit.implement` |

## Quick Reference

| Item | Value |
|------|-------|
| Stack | Laravel 12 + React 19 + Neon + Railway |
| Frontend | https://www.botjao.com |
| Backend | https://api.botjao.com |

## Gotchas

| Problem | Fix |
|---------|-----|
| `config('x','')` null | `config('x') ?? ''` |
| API wrapped `{data:X}` | `response.data` |
| serve.json fail | Express server |

---
*Autonomous Mode - เลือก tool ถูก ทำได้เอง*
