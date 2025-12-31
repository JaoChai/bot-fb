# CLAUDE.md - BotFacebook

## Quick Codes
`ccc` context+compact | `nnn` plan | `gogogo` execute | `lll` status | `rrr` retrospective

## Critical Rules
- **NEVER** force flags, merge without approval, `rm -rf`
- **NEVER** guess root cause - get ACTUAL error first
- **STOP** after 2 failed fix attempts
- **ALWAYS** `npm run build` before commit

## Stack
Laravel 12 + React 19 + PostgreSQL (Neon) + Railway + Reverb

## URLs
- Frontend: `https://www.botjao.com`
- Backend: `https://api.botjao.com`

## Gotchas
| Issue | Fix |
|-------|-----|
| `config('x','')` returns null | `config('x') ?? ''` |
| Echo auth stale | Dynamic authorizer |
| PDO boolean fails | Disable emulate_prepares |

---

## MANDATORY: Auto-Use MCP Tools

**YOU MUST use the appropriate MCP tool IMMEDIATELY when detecting these patterns:**

### Error/System Health Keywords
**Trigger:** `error`, `500`, `502`, `503`, `ล่ม`, `พัง`, `crash`, `down`, `ไม่ทำงาน`, `timeout`, `failed`, `bug`, `ปัญหา`

**Required Action:**
```
1. FIRST: mcp__botfacebook__diagnose({ action: "all" })
2. THEN: Analyze results and form hypothesis
3. FINALLY: Use mcp__botfacebook__fix() if needed
```

### Bot/Flow Management Keywords
**Trigger:** `bot`, `flow`, `บอท`, `โฟลว์`, `สร้าง bot`, `แก้ไข bot`, `ลบ bot`

**Required Action:**
```
mcp__botfacebook__bot_manage({ action: "list_bots" | "get_bot" | ... })
```

### Evaluation Keywords
**Trigger:** `ประเมิน`, `evaluate`, `test bot`, `ทดสอบ`, `quality`, `คุณภาพ`

**Required Action:**
```
mcp__botfacebook__evaluate({ action: "list" | "create" | ... })
```

### Deploy/Railway Keywords
**Trigger:** `deploy`, `railway`, `production`, `push`, `release`

**Required Action:**
```
1. mcp__botfacebook__execute({ action: "railway_status" })
2. Check status before any deployment action
```

### Knowledge Base Keywords
**Trigger:** `KB`, `knowledge`, `embedding`, `RAG`, `search`, `ค้นหา`, `document`

**Required Action:**
```
mcp__botfacebook__bot_manage({ action: "search_kb" | "list_documents" | ... })
```

---

## MCP Tools Reference

| Tool | Purpose | Common Actions |
|------|---------|----------------|
| `diagnose` | System health check | all, backend, frontend, database, queue, logs, railway |
| `fix` | Apply fixes | clear_cache, clear_all, optimize, migrate, restart_queue |
| `bot_manage` | Bot/Flow/KB/Conv | list_bots, get_bot, test_bot, list_flows, search_kb |
| `evaluate` | Bot evaluation | list, create, show, progress, report |
| `execute` | Other operations | railway_status, railway_logs, run_e2e, tinker |

---

## Debug Protocol

```
Error Detected
     ↓
[1] diagnose({ action: "all" }) ← GET ACTUAL ERROR
     ↓
[2] Form hypothesis based on REAL data
     ↓
[3] Propose fix to user
     ↓
[4] Apply ONE fix at a time
     ↓
[5] Verify with diagnose again
     ↓
If fail 2x → STOP and re-analyze
```

---

## See Also
- [COMMANDS.md](.claude/COMMANDS.md)
- [UI-RULES.md](.claude/UI-RULES.md)
