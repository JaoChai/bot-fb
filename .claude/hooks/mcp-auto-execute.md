---
event: UserPromptSubmit
condition: |
  prompt matches "(?i)(deploy|cost|ค่าใช้จ่าย|token|api key|security|webhook|railway|log|tinker|test|e2e|unit)"
---
# MCP Auto-Execute Hook

พบ keyword เกี่ยวกับ Execute actions ในข้อความของ user

## Action Required
วิเคราะห์ context และใช้ MCP tool `execute` จาก botfacebook server:

## Cost/Analytics Actions
```javascript
// สรุปค่าใช้จ่าย
execute({ action: "cost_summary", from_date: "2024-01-01", to_date: "2024-12-31" })

// ค่าใช้จ่ายแยกตาม bot
execute({ action: "cost_by_bot", from_date: "2024-01-01", to_date: "2024-12-31" })

// ค่าใช้จ่ายแยกตาม model
execute({ action: "cost_by_model", from_date: "2024-01-01", to_date: "2024-12-31" })
```

## Security Actions
- เช็ค API keys: `execute({ action: "check_api_keys" })` (local mode only)
- rotate webhook: `execute({ action: "rotate_webhook", bot_id: <id> })`
- list tokens: `execute({ action: "list_tokens" })`
- revoke token: `execute({ action: "revoke_token", token_id: <id>, confirm: true })`

## Deploy Actions (ต้อง confirm)
```javascript
// Deploy backend
execute({ action: "deploy_backend", confirm: true })

// Deploy frontend
execute({ action: "deploy_frontend", confirm: true })

// ดู Railway logs
execute({ action: "railway_logs", service: "backend", lines: 100 })
execute({ action: "railway_logs", service: "frontend", lines: 100 })

// ดู Railway status
execute({ action: "railway_status" })
```

## Test Actions
- E2E tests: `execute({ action: "run_e2e" })` (local only)
- Unit tests: `execute({ action: "run_unit" })` (local only)
- Test webhook: `execute({ action: "test_webhook", bot_id: <id> })`

## Tinker (local mode only)
```javascript
execute({
  action: "tinker",
  code: "User::count()"
})
```

## Keyword Mapping
| Keyword | Suggested Action |
|---------|-----------------|
| cost, ค่าใช้จ่าย | cost_summary |
| token usage | cost_by_model |
| deploy backend | deploy_backend |
| deploy frontend | deploy_frontend |
| railway, log | railway_logs |
| api key, security | check_api_keys |
| webhook | rotate_webhook หรือ test_webhook |
| tinker, php | tinker |
| test, e2e | run_e2e |
| unit test | run_unit |

## Safety Notes
- Deploy actions ต้องมี `confirm: true`
- Tinker เฉพาะ local mode
- ห้ามใช้ tinker สำหรับ DROP/DELETE/TRUNCATE statements
