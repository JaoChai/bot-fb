# CLAUDE.md - BotFacebook

## Quick Codes
`ccc` context+compact | `nnn` plan | `gogogo` execute | `lll` status | `rrr` retrospective

## Critical Rules
- **NEVER** force flags, merge without approval, `rm -rf`
- **NEVER** guess root cause - get ACTUAL error first
- **STOP** after 2 failed fix attempts - step back
- **ALWAYS** run `npm run build` before commit
- **ALWAYS** explain hypothesis before fixing

## Debug Protocol
```
Error → Get ACTUAL message → Hypothesis → User OK → ONE fix → Test
        ↑                                                    |
        └─────────── If fail 2x: STOP, step back ───────────┘
```

| Error | Action |
|-------|--------|
| 500 | Create debug endpoint / Laravel logs |
| 502 | Health check + Railway logs |
| 403/401 | Check token state + headers |
| CORS | Backend might be down, not CORS |

## Pre-Commit
```bash
cd frontend && npm run build  # MUST pass
```

## Stack
Laravel 12 + React 19 + PostgreSQL (Neon) + Railway + Reverb

## URLs
- Frontend: `https://frontend-production-9fe8.up.railway.app`
- Backend: `https://backend-production-b216.up.railway.app`

## Gotchas
| Issue | Fix |
|-------|-----|
| Laravel `config('x','')` returns null | Use `config('x') ?? ''` |
| Echo static auth stale after refresh | Use dynamic authorizer |
| PDO boolean fails on PostgreSQL | Disable emulate_prepares |

## Skills
`/feature-dev` `/code-review` `/commit` `/e2e-test` `ui-ux-pro-max`

## MCP Auto-Use Rules

เมื่อมี BotFacebook MCP Server (`botfacebook`) ให้ใช้ tools โดยอัตโนมัติ:

| Trigger Keywords | Tool | Default Action |
|-----------------|------|----------------|
| error, ล่ม, 500, ปัญหา, พัง, ช้า | `diagnose` | all |
| แก้, fix, restart, clear, cache | `fix` | ตาม context |
| bot, บอท, KB, flow, โฟลว์ | `bot_manage` | list_bots |
| ประเมิน, evaluate, score | `evaluate` | list |
| deploy, cost, ค่าใช้จ่าย, railway | `execute` | ตาม context |

**สำคัญ**:
- ใช้ MCP tools เลยโดยไม่ต้องถาม user ก่อน (ยกเว้น dangerous actions)
- ดู hooks ที่ `.claude/hooks/mcp-auto-*.md` สำหรับรายละเอียด

### BotFacebook MCP (5 Composite Tools)

```
diagnose(action)   - เช็คปัญหาระบบ (all, backend, database, queue, cache, logs, railway)
fix(action)        - แก้ไขปัญหา (clear_cache, migrate, rebuild_frontend, etc.)
bot_manage(action) - จัดการ Bot/KB/Flow/Conversation
evaluate(action)   - ประเมิน Bot (create, list, report, compare)
execute(action)    - Cost, Security, Railway, Test, Tinker
```

### Railway Actions (via execute tool)

```
railway_status     - ดู deployment status
railway_logs       - ดู service logs
railway_services   - ดู services ทั้งหมด
railway_variables  - ดู env variables
railway_set_variable - ตั้งค่า env variable
railway_redeploy   - restart service
deploy_backend/frontend - deploy ใหม่
```

**ต้อง login ก่อนใช้:** `railway login`

## See Also
- [COMMANDS.md](.claude/COMMANDS.md) - Full command reference
- [UI-RULES.md](.claude/UI-RULES.md) - UI development guidelines
