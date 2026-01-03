# CLAUDE.md - Autonomous Mode

## Core Rules (ห้ามละเมิด)

| # | Rule | Action |
|---|------|--------|
| 1 | ห้ามเดา | ดูจริงก่อนแก้ (เปิดไฟล์/curl/logs) |
| 2 | ห้ามแก้มัว | 2 รอบไม่หาย → หยุด ถาม User |
| 3 | ใช้ Memory | `mem-search` ก่อนทำทุกครั้ง |
| 4 | เรียนรู้ | ผิด → บันทึก LESSONS.md ทันที |

## Work Loop (Autonomous)

```
RECEIVE → RESEARCH → SPEC → PLAN → TASKS → ISSUE → EXECUTE → VERIFY → COMMIT → LEARN
```

| Phase | Action | Output |
|-------|--------|--------|
| 1. Receive | รับงาน ถามให้ชัด | เข้าใจ requirement |
| 2. Research | mem-search + ดูจริง | ข้อมูลครบ |
| 3. Spec | `/speckit.specify` | `.specify/specs/x.md` |
| 4. Plan | `/speckit.plan` | `.specify/plans/x.md` |
| 5. Tasks | `/speckit.tasks` | `.specify/tasks/x.md` |
| 6. Issue | `gh issue create` | GitHub Issue #XX |
| 7. Execute | `/speckit.implement` | Code changes |
| 8. Verify | Build + Test | Pass/Fail |
| 9. Commit | Push + Close Issue | Done |
| 10. Learn | บันทึก Memory | จำไว้ |

## Safety Stops (ต้องถาม User)

- **Destructive**: delete, migrate:fresh, drop, force push
- **Cost**: paid API, infrastructure change
- **Security**: auth, API keys, permissions
- **Ambiguous**: ไม่ชัดเจน, หลายทางเลือก
- **Failed 2x**: แก้ 2 รอบไม่หาย

## Session Checklist

**Start:**
```
□ CLAUDE.md □ LESSONS.md □ mem-search □ gh issue list □ .specify/specs/
```

**End:**
```
□ งานค้าง → Issue □ บทเรียน → LESSONS.md □ สรุป → Memory
```

## Stack & URLs

| Item | Value |
|------|-------|
| Stack | Laravel 12 + React 19 + Neon + Railway |
| Frontend | https://www.botjao.com |
| Backend | https://api.botjao.com |

## MCP Tools

| Tool | Use |
|------|-----|
| `diagnose` | logs, railway, all |
| `fix` | clear_cache, migrate |
| `bot_manage` | bot/flow/kb ops |
| `execute` | tinker, railway_logs |

## Gotchas

| Problem | Fix |
|---------|-----|
| `config('x','')` null | `config('x') ?? ''` |
| API wrapped `{data:X}` | `response.data` |
| serve.json fail | Express server |

## Spec-Kit Commands

| Command | Purpose |
|---------|---------|
| `/speckit.specify` | What/Why |
| `/speckit.plan` | How |
| `/speckit.tasks` | Task breakdown |
| `/speckit.implement` | Execute |

---
*Autonomous Mode - No gogogo needed*
